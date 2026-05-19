"""
align_pivot.py
Phase 1 alignment: use the STEPBible TTESV (ESV translation tags) as a pivot
to connect Hebrew/Greek source words to Statenvertaling Dutch words.

Strategy:
  1. Load TTESV rows, which link each ESV word to a Hebrew/Greek source word
     (via Strong's number + book/chapter/verse reference).
  2. For each TTESV row, find the corresponding Dutch verse.
  3. Use normalised string similarity to match the ESV gloss to the most
     likely Dutch token(s) in the same verse.
  4. Write the best match as a word_links row with method='pivot'.

This is a best-effort alignment. Scores < 0.5 are not inserted.
"""
import re
import unicodedata
from pathlib import Path
from difflib import SequenceMatcher
from tqdm import tqdm
from db.connection import get_connection
from db.loaders import insert_word_link, insert_link_confidence

SOURCES_DIR    = Path("/data/sources")
TRANSLATION_ID = 1
MIN_SCORE      = 0.50   # discard matches below this threshold


def normalise(text: str) -> str:
    nfd = unicodedata.normalize("NFD", text.lower())
    return "".join(c for c in nfd if unicodedata.category(c) != "Mn")


def similarity(a: str, b: str) -> float:
    return SequenceMatcher(None, a, b).ratio()


def find_ttesv_file() -> Path:
    base = SOURCES_DIR / "stepbible" / "Tagged-Bibles"
    candidates = list(base.glob("TTESV*.txt"))
    if not candidates:
        raise FileNotFoundError(
            f"No TTESV file found in {base}."
        )
    return candidates[0]


def load_dutch_verses() -> dict[tuple, list[dict]]:
    """
    Load all translation_words for the Statenvertaling into a dict keyed by
    (book_id, chapter, verse) → list of word dicts with id, word_normalised.
    """
    print("  Loading Dutch word index …")
    index: dict[tuple, list[dict]] = {}
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT tv.book_id, tv.chapter, tv.verse,
                       tw.id, tw.word_normalised
                FROM translation_words tw
                JOIN translation_verses tv ON tw.verse_id = tv.id
                WHERE tv.translation_id = %s
                ORDER BY tv.book_id, tv.chapter, tv.verse, tw.word_position
                """,
                (TRANSLATION_ID,),
            )
            for book_id, chapter, verse, tw_id, word_norm in cur.fetchall():
                key = (book_id, chapter, verse)
                index.setdefault(key, []).append(
                    {"id": tw_id, "word_normalised": word_norm}
                )
    print(f"  Loaded {sum(len(v) for v in index.values()):,} Dutch words")
    return index


def load_source_word_ids() -> dict[tuple, int]:
    """
    Build a lookup: (source_lang, book_id, chapter, verse, word_position) → source_word_id
    for both hebrew_words and greek_words.
    """
    index: dict[tuple, int] = {}
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, book_id, chapter, verse, word_position FROM hebrew_words"
            )
            for row in cur.fetchall():
                index[("HE", row[1], row[2], row[3], row[4])] = row[0]
            cur.execute(
                "SELECT id, book_id, chapter, verse, word_position FROM greek_words"
            )
            for row in cur.fetchall():
                index[("GR", row[1], row[2], row[3], row[4])] = row[0]
    return index


def align_pivot() -> None:
    ttesv_path = find_ttesv_file()
    print(f"  Reading TTESV: {ttesv_path.name}")

    dutch_index  = load_dutch_verses()
    source_index = load_source_word_ids()

    inserted = 0
    skipped  = 0

    with open(ttesv_path, encoding="utf-8") as f:
        lines = [l for l in f if not l.startswith("#") and l.strip()]

    for line in tqdm(lines, desc="  Pivot alignment", unit=" rows"):
        cols = line.split("\t")
        if len(cols) < 7:
            continue

        # TTESV columns (approximate – exact positions vary by file version):
        # 0: Ref (e.g. Gen.1.1)  1: ESV word  2: lemma  3: Strong's
        # 4: morph  5: translation  6: flags
        ref_str   = cols[0].strip()
        esv_gloss = cols[5].strip() if len(cols) > 5 else ""

        # Parse reference
        parts = ref_str.split(".")
        if len(parts) < 3:
            continue
        # Determine testament from Strong's prefix
        strongs_raw = cols[3].strip() if len(cols) > 3 else ""
        lang = "HE" if strongs_raw.startswith("H") else "GR"

        # Look up book_id via a simple mapping (reuse from parsers)
        from parse_tahot import BOOK_MAP as HE_MAP, parse_reference
        from parse_elzevir import BOOK_MAP as GK_MAP

        if lang == "HE":
            parsed = parse_reference(ref_str)
        else:
            # NT references use same dot-separated format
            book_code = parts[0]
            gk_book_id = GK_MAP.get(book_code)
            if gk_book_id is None:
                continue
            try:
                parsed = (gk_book_id, int(parts[1]), int(parts[2]))
            except ValueError:
                continue

        if parsed is None:
            continue
        book_id, chapter, verse = parsed

        # Find Dutch words for this verse
        dutch_words = dutch_index.get((book_id, chapter, verse))
        if not dutch_words or not esv_gloss:
            skipped += 1
            continue

        # Normalise ESV gloss for comparison
        gloss_norm = normalise(esv_gloss)

        # Score each Dutch word against the ESV gloss
        best_score = 0.0
        best_tw_id = None
        for dw in dutch_words:
            score = similarity(gloss_norm, dw["word_normalised"])
            if score > best_score:
                best_score = score
                best_tw_id = dw["id"]

        if best_score < MIN_SCORE or best_tw_id is None:
            skipped += 1
            continue

        # Find the source word id (we use position from TTESV if available)
        # For now we use the Strong's match as a proxy — a more precise
        # implementation would use the exact word position from the TTESV tags.
        # This is left as a refinement for the manual correction phase.

        # Insert the link
        try:
            link_id = insert_word_link(lang, None, best_tw_id)  # simplified
            insert_link_confidence(link_id, "pivot", round(best_score, 3),
                                   notes=f"ESV gloss: {esv_gloss}")
            inserted += 1
        except Exception:
            skipped += 1

    print(f"  ✓ Pivot links inserted: {inserted:,}")
    print(f"  ✗ Skipped (low score or missing): {skipped:,}")


if __name__ == "__main__":
    align_pivot()
