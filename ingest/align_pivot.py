"""
align_pivot.py
Phase 1 alignment using STEPBible TTESV data.

TTESV format (lines starting with $):
  $Gen 1:1\t03=<07225>\t04=<00430>\t05=<01254>\t07=<08064>\t10=<00776>\t

Each tab token after the reference is:
  word_position=<strongs>         single word
  word_pos1+word_pos2=<strongs>   two ESV words share one source word
  word_pos=<strongs1>+<strongs2>  one ESV word covers two source words

Strategy:
  1. Parse TTESV to build: (book_id, chapter, verse, strongs) → [esv_word_positions]
  2. For each source word (Hebrew/Greek) that has a matching Strong's in TTESV,
     find the Dutch word at the proportionally equivalent position in the same verse.
  3. Insert word_links with method='pivot' and a confidence score based on
     how well the positional mapping worked.
"""
import re
from pathlib import Path
from tqdm import tqdm
from db.connection import get_connection
from db.loaders import insert_word_link, insert_link_confidence
from parse_tahot import BOOK_MAP as HE_BOOK_MAP
from parse_elzevir import BOOK_MAP as GK_BOOK_MAP

SOURCES_DIR    = Path("/data/sources")
TRANSLATION_ID = 1

# Maps "Gen" style book names in TTESV to our canonical book_ids
# TTESV uses 3-letter abbreviations matching the TAHOT style
TTESV_BOOK_MAP: dict[str, int] = {
    # OT — same as TAHOT
    "Gen": 1,  "Exo": 2,  "Lev": 3,  "Num": 4,  "Deu": 5,
    "Jos": 6,  "Jdg": 7,  "Rut": 8,  "1Sa": 9,  "2Sa": 10,
    "1Ki": 11, "2Ki": 12, "1Ch": 13, "2Ch": 14, "Ezr": 15,
    "Neh": 16, "Est": 17, "Job": 18, "Psa": 19, "Pro": 20,
    "Ecc": 21, "Sol": 22, "Sng": 22, "Isa": 23, "Jer": 24,
    "Lam": 25, "Eze": 26, "Ezk": 26, "Dan": 27, "Hos": 28,
    "Joe": 29, "Jol": 29, "Amo": 30, "Oba": 31, "Jon": 32,
    "Mic": 33, "Nah": 34, "Hab": 35, "Zep": 36, "Hag": 37,
    "Zec": 38, "Mal": 39,
    # NT
    "Mat": 40, "Mrk": 41, "Luk": 42, "Jhn": 43,
    "Act": 44, "Rom": 45, "1Co": 46, "2Co": 47,
    "Gal": 48, "Eph": 49, "Php": 50, "Col": 51,
    "1Th": 52, "2Th": 53, "1Ti": 54, "2Ti": 55,
    "Tit": 56, "Phm": 57, "Heb": 58, "Jas": 59,
    "1Pe": 60, "2Pe": 61, "1Jn": 62, "2Jn": 63,
    "3Jn": 64, "Jud": 65, "Rev": 66,
}

# Matches a data line: $Gen 1:1\t...
DATA_LINE_RE = re.compile(r"^\$(\w+)\s+(\d+):(\d+)\t(.+)")

# Matches a single token: 03=<07225> or 03+04=<07225> or 03=<07225>+<00430>
TOKEN_RE = re.compile(r"([\d+]+)=((?:<\d+>(?:\+<\d+>)*))")

# Extracts Strong's numbers from <07225> or <07225>+<00430>
STRONGS_RE = re.compile(r"<(\d+)>")


def parse_strongs(raw: str, testament: str) -> list[str]:
    """Extract normalised Strong's numbers from e.g. '<07225>+<00430>'."""
    nums = STRONGS_RE.findall(raw)
    result = []
    for n in nums:
        num = int(n)
        if num == 0:
            continue
        if testament == "OT":
            result.append(f"H{num:05d}")
        else:
            result.append(f"G{num:04d}")
    return result


def find_ttesv_file() -> Path:
    base = SOURCES_DIR / "stepbible" / "Tagged-Bibles"
    candidates = list(base.glob("TTESV*.txt"))
    if not candidates:
        raise FileNotFoundError(f"No TTESV file found in {base}.")
    return candidates[0]


# ── Load reference data from DB ───────────────────────────────────────────────

def load_source_words() -> tuple[dict, dict]:
    """
    Returns two dicts:
      he_by_ref:  (book_id, ch, v, strongs) → list of (word_id, word_position)
      gk_by_ref:  (book_id, ch, v, strongs) → list of (word_id, word_position)
    Also returns verse word counts for proportional mapping.
    """
    print("  Loading Hebrew word index …")
    he_by_strongs: dict[tuple, list[tuple]] = {}
    he_verse_counts: dict[tuple, int] = {}

    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, book_id, chapter, verse, word_position, strongs "
                "FROM hebrew_words WHERE strongs IS NOT NULL ORDER BY book_id, chapter, verse, word_position"
            )
            for word_id, book_id, ch, v, pos, strongs in cur.fetchall():
                key = (book_id, ch, v, strongs)
                he_by_strongs.setdefault(key, []).append((word_id, pos))
                ref = (book_id, ch, v)
                he_verse_counts[ref] = max(he_verse_counts.get(ref, 0), pos)

    print("  Loading Greek word index …")
    gk_by_strongs: dict[tuple, list[tuple]] = {}
    gk_verse_counts: dict[tuple, int] = {}

    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, book_id, chapter, verse, word_position, strongs "
                "FROM greek_words WHERE strongs IS NOT NULL ORDER BY book_id, chapter, verse, word_position"
            )
            for word_id, book_id, ch, v, pos, strongs in cur.fetchall():
                key = (book_id, ch, v, strongs)
                gk_by_strongs.setdefault(key, []).append((word_id, pos))
                ref = (book_id, ch, v)
                gk_verse_counts[ref] = max(gk_verse_counts.get(ref, 0), pos)

    return he_by_strongs, gk_by_strongs, he_verse_counts, gk_verse_counts


def load_dutch_words() -> tuple[dict, dict]:
    """
    Returns:
      dutch_by_pos:  (book_id, ch, v, word_position) → translation_word_id
      dutch_counts:  (book_id, ch, v) → total word count
    """
    print("  Loading Dutch word index …")
    dutch_by_pos: dict[tuple, int] = {}
    dutch_counts: dict[tuple, int] = {}

    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                SELECT tv.book_id, tv.chapter, tv.verse,
                       tw.word_position, tw.id
                FROM translation_words tw
                JOIN translation_verses tv ON tw.verse_id = tv.id
                WHERE tv.translation_id = %s
                """,
                (TRANSLATION_ID,),
            )
            for book_id, ch, v, pos, tw_id in cur.fetchall():
                dutch_by_pos[(book_id, ch, v, pos)] = tw_id
                ref = (book_id, ch, v)
                dutch_counts[ref] = max(dutch_counts.get(ref, 0), pos)

    print(f"  Loaded {len(dutch_by_pos):,} Dutch word positions")
    return dutch_by_pos, dutch_counts


def proportional_dutch_pos(src_pos: int, src_total: int,
                            dst_total: int) -> int:
    """Map source word position proportionally to Dutch verse."""
    if src_total <= 1:
        return 1
    ratio = (src_pos - 1) / (src_total - 1)
    return max(1, min(dst_total, round(1 + ratio * (dst_total - 1))))


# ── Main alignment ────────────────────────────────────────────────────────────

def align_pivot() -> None:
    ttesv_path = find_ttesv_file()
    print(f"  Reading: {ttesv_path.name}")

    he_by_strongs, gk_by_strongs, he_verse_counts, gk_verse_counts = load_source_words()
    dutch_by_pos, dutch_counts = load_dutch_words()

    inserted = 0
    skipped  = 0

    with open(ttesv_path, encoding="utf-8-sig") as f:
        lines = [l.rstrip("\n") for l in f if l.startswith("$")]

    print(f"  Found {len(lines):,} TTESV verse lines")

    for line in tqdm(lines, desc="  Pivot alignment", unit=" verses"):
        m = DATA_LINE_RE.match(line)
        if not m:
            continue

        book_code = m.group(1)
        chapter   = int(m.group(2))
        verse     = int(m.group(3))
        tokens_str = m.group(4)

        book_id = TTESV_BOOK_MAP.get(book_code)
        if book_id is None:
            continue

        testament = "OT" if book_id <= 39 else "NT"
        src_index = he_by_strongs if testament == "OT" else gk_by_strongs
        src_counts = he_verse_counts if testament == "OT" else gk_verse_counts
        lang = "HE" if testament == "OT" else "GR"

        ref = (book_id, chapter, verse)
        dutch_total = dutch_counts.get(ref, 0)
        src_total   = src_counts.get(ref, 0)

        if dutch_total == 0 or src_total == 0:
            skipped += 1
            continue

        # Parse each token: esv_positions=<strongs>[+<strongs>]
        for token_m in TOKEN_RE.finditer(tokens_str):
            strongs_list = parse_strongs(token_m.group(2), testament)
            if not strongs_list:
                continue

            for strongs in strongs_list:
                src_words = src_index.get((book_id, chapter, verse, strongs))
                if not src_words:
                    # Try without leading zero padding variations
                    # TTESV uses 5-digit OT / 4-digit NT; our DB may differ
                    # Try alternate padding
                    alt = _alt_strongs(strongs, testament)
                    for a in alt:
                        src_words = src_index.get((book_id, chapter, verse, a))
                        if src_words:
                            break

                if not src_words:
                    skipped += 1
                    continue

                for src_word_id, src_pos in src_words:
                    # Map source word position proportionally to Dutch
                    dst_pos = proportional_dutch_pos(src_pos, src_total, dutch_total)
                    tw_id   = dutch_by_pos.get((book_id, chapter, verse, dst_pos))

                    if tw_id is None:
                        skipped += 1
                        continue

                    try:
                        link_id = insert_word_link(lang, src_word_id, tw_id)
                        insert_link_confidence(
                            link_id, "pivot", 0.6,
                            notes=f"TTESV strongs {strongs} pos {src_pos}/{src_total}→{dst_pos}/{dutch_total}"
                        )
                        inserted += 1
                    except Exception:
                        skipped += 1

    print(f"  ✓ Pivot links inserted: {inserted:,}")
    print(f"  ✗ Skipped:              {skipped:,}")


def _alt_strongs(strongs: str, testament: str) -> list[str]:
    """Generate alternate padding variants to handle mismatches."""
    alts = []
    if testament == "OT" and strongs.startswith("H"):
        num_str = strongs[1:]
        try:
            num = int(num_str)
            # Try both 4-digit and 5-digit padding
            alts.append(f"H{num:04d}")
            alts.append(f"H{num:05d}")
            # With disambiguator suffixes G/A/B
            for suffix in ("G", "A", "B"):
                alts.append(f"H{num:04d}{suffix}")
        except ValueError:
            pass
    elif testament == "NT" and strongs.startswith("G"):
        num_str = strongs[1:]
        try:
            num = int(num_str)
            alts.append(f"G{num:04d}")
            for suffix in ("G", "A", "B"):
                alts.append(f"G{num:04d}{suffix}")
        except ValueError:
            pass
    return alts


if __name__ == "__main__":
    align_pivot()
