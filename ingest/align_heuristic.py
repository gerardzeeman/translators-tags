"""
align_heuristic.py
Phase 2 alignment: heuristic word-order and proper-noun matching for verses
that have no pivot coverage, or to supplement pivot results.

On each run the script first removes all previously created heuristic links
(preserving manual and pivot links), then re-runs alignment with fresh knowledge
from manual annotations.

Strategies (in order of confidence):
  1. Manual-hint: when other occurrences of the same Strong's number have been
     manually linked, the dominant target form is used as a hint.
     – If the dominant annotation is "no Dutch translation" (manual_empty_links),
       the word is skipped entirely: no heuristic link is created.
  2. Proper noun matching: transliteration ≈ Dutch word (names like Abraham,
     David, Jerusalem appear nearly identically in both languages).
  3. Positional alignment: map source word at position P/N_src to Dutch word
     at position P/N_dst (proportional index). Low confidence.
"""
import argparse
import unicodedata
from difflib import SequenceMatcher
from tqdm import tqdm
from db.connection import get_connection
from db.loaders import insert_word_link, insert_link_confidence

PROPER_NOUN_MIN_SCORE = 0.72   # high bar – proper nouns should match closely
POSITIONAL_SCORE      = 0.30   # low confidence for positional fallback
PROPER_NOUN_MORPH_HE  = {"NP", "Np"}   # OpenScriptures proper-noun morph codes

# Manual-hint thresholds
MIN_MANUAL_COUNT      = 3      # need at least this many manual links for a Strong's
MIN_MANUAL_CONSENSUS  = 0.50   # dominant form must represent at least this fraction
MANUAL_HINT_FUZZY_MIN = 0.80   # minimum similarity score for fuzzy hint matching


def normalise(text: str) -> str:
    if not text:
        return ""
    nfd = unicodedata.normalize("NFD", text.lower())
    return "".join(c for c in nfd if unicodedata.category(c) != "Mn")


def similarity(a: str, b: str) -> float:
    if not a or not b:
        return 0.0
    return SequenceMatcher(None, a, b).ratio()


# ─── Book resolver ────────────────────────────────────────────────────────────

def resolve_translation(code: str) -> int:
    """Return translation_id for a translation code (e.g. 'SV' → 1).
    Raises ValueError if the code is not found."""
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id FROM translations WHERE code = %s",
                (code.upper(),),
            )
            row = cur.fetchone()
            if not row:
                raise ValueError(f"Translation '{code}' not found in the database.")
            return row[0]


def resolve_book(usfm_code: str) -> tuple[int, str]:
    """Return (book_id, testament) for a USFM code (e.g. 'GEN' → (1, 'OT')).
    Raises ValueError if the code is not found."""
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, testament FROM books WHERE usfm_code = %s",
                (usfm_code.upper(),),
            )
            row = cur.fetchone()
            if not row:
                raise ValueError(f"Book '{usfm_code}' not found in the database.")
            return row[0], row[1]


# ─── Cleanup ──────────────────────────────────────────────────────────────────

AUTOMATED_METHODS = ('manual_hint', 'proper_noun', 'positional')

def delete_heuristic_links(translation_id: int, book_id: int | None = None) -> int:
    """
    Remove automated word_links (manual_hint / proper_noun / positional) that
    have no 'manual' confidence row, scoped to a specific translation.
    Manual links are never touched.
    If book_id is given, only links belonging to words in that book are removed.
    Returns the number of rows deleted.
    """
    # Base condition: automated method, no manual row, and belongs to translation_id
    translation_filter = """
        AND EXISTS (
            SELECT 1
            FROM translation_words tw
            JOIN translation_verses tv ON tv.id = tw.verse_id
            WHERE tw.id = wl.translation_word_id
              AND tv.translation_id = %(translation_id)s
        )
    """

    with get_connection() as conn:
        with conn.cursor() as cur:
            if book_id is None:
                cur.execute(
                    f"""
                    DELETE FROM word_links wl
                    USING link_confidence lc
                    WHERE lc.link_id = wl.id
                      AND lc.method  IN ('manual_hint', 'proper_noun', 'positional')
                      AND NOT EXISTS (
                          SELECT 1 FROM link_confidence lc2
                          WHERE lc2.link_id = wl.id
                            AND lc2.method = 'manual'
                      )
                      {translation_filter}
                    """,
                    {"translation_id": translation_id},
                )
            else:
                cur.execute(
                    f"""
                    DELETE FROM word_links wl
                    USING link_confidence lc
                    WHERE lc.link_id = wl.id
                      AND lc.method  IN ('manual_hint', 'proper_noun', 'positional')
                      AND NOT EXISTS (
                          SELECT 1 FROM link_confidence lc2
                          WHERE lc2.link_id = wl.id
                            AND lc2.method = 'manual'
                      )
                      {translation_filter}
                      AND (
                          EXISTS (
                              SELECT 1 FROM hebrew_words hw
                              WHERE hw.id = wl.hebrew_word_id AND hw.book_id = %(book_id)s
                          )
                          OR EXISTS (
                              SELECT 1 FROM greek_words gw
                              WHERE gw.id = wl.greek_word_id AND gw.book_id = %(book_id)s
                          )
                      )
                    """,
                    {"translation_id": translation_id, "book_id": book_id},
                )
            return cur.rowcount


# ─── Data loading ─────────────────────────────────────────────────────────────

def load_manual_hints(translation_id: int) -> dict[str, dict[str, int]]:
    """
    Build {strongs: {word_normalised: count}} from manual links for the given translation.

    The special key '__empty__' counts manual "no Dutch translation" annotations
    stored in manual_empty_links. If this is the dominant key for a Strong's
    number, the heuristic script will skip those words entirely.
    """
    hints: dict[str, dict[str, int]] = {}

    def add(strongs: str | None, form: str, cnt: int) -> None:
        if strongs:
            bucket = hints.setdefault(strongs, {})
            bucket[form] = bucket.get(form, 0) + cnt

    with get_connection() as conn:
        with conn.cursor() as cur:

            # ── Manual word links: Hebrew ─────────────────────────────────────
            cur.execute(
                """
                SELECT hw.strongs, tw.word_normalised, COUNT(*) AS cnt
                FROM word_links wl
                JOIN link_confidence lc ON lc.link_id = wl.id AND lc.method = 'manual'
                JOIN translation_words tw ON tw.id = wl.translation_word_id
                JOIN translation_verses tv ON tv.id = tw.verse_id
                JOIN hebrew_words hw ON hw.id = wl.hebrew_word_id
                WHERE hw.strongs IS NOT NULL
                  AND tv.translation_id = %s
                GROUP BY hw.strongs, tw.word_normalised
                """,
                (translation_id,),
            )
            for strongs, word_norm, cnt in cur.fetchall():
                add(strongs, word_norm, int(cnt))

            # ── Manual word links: Greek ──────────────────────────────────────
            cur.execute(
                """
                SELECT gw.strongs, tw.word_normalised, COUNT(*) AS cnt
                FROM word_links wl
                JOIN link_confidence lc ON lc.link_id = wl.id AND lc.method = 'manual'
                JOIN translation_words tw ON tw.id = wl.translation_word_id
                JOIN translation_verses tv ON tv.id = tw.verse_id
                JOIN greek_words gw ON gw.id = wl.greek_word_id
                WHERE gw.strongs IS NOT NULL
                  AND tv.translation_id = %s
                GROUP BY gw.strongs, tw.word_normalised
                """,
                (translation_id,),
            )
            for strongs, word_norm, cnt in cur.fetchall():
                add(strongs, word_norm, int(cnt))

            # ── Manual empty links: Hebrew ────────────────────────────────────
            cur.execute(
                """
                SELECT hw.strongs, COUNT(*) AS cnt
                FROM manual_empty_links mel
                JOIN hebrew_words hw ON hw.id = mel.hebrew_word_id
                WHERE hw.strongs IS NOT NULL
                  AND mel.translation_id = %s
                GROUP BY hw.strongs
                """,
                (translation_id,),
            )
            for strongs, cnt in cur.fetchall():
                add(strongs, "__empty__", int(cnt))

            # ── Manual empty links: Greek ─────────────────────────────────────
            cur.execute(
                """
                SELECT gw.strongs, COUNT(*) AS cnt
                FROM manual_empty_links mel
                JOIN greek_words gw ON gw.id = mel.greek_word_id
                WHERE gw.strongs IS NOT NULL
                  AND mel.translation_id = %s
                GROUP BY gw.strongs
                """,
                (translation_id,),
            )
            for strongs, cnt in cur.fetchall():
                add(strongs, "__empty__", int(cnt))

    return hints


def load_unlinked_hebrew(translation_id: int, book_id: int | None = None) -> list[dict]:
    """Load Hebrew words that have no word_links entry (for this translation)
    and no manual_empty_links entry (for this translation).
    If book_id is given, only words from that book are returned."""
    with get_connection() as conn:
        with conn.cursor() as cur:
            sql = """
                SELECT hw.id, hw.book_id, hw.chapter, hw.verse,
                       hw.word_position, hw.transliteration, hw.morph_code, hw.strongs
                FROM hebrew_words hw
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM word_links wl
                    JOIN translation_words tw  ON tw.id = wl.translation_word_id
                    JOIN translation_verses tv ON tv.id = tw.verse_id
                    WHERE wl.hebrew_word_id = hw.id
                      AND tv.translation_id = %(translation_id)s
                )
                AND NOT EXISTS (
                    SELECT 1 FROM manual_empty_links
                    WHERE hebrew_word_id = hw.id
                      AND translation_id = %(translation_id)s
                )
            """
            params: dict = {"translation_id": translation_id}
            if book_id is not None:
                sql += " AND hw.book_id = %(book_id)s"
                params["book_id"] = book_id
            sql += " ORDER BY hw.book_id, hw.chapter, hw.verse, hw.word_position"
            cur.execute(sql, params)
            cols = [d[0] for d in cur.description]
            return [dict(zip(cols, row)) for row in cur.fetchall()]


def load_unlinked_greek(translation_id: int, book_id: int | None = None) -> list[dict]:
    """Load Greek words that have no word_links entry (for this translation)
    and no manual_empty_links entry (for this translation).
    If book_id is given, only words from that book are returned."""
    with get_connection() as conn:
        with conn.cursor() as cur:
            sql = """
                SELECT gw.id, gw.book_id, gw.chapter, gw.verse,
                       gw.word_position, gw.parse_code, gw.strongs
                FROM greek_words gw
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM word_links wl
                    JOIN translation_words tw  ON tw.id = wl.translation_word_id
                    JOIN translation_verses tv ON tv.id = tw.verse_id
                    WHERE wl.greek_word_id = gw.id
                      AND tv.translation_id = %(translation_id)s
                )
                AND NOT EXISTS (
                    SELECT 1 FROM manual_empty_links
                    WHERE greek_word_id = gw.id
                      AND translation_id = %(translation_id)s
                )
            """
            params: dict = {"translation_id": translation_id}
            if book_id is not None:
                sql += " AND gw.book_id = %(book_id)s"
                params["book_id"] = book_id
            sql += " ORDER BY gw.book_id, gw.chapter, gw.verse, gw.word_position"
            cur.execute(sql, params)
            cols = [d[0] for d in cur.description]
            return [dict(zip(cols, row)) for row in cur.fetchall()]


def load_verse_dutch_words(translation_id: int, book_id: int, chapter: int, verse: int,
                            cur=None) -> list[dict]:
    """Pass an open `cur` to reuse an existing connection — see
    insert_word_link() in db/loaders.py for why this matters when called
    once per verse inside a large loop."""
    sql = """
        SELECT tw.id, tw.word_position, tw.word_text, tw.word_normalised
        FROM translation_words tw
        JOIN translation_verses tv ON tw.verse_id = tv.id
        WHERE tv.translation_id = %s
          AND tv.book_id = %s AND tv.chapter = %s AND tv.verse = %s
        ORDER BY tw.word_position
        """
    params = (translation_id, book_id, chapter, verse)
    if cur is not None:
        cur.execute(sql, params)
        cols = [d[0] for d in cur.description]
        return [dict(zip(cols, row)) for row in cur.fetchall()]
    with get_connection() as conn:
        with conn.cursor() as owned_cur:
            owned_cur.execute(sql, params)
            cols = [d[0] for d in owned_cur.description]
            return [dict(zip(cols, row)) for row in owned_cur.fetchall()]


def load_verse_source_counts() -> dict[tuple, int]:
    """
    For proportional positional alignment we need to know how many source words
    a verse has total. Returns {(lang, book_id, ch, v): count}.
    """
    counts: dict[tuple, int] = {}
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT book_id, chapter, verse, COUNT(*) AS cnt
                FROM hebrew_words GROUP BY book_id, chapter, verse
                """
            )
            for book_id, ch, v, cnt in cur.fetchall():
                counts[("HE", book_id, ch, v)] = cnt
            cur.execute(
                """
                SELECT book_id, chapter, verse, COUNT(*) AS cnt
                FROM greek_words GROUP BY book_id, chapter, verse
                """
            )
            for book_id, ch, v, cnt in cur.fetchall():
                counts[("GR", book_id, ch, v)] = cnt
    return counts


# ─── Matching strategies ──────────────────────────────────────────────────────

def try_manual_hint_match(
    word: dict,
    dutch_words: list[dict],
    hints: dict[str, dict[str, int]],
    used_tw_ids: set[int] | None = None,
) -> tuple[int | None, float, bool]:
    """
    Look up the manual-link frequency table for this word's Strong's number.

    used_tw_ids: Dutch word IDs already assigned to earlier source words in this
                 verse. Unused candidates are preferred so that repeated words
                 (e.g. three και → three "en") are spread across occurrences in
                 document order rather than all mapped to the first match.

    Returns (tw_id, score, should_skip):
      tw_id:       matched translation_word_id, or None if no match found
      score:       consensus ratio (fraction of manual links agreeing on this form)
      should_skip: True when the dominant manual signal is "no Dutch translation",
                   meaning the caller should create NO link for this word at all
    """
    if used_tw_ids is None:
        used_tw_ids = set()

    strongs = word.get("strongs")
    if not strongs:
        return None, 0.0, False

    counts = hints.get(strongs)
    if not counts:
        return None, 0.0, False

    total = sum(counts.values())
    if total < MIN_MANUAL_COUNT:
        return None, 0.0, False

    dominant_form = max(counts, key=counts.__getitem__)
    consensus     = counts[dominant_form] / total

    if consensus < MIN_MANUAL_CONSENSUS:
        return None, 0.0, False

    # Dominant signal: this Strong's is intentionally untranslated
    if dominant_form == "__empty__":
        return None, consensus, True

    if not dutch_words:
        return None, 0.0, False

    # Exact normalised match — prefer an unused occurrence, fall back to any
    exact = [dw for dw in dutch_words if dw["word_normalised"] == dominant_form]
    if exact:
        unused_exact = [dw for dw in exact if dw["id"] not in used_tw_ids]
        best = unused_exact[0] if unused_exact else exact[0]
        return best["id"], consensus, False

    # Fuzzy fallback — prefer unused candidates
    available = [dw for dw in dutch_words if dw["id"] not in used_tw_ids] or dutch_words
    best_score, best_id = 0.0, None
    for dw in available:
        sc = similarity(dominant_form, dw["word_normalised"])
        if sc > best_score:
            best_score, best_id = sc, dw["id"]

    if best_score >= MANUAL_HINT_FUZZY_MIN:
        return best_id, consensus * best_score, False

    return None, 0.0, False


def try_proper_noun_match(word: dict, lang: str,
                          dutch_words: list[dict]) -> tuple[int | None, float]:
    """
    For Hebrew: compare transliteration against Dutch word_normalised.
    For Greek:  compare word_text (already Latin-ish for proper nouns) against Dutch.
    Returns (translation_word_id, score) or (None, 0.0).
    """
    if not dutch_words:
        return None, 0.0

    if lang == "HE":
        source_form = normalise(word.get("transliteration") or "")
    else:
        source_form = normalise(word.get("word_text") or "")

    if not source_form or len(source_form) < 3:
        return None, 0.0

    best_score = 0.0
    best_id    = None
    for dw in dutch_words:
        sc = similarity(source_form, dw["word_normalised"])
        if sc > best_score:
            best_score = sc
            best_id    = dw["id"]

    if best_score >= PROPER_NOUN_MIN_SCORE:
        return best_id, best_score
    return None, 0.0


def try_positional_match(word: dict, dutch_words: list[dict],
                         total_source: int) -> tuple[int | None, float]:
    """
    Map source word at position P (1-based, out of total_source) to the Dutch
    word at the proportionally equivalent position.
    """
    if not dutch_words or total_source == 0:
        return None, 0.0

    src_pos = word["word_position"]  # 1-based
    ratio   = (src_pos - 1) / max(total_source - 1, 1)
    dst_idx = round(ratio * (len(dutch_words) - 1))
    dst_idx = max(0, min(dst_idx, len(dutch_words) - 1))
    return dutch_words[dst_idx]["id"], POSITIONAL_SCORE


# ─── Main alignment loop ──────────────────────────────────────────────────────

def is_proper_noun_he(word: dict) -> bool:
    morph = word.get("morph_code") or ""
    return any(code in morph for code in PROPER_NOUN_MORPH_HE)


def is_proper_noun_gk(word: dict) -> bool:
    parse = word.get("parse_code") or ""
    return parse.startswith(("N-PRI", "HEB", "ARAM", "Aram"))


def _align_word(word: dict, lang: str, dutch: list[dict],
                source_counts: dict, manual_hints: dict,
                used_tw_ids: set[int] | None = None) -> tuple[int | None, float, str]:
    """
    Apply all three strategies in order and return (tw_id, score, notes).
    tw_id is None if all strategies failed.
    used_tw_ids: Dutch word IDs already assigned in this verse (for de-duplication).
    """
    # ── Strategy 1: manual hints based on other occurrences of this Strong's ──
    hint_id, hint_score, skip = try_manual_hint_match(word, dutch, manual_hints, used_tw_ids)
    if skip:
        return None, hint_score, "__skip__"   # signal: no link wanted
    if hint_id is not None:
        return hint_id, hint_score, f"manual_hint:{word.get('strongs', '')}"

    # ── Strategy 2: proper noun transliteration matching ─────────────────────
    is_pn = is_proper_noun_he(word) if lang == "HE" else is_proper_noun_gk(word)
    if is_pn:
        tw_id, score = try_proper_noun_match(word, lang, dutch)
        if tw_id is not None:
            return tw_id, score, "proper_noun"

    # ── Strategy 3: proportional positional fallback ──────────────────────────
    key   = (lang, word["book_id"], word["chapter"], word["verse"])
    total = source_counts.get(key, 1)
    tw_id, score = try_positional_match(word, dutch, total)
    return tw_id, score, "positional"


def align_heuristic(translation_id: int, book_id: int | None = None,
                    testament: str | None = None, book_label: str = "all books") -> None:
    # ── Step 1: remove stale heuristic links ──────────────────────────────────
    scope = f"book {book_label}" if book_id else "all books"
    print(f"  Removing previous heuristic links ({scope}) …")
    deleted = delete_heuristic_links(translation_id, book_id)
    print(f"  Deleted {deleted:,} heuristic-only links")

    # ── Step 2: build manual-hint index ───────────────────────────────────────
    print("  Loading manual hints …")
    manual_hints = load_manual_hints(translation_id)
    strong_count = len(manual_hints)
    hint_count   = sum(sum(v.values()) for v in manual_hints.values())
    print(f"  Manual hint index: {strong_count:,} Strong's numbers, "
          f"{hint_count:,} manual annotations")

    # ── Step 3: load words and verse data ─────────────────────────────────────
    do_hebrew = testament in (None, "OT")
    do_greek  = testament in (None, "NT")

    print(f"  Loading unlinked source words ({scope}) …")
    hebrew_unlinked = load_unlinked_hebrew(translation_id, book_id) if do_hebrew else []
    greek_unlinked  = load_unlinked_greek(translation_id, book_id)  if do_greek  else []
    source_counts   = load_verse_source_counts()

    print(f"  Unlinked Hebrew words: {len(hebrew_unlinked):,}")
    print(f"  Unlinked Greek words:  {len(greek_unlinked):,}")

    dutch_cache: dict[tuple, list[dict]] = {}

    def get_dutch(cur, book_id: int, chapter: int, verse: int) -> list[dict]:
        key = (book_id, chapter, verse)
        if key not in dutch_cache:
            dutch_cache[key] = load_verse_dutch_words(translation_id, book_id, chapter, verse, cur=cur)
        return dutch_cache[key]

    inserted_hint       = 0
    inserted_proper     = 0
    inserted_positional = 0
    skipped_by_hint     = 0
    failed              = 0

    COMMIT_EVERY = 500  # bound transaction size/duration; not correctness-critical

    def run_pass(words: list[dict], lang: str, desc: str) -> None:
        nonlocal inserted_hint, inserted_proper, inserted_positional, skipped_by_hint, failed
        if not words:
            return
        current_verse: tuple | None = None
        used_tw_ids:   set[int]     = set()

        # One shared connection for the whole pass instead of two brand-new
        # connections per word — the earlier per-word get_connection() calls
        # were pure network/auth round-trip overhead (~17 words/sec), not
        # computation. Each word's insert still runs inside its own SAVEPOINT
        # (conn.transaction()) so one bad row rolls back only that row instead
        # of aborting the whole in-progress pass.
        with get_connection() as conn:
            with conn.cursor() as cur:
                for i, word in enumerate(tqdm(words, desc=desc, unit=" words"), start=1):
                    verse_key = (word["book_id"], word["chapter"], word["verse"])
                    if verse_key != current_verse:
                        current_verse = verse_key
                        used_tw_ids   = set()

                    dutch = get_dutch(cur, word["book_id"], word["chapter"], word["verse"])
                    tw_id, score, notes = _align_word(word, lang, dutch, source_counts, manual_hints, used_tw_ids)

                    if notes == "__skip__":
                        skipped_by_hint += 1
                        continue

                    if tw_id is not None:
                        try:
                            with conn.transaction():
                                method = "manual_hint" if notes.startswith("manual_hint") else notes
                                link_id = insert_word_link(lang, word["id"], tw_id, cur=cur)
                                insert_link_confidence(link_id, method, round(score, 3), notes=notes, cur=cur)
                            used_tw_ids.add(tw_id)
                            if method == "manual_hint":
                                inserted_hint += 1
                            elif method == "proper_noun":
                                inserted_proper += 1
                            else:
                                inserted_positional += 1
                        except Exception:
                            failed += 1

                    if i % COMMIT_EVERY == 0:
                        conn.commit()

    # ── Hebrew ────────────────────────────────────────────────────────────────
    run_pass(hebrew_unlinked, "HE", "  Heuristic HE")

    # ── Greek ─────────────────────────────────────────────────────────────────
    run_pass(greek_unlinked, "GR", "  Heuristic GR")

    print(f"  ✓ Manual-hint links:      {inserted_hint:,}")
    print(f"  ✓ Proper-noun links:      {inserted_proper:,}")
    print(f"  ✓ Positional links:       {inserted_positional:,}")
    print(f"  ↷ Skipped (hint: no link): {skipped_by_hint:,}")
    if failed:
        print(f"  ✗ Failed inserts:         {failed:,}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Heuristic word-alignment for source → Dutch translation links."
    )
    parser.add_argument(
        "--book",
        metavar="USFM",
        default=None,
        help="Only process a single Bible book, identified by its USFM code "
             "(e.g. GEN, EXO, MAT, REV). Omit to process all books.",
    )
    parser.add_argument(
        "--translation",
        metavar="CODE",
        default="SV",
        help="Translation code to align against (e.g. SV, NBG51). Defaults to SV.",
    )
    args = parser.parse_args()

    # Resolve translation
    try:
        translation_id = resolve_translation(args.translation)
        print(f"Translation: {args.translation} (id={translation_id})")
    except ValueError as e:
        parser.error(str(e))

    book_id: int | None = None
    testament: str | None = None
    if args.book:
        try:
            book_id, testament = resolve_book(args.book)
            print(f"Selected book: {args.book}  ({testament})")
        except ValueError as e:
            parser.error(str(e))

    align_heuristic(
        translation_id=translation_id,
        book_id=book_id,
        testament=testament,
        book_label=args.book or "all",
    )
