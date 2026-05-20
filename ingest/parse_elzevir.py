"""
parse_elzevir.py
Parses the byztxt/greektext-elzevir .UEL files (one per NT book).

File format (space-separated, lines may wrap mid-verse):
  1:1 word1 strongs1 {parse1} word2 strongs2 {parse2} ...

Beta Code conversion uses the byztxt custom method based on the beta-code library.
"""
import re
from pathlib import Path
from tqdm import tqdm
import beta_code
from db.loaders import bulk_insert_greek_words

SOURCES_DIR = Path("/data/sources")

BOOK_MAP: dict[str, int] = {
    "MT": 40, "MR": 41, "LU": 42, "JOH": 43,
    "AC": 44, "RO": 45, "1CO": 46, "2CO": 47,
    "GA": 48, "EPH": 49, "PHP": 50, "COL": 51,
    "1TH": 52, "2TH": 53, "1TI": 54, "2TI": 55,
    "TIT": 56, "PHM": 57, "HEB": 58, "JAS": 59,
    "1PE": 60, "2PE": 61, "1JO": 62, "2JO": 63,
    "3JO": 64, "JUDE": 65, "RE": 66,
}

BATCH_SIZE = 1000
VERSE_REF_RE = re.compile(r"^(\d+):(\d+)\s+(.*)")
# Matches: word strongs {parse}  OR  word strongs extra_num {parse}
TOKEN_RE = re.compile(r"(\S+)\s+(\d+)(?:\s+\d+)?\s+\{([^}]+)\}")


# ── Beta Code conversion (adapted from byztxt/byzantine-majority-text) ────────

def standardise_beta_code(text: str) -> str:
    """
    Pre-process Beta Code to make it compatible with the beta-code library.
    Adapted from byztxt/byzantine-majority-text/scripts/beta_to_unicode_custom.
    """
    # Swap order of + followed by / or \ (library expects /+ not +/)
    text = text.replace("+/", "/+")
    text = text.replace("+\\", "\\+")
    # Replace apostrophes with right quotation marks
    text = text.replace("'", "\u2019")
    # Add space before dash/bracket after final sigma so it stays final
    text = text.replace("S-", "S -")
    text = text.replace("S]", "S ]")

    # Custom
    text = text.replace("c", "x")
    text = text.replace("v", "s")
    text = text.replace("y", "q")

    return text.strip()

def standardised_to_transliteration(text: str):
    text = text.replace("h", "ē")
    text = text.replace("x", "ch")
    text = text.replace("w", "ō")
    text = text.replace("q", "th")
    text = text.replace("v", "s")

    return text


def beta_word_to_unicode(word: str) -> str:
    """
    Convert a single Beta Code word token to Unicode Greek.
    Adapted from convert_beta_to_unicode_strongs() in the byztxt script,
    but operating on a single pre-extracted word (no strongs/parse codes).
    """
    standardised = standardise_beta_code(word)
    
    try:
        result = beta_code.beta_code_to_greek(standardised)
        # Restore extra space added around final sigma before dash/bracket
        result = result.replace("{ν", "{NA")
        result = result.replace("{β", "{Byz")
        result = result.replace("{ξ", "{NA27/28")
        result = result.replace("{μ", "{ECM")
        result = result.replace("{ς", "{NA27")
        result = result.replace("{ε", "{NA28")
        result = result.replace("ς -", "ς-")
        result = result.replace("ς ]", "ς]")
        result = result.replace(" ♦ ", " = ")
        return result
    except Exception:
        return word  # return original on any conversion failure


# ── Strong's normalisation ────────────────────────────────────────────────────

def parse_strongs_gk(raw: str) -> str | None:
    raw = raw.strip()
    if not raw or raw == "0":
        return None
    try:
        num = int(raw)
        return f"G{num:04d}" if num > 0 else None
    except ValueError:
        return None


# ── Main parser ───────────────────────────────────────────────────────────────

def parse_elzevir() -> None:
    parsed_dir = SOURCES_DIR / "elzevir" / "parsed"
    if not parsed_dir.exists():
        raise FileNotFoundError(f"Elzevir parsed directory not found: {parsed_dir}")

    uel_files = sorted(parsed_dir.glob("*.UEL"))
    if not uel_files:
        raise FileNotFoundError(f"No .UEL files found in {parsed_dir}")

    total_words = 0

    for uel_path in tqdm(uel_files, desc="  Elzevir books", unit=" book"):
        book_code = uel_path.stem.upper()
        book_id   = BOOK_MAP.get(book_code)
        if book_id is None:
            print(f"\n  ⚠ Unknown book code '{book_code}', skipping {uel_path.name}")
            continue

        rows: list[dict] = []
        current_chapter: int | None = None
        current_verse:   int | None = None
        current_text = ""

        def flush_verse(chapter: int, verse: int, text: str) -> None:
            position = 1
            for m in TOKEN_RE.finditer(text):
                beta_word  = m.group(1)
                strongs    = parse_strongs_gk(m.group(2))
                parse_code = m.group(3).strip()
                word_text  = beta_word_to_unicode(beta_word)
                translit   = standardised_to_transliteration(str(beta_word))
                rows.append({
                    "book_id":         book_id,
                    "chapter":         chapter,
                    "verse":           verse,
                    "word_position":   position,
                    "word_text":       word_text,
                    "lemma":           None,
                    "strongs":         strongs,
                    "parse_code":      parse_code,
                    "transliteration": translit,
                })
                position += 1

        with open(uel_path, encoding="utf-8", newline="") as f:
            for line in f:
                line = line.rstrip("\n")
                if not line.strip():
                    continue

                m = VERSE_REF_RE.match(line)
                if m:
                    if current_chapter is not None:
                        flush_verse(current_chapter, current_verse, current_text)
                    current_chapter = int(m.group(1))
                    current_verse   = int(m.group(2))
                    current_text    = m.group(3)
                else:
                    current_text += " " + line.strip()

        # Flush final verse of the book
        if current_chapter is not None:
            flush_verse(current_chapter, current_verse, current_text)

        if rows:
            bulk_insert_greek_words(rows)
            total_words += len(rows)
            rows.clear()

    print(f"\n  ✓ Greek words inserted: {total_words:,}")
    _validate_count(total_words)


def _validate_count(count: int) -> None:
    expected_min, expected_max = 130_000, 145_000
    if not (expected_min <= count <= expected_max):
        print(
            f"  ⚠ Word count {count:,} outside expected range "
            f"({expected_min:,}–{expected_max:,}). Check all 27 books were present."
        )


if __name__ == "__main__":
    parse_elzevir()
