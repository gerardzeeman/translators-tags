"""
parse_tahot.py
Parses the STEPBible TAHOT (Translators Amalgamated Hebrew OT) file and
inserts word tokens into the hebrew_words table.

File format: tab-separated, lines beginning with # are comments.
Key columns (0-indexed after splitting on \t):
  0  – Reference       e.g. Gen.1.1
  1  – Hebrew word     pointed Unicode
  2  – Transliteration
  3  – Lemma
  4  – Strong's        e.g. H0430a
  5  – Morphology code e.g. HNcmpa
  (additional columns may follow; we only use 0-5)
"""
from pathlib import Path
from tqdm import tqdm
from db.loaders import bulk_insert_hebrew_words

SOURCES_DIR = Path("/data/sources")

# STEPBible uses its own book abbreviations. Map to our canonical book ids.
BOOK_MAP: dict[str, int] = {
    "Gen": 1,  "Exo": 2,  "Lev": 3,  "Num": 4,  "Deu": 5,
    "Jos": 6,  "Jdg": 7,  "Rut": 8,  "1Sa": 9,  "2Sa": 10,
    "1Ki": 11, "2Ki": 12, "1Ch": 13, "2Ch": 14, "Ezr": 15,
    "Neh": 16, "Est": 17, "Job": 18, "Psa": 19, "Pro": 20,
    "Ecc": 21, "Sol": 22, "Isa": 23, "Jer": 24, "Lam": 25,
    "Eze": 26, "Dan": 27, "Hos": 28, "Joe": 29, "Amo": 30,
    "Oba": 31, "Jon": 32, "Mic": 33, "Nah": 34, "Hab": 35,
    "Zep": 36, "Hag": 37, "Zec": 38, "Mal": 39,
}

BATCH_SIZE = 2000


def find_tahot_file() -> Path:
    """Locate the TAHOT sorted-by-reference file in the STEPBible repo."""
    base = SOURCES_DIR / "stepbible" / "Translators Amalgamated OT+NT"
    candidates = list(base.glob("TAHOT*Ref*.txt")) + list(base.glob("TAHOT*.txt"))
    if not candidates:
        raise FileNotFoundError(
            f"No TAHOT file found in {base}. "
            "Check that the STEPBible repo cloned correctly."
        )
    # Prefer the sorted-by-Ref version
    for p in candidates:
        if "Ref" in p.name or "ref" in p.name:
            return p
    return candidates[0]


def parse_reference(ref: str) -> tuple[int, int, int] | None:
    """Parse 'Gen.1.1' → (book_id, chapter, verse). Returns None on failure."""
    parts = ref.strip().split(".")
    if len(parts) < 3:
        return None
    book_code = parts[0]
    book_id = BOOK_MAP.get(book_code)
    if book_id is None:
        return None
    try:
        chapter = int(parts[1])
        verse   = int(parts[2])
    except ValueError:
        return None
    return book_id, chapter, verse


def parse_tahot() -> None:
    tahot_path = find_tahot_file()
    print(f"  Reading: {tahot_path.name}")

    batch: list[dict] = []
    total = 0
    current_ref: tuple | None = None
    word_position = 0

    with open(tahot_path, encoding="utf-8") as f:
        for line in tqdm(f, desc="  TAHOT", unit=" lines"):
            line = line.rstrip("\n")

            # Skip comment/header lines
            if line.startswith("#") or not line.strip():
                continue

            cols = line.split("\t")
            if len(cols) < 6:
                continue

            ref_str     = cols[0].strip()
            word_text   = cols[1].strip()
            translit    = cols[2].strip() or None
            lemma       = cols[3].strip() or None
            strongs_raw = cols[4].strip()
            morph_code  = cols[5].strip() or None

            parsed = parse_reference(ref_str)
            if parsed is None:
                continue
            book_id, chapter, verse = parsed

            # Advance word position counter within the verse
            ref_key = (book_id, chapter, verse)
            if ref_key != current_ref:
                current_ref = ref_key
                word_position = 1
            else:
                word_position += 1

            # Normalise Strong's: H430a → H0430a (pad numeric part to 4 digits)
            strongs = _normalise_strongs_he(strongs_raw)

            # Detect Ketiv/Qere annotations (STEPBible marks with K/Q suffix)
            is_ketiv = "K" in morph_code if morph_code else False
            has_qere = "Q" in morph_code if morph_code else False

            batch.append({
                "book_id":        book_id,
                "chapter":        chapter,
                "verse":          verse,
                "word_position":  word_position,
                "word_text":      word_text,
                "transliteration": translit,
                "lemma":          lemma,
                "strongs":        strongs,
                "morph_code":     morph_code,
                "is_ketiv":       is_ketiv,
                "has_qere":       has_qere,
            })

            if len(batch) >= BATCH_SIZE:
                bulk_insert_hebrew_words(batch)
                total += len(batch)
                batch.clear()

    if batch:
        bulk_insert_hebrew_words(batch)
        total += len(batch)

    print(f"  ✓ Hebrew words inserted: {total:,}")


def _normalise_strongs_he(raw: str) -> str | None:
    """Convert 'H430a' → 'H0430a', 'H0430' → 'H0430', '' → None."""
    raw = raw.strip()
    if not raw or raw in ("0", "H0"):
        return None
    if not raw.startswith("H"):
        return None
    rest = raw[1:]  # everything after 'H'
    # Split numeric part from optional letter suffix
    num_part = ""
    suffix = ""
    for i, ch in enumerate(rest):
        if ch.isdigit():
            num_part += ch
        else:
            suffix = rest[i:]
            break
    if not num_part:
        return None
    return f"H{int(num_part):04d}{suffix}"


if __name__ == "__main__":
    parse_tahot()
