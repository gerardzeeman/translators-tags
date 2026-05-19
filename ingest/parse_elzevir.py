"""
parse_elzevir.py
Parses the byztxt/greektext-elzevir .UEL files (one per NT book).

File format (space-separated, lines may wrap mid-verse):
  1:1 word1 strongs1 {parse1} word2 strongs2 {parse2} ...
  1:2 word1 strongs1 {parse1} ...

A line starting with digits and a colon begins a new verse.
Continuation lines (starting with a space) belong to the current verse.
Some entries have an extra number between strongs and parse (variant flag).
"""
import re
from pathlib import Path
from tqdm import tqdm
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

# Matches a verse reference at the start of a line e.g. "1:1"
VERSE_REF_RE = re.compile(r"^(\d+):(\d+)\s+(.*)")

# Matches a single word token: word strongs {parse} or word strongs extra {parse}
TOKEN_RE = re.compile(r"(\S+)\s+(\d+)(?:\s+\d+)?\s+\{([^}]+)\}")


def parse_strongs_gk(raw: str) -> str | None:
    raw = raw.strip()
    if not raw or raw == "0":
        return None
    try:
        num = int(raw)
        return f"G{num:04d}" if num > 0 else None
    except ValueError:
        return None


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
        current_chapter = None
        current_verse   = None
        current_text    = ""   # accumulated text for current verse

        def flush_verse(chapter, verse, text):
            """Parse accumulated verse text into word tokens and add to rows."""
            position = 1
            for m in TOKEN_RE.finditer(text):
                word_text  = m.group(1)
                strongs    = parse_strongs_gk(m.group(2))
                parse_code = m.group(3).strip()
                rows.append({
                    "book_id":       book_id,
                    "chapter":       chapter,
                    "verse":         verse,
                    "word_position": position,
                    "word_text":     word_text,
                    "lemma":         None,
                    "strongs":       strongs,
                    "parse_code":    parse_code,
                })
                position += 1

        with open(uel_path, encoding="utf-8", newline="") as f:
            for line in f:
                line = line.rstrip("\n")
                if not line.strip():
                    continue

                m = VERSE_REF_RE.match(line)
                if m:
                    # New verse — flush previous
                    if current_chapter is not None:
                        flush_verse(current_chapter, current_verse, current_text)
                    current_chapter = int(m.group(1))
                    current_verse   = int(m.group(2))
                    current_text    = m.group(3)
                else:
                    # Continuation line — append to current verse text
                    current_text += " " + line.strip()

        # Flush last verse
        if current_chapter is not None:
            flush_verse(current_chapter, current_verse, current_text)

        if len(rows) >= BATCH_SIZE:
            bulk_insert_greek_words(rows)
            total_words += len(rows)
            rows.clear()

        if rows:
            bulk_insert_greek_words(rows)
            total_words += len(rows)
            rows.clear()

    print(f"\n  ✓ Greek words inserted: {total_words:,}")


if __name__ == "__main__":
    parse_elzevir()
