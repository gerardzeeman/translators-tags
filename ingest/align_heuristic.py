"""
align_heuristic.py
Phase 2 alignment: heuristic word-order and proper-noun matching for verses
that have no pivot coverage, or to supplement pivot results.

Strategies (in order of confidence):
  1. Proper noun matching: transliteration ≈ Dutch word (names like Abraham,
     David, Jerusalem appear nearly identically in both languages).
  2. Positional alignment: map source word at position P/N_src to Dutch word
     at position P/N_dst (proportional index). Low confidence.
"""
import unicodedata
from difflib import SequenceMatcher
from tqdm import tqdm
from db.connection import get_connection
from db.loaders import insert_word_link, insert_link_confidence

TRANSLATION_ID        = 1
PROPER_NOUN_MIN_SCORE = 0.72   # high bar – proper nouns should match closely
POSITIONAL_SCORE      = 0.30   # low confidence for positional fallback
PROPER_NOUN_MORPH_HE  = {"NP", "Np"}   # OpenScriptures proper-noun morph codes


def normalise(text: str) -> str:
    if not text:
        return ""
    nfd = unicodedata.normalize("NFD", text.lower())
    return "".join(c for c in nfd if unicodedata.category(c) != "Mn")


def similarity(a: str, b: str) -> float:
    if not a or not b:
        return 0.0
    return SequenceMatcher(None, a, b).ratio()


# ─── Data loading ─────────────────────────────────────────────────────────────

def load_unlinked_hebrew() -> list[dict]:
    """Load Hebrew words that have no word_links entry yet."""
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT hw.id, hw.book_id, hw.chapter, hw.verse,
                       hw.word_position, hw.transliteration, hw.morph_code,
                       (SELECT COUNT(*) FROM word_links wl
                        WHERE wl.hebrew_word_id = hw.id) AS link_count
                FROM hebrew_words hw
                WHERE NOT EXISTS (
                    SELECT 1 FROM word_links WHERE hebrew_word_id = hw.id
                )
                ORDER BY hw.book_id, hw.chapter, hw.verse, hw.word_position
                """
            )
            cols = [d[0] for d in cur.description]
            return [dict(zip(cols, row)) for row in cur.fetchall()]


def load_unlinked_greek() -> list[dict]:
    """Load Greek words that have no word_links entry yet."""
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT gw.id, gw.book_id, gw.chapter, gw.verse,
                       gw.word_position, gw.parse_code
                FROM greek_words gw
                WHERE NOT EXISTS (
                    SELECT 1 FROM word_links WHERE greek_word_id = gw.id
                )
                ORDER BY gw.book_id, gw.chapter, gw.verse, gw.word_position
                """
            )
            cols = [d[0] for d in cur.description]
            return [dict(zip(cols, row)) for row in cur.fetchall()]


def load_verse_dutch_words(book_id: int, chapter: int, verse: int) -> list[dict]:
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT tw.id, tw.word_position, tw.word_text, tw.word_normalised
                FROM translation_words tw
                JOIN translation_verses tv ON tw.verse_id = tv.id
                WHERE tv.translation_id = %s
                  AND tv.book_id = %s AND tv.chapter = %s AND tv.verse = %s
                ORDER BY tw.word_position
                """,
                (TRANSLATION_ID, book_id, chapter, verse),
            )
            cols = [d[0] for d in cur.description]
            return [dict(zip(cols, row)) for row in cur.fetchall()]


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
        # Greek proper nouns often appear in a recognisable form
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
    # Robinson: N-PRI (proper noun indeclinable), N-NSM etc. with HEB or ARAM prefix
    return parse.startswith(("N-PRI", "HEB", "ARAM", "Aram"))


def align_heuristic() -> None:
    print("  Loading unlinked source words …")
    hebrew_unlinked = load_unlinked_hebrew()
    greek_unlinked  = load_unlinked_greek()
    source_counts   = load_verse_source_counts()

    print(f"  Unlinked Hebrew words: {len(hebrew_unlinked):,}")
    print(f"  Unlinked Greek words:  {len(greek_unlinked):,}")

    # Cache Dutch verses we've already fetched this run
    dutch_cache: dict[tuple, list[dict]] = {}

    def get_dutch(book_id, chapter, verse):
        key = (book_id, chapter, verse)
        if key not in dutch_cache:
            dutch_cache[key] = load_verse_dutch_words(book_id, chapter, verse)
        return dutch_cache[key]

    inserted_proper    = 0
    inserted_positional = 0

    # ── Hebrew ────────────────────────────────────────────────────────────────
    for word in tqdm(hebrew_unlinked, desc="  Heuristic HE", unit=" words"):
        book_id = word["book_id"]
        chapter = word["chapter"]
        verse   = word["verse"]
        dutch   = get_dutch(book_id, chapter, verse)

        tw_id, score = None, 0.0

        if is_proper_noun_he(word):
            tw_id, score = try_proper_noun_match(word, "HE", dutch)

        if tw_id is None:
            total = source_counts.get(("HE", book_id, chapter, verse), 1)
            tw_id, score = try_positional_match(word, dutch, total)

        if tw_id is not None:
            try:
                link_id = insert_word_link("HE", word["id"], tw_id)
                method  = "heuristic"
                insert_link_confidence(link_id, method, round(score, 3),
                                       notes="proper_noun" if score >= PROPER_NOUN_MIN_SCORE
                                             else "positional")
                if score >= PROPER_NOUN_MIN_SCORE:
                    inserted_proper += 1
                else:
                    inserted_positional += 1
            except Exception:
                pass

    # ── Greek ─────────────────────────────────────────────────────────────────
    for word in tqdm(greek_unlinked, desc="  Heuristic GR", unit=" words"):
        book_id = word["book_id"]
        chapter = word["chapter"]
        verse   = word["verse"]
        dutch   = get_dutch(book_id, chapter, verse)

        tw_id, score = None, 0.0

        if is_proper_noun_gk(word):
            tw_id, score = try_proper_noun_match(word, "GR", dutch)

        if tw_id is None:
            total = source_counts.get(("GR", book_id, chapter, verse), 1)
            tw_id, score = try_positional_match(word, dutch, total)

        if tw_id is not None:
            try:
                link_id = insert_word_link("GR", word["id"], tw_id)
                insert_link_confidence(link_id, "heuristic", round(score, 3),
                                       notes="proper_noun" if score >= PROPER_NOUN_MIN_SCORE
                                             else "positional")
                if score >= PROPER_NOUN_MIN_SCORE:
                    inserted_proper += 1
                else:
                    inserted_positional += 1
            except Exception:
                pass

    print(f"  ✓ Proper-noun links: {inserted_proper:,}")
    print(f"  ✓ Positional links:  {inserted_positional:,}")


if __name__ == "__main__":
    align_heuristic()
