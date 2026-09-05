"""
parse_tahot.py
Parses the STEPBible TAHOT files (4 files covering the full Hebrew OT).

Files:
  TAHOT Gen-Deu - Translators Amalgamated Hebrew OT - STEPBible.org CC BY.txt
  TAHOT Jos-Est - Translators Amalgamated Hebrew OT - STEPBible.org CC BY.txt
  TAHOT Job-Sng - Translators Amalgamated Hebrew OT - STEPBible.org CC BY.txt
  TAHOT Isa-Mal - Translators Amalgamated Hebrew OT - STEPBible.org CC BY.txt

FORMAT (tab-separated columns):
  0  Ref & Type   e.g. Gen.1.1#01=L   or  Gen.1.1#01=Q(K)
  1  Hebrew       pointed Unicode with / separating prefix/suffix from root
  2  Transliteration
  3  Translation  English gloss
  4  dStrongs     root in {curly braces}, prefixes outside  e.g. H9001/{H0559}
  5  Grammar      ETCBC morphology code
  6  Meaning Variants
  7  Spelling Variants
  8  Root dStrong+Instance
  9  Alt Strongs
  10 Conjoin word
  11 Expanded Strong tags

VARIANT SELECTION RULES (from file header):
  - Follow L (Leningrad) as the primary text.
  - L sometimes presents a Qere/Ketiv choice: the type after = is L with Q/K in
    brackets, e.g. =L means plain Leningrad; the Qere rows are =Q and Ketiv =K.
  - We include: =L, =Q, =R (restored), =X (LXX extra words)
  - We exclude: =K (Ketiv — the text the Qere replaces)
  - Lower-case variant letters in brackets indicate non-significant spelling
    variants and are ignored (they do not produce separate rows).

STRONG'S EXTRACTION:
  dStrongs column contains tags like: H9001/{H0559}  or  {H0430G}  or  H9009/{H0216}
  The root word's Strong number is in {curly braces}.
  Prefixes/suffixes (H9001, H9009, etc.) are grammatical function tags, not lexical.
  We extract only the root Strong from inside {}.
  Numbers >= H9000 are STEPBible grammatical function tags, not BDB lexical entries.
"""
import re
from pathlib import Path
from tqdm import tqdm
from db.loaders import bulk_insert_hebrew_words

SOURCES_DIR = Path("/data/sources")
BATCH_SIZE  = 2000

# ── Book code mapping ─────────────────────────────────────────────────────────
# STEPBible uses standard English abbreviations in the reference field.
BOOK_MAP: dict[str, int] = {
    "Gen": 1,  "Exo": 2,  "Lev": 3,  "Num": 4,  "Deu": 5,
    "Jos": 6,  "Jdg": 7,  "Rut": 8,  "1Sa": 9,  "2Sa": 10,
    "1Ki": 11, "2Ki": 12, "1Ch": 13, "2Ch": 14, "Ezr": 15,
    "Neh": 16, "Est": 17, "Job": 18, "Psa": 19, "Pro": 20,
    "Ecc": 21, "Sol": 22, "Sng": 22, "Isa": 23, "Jer": 24,
    "Lam": 25, "Eze": 26, "Ezk": 26, "Dan": 27, "Hos": 28,
    "Joe": 29, "Jol": 29, "Amo": 30, "Oba": 31, "Jon": 32,
    "Mic": 33, "Nah": 34, "Hab": 35, "Zep": 36, "Hag": 37,
    "Zec": 38, "Mal": 39,
    # Ruth is sometimes "Rut" in STEPBible
    "Rth": 8,
    # STEPBible's TAHOT files use "Nam" (not the more common "Nah") for Nahum
    "Nam": 34,
}

# ── Regex patterns ────────────────────────────────────────────────────────────

# Matches the reference column: Book.chapter.verse#wordnum=TYPE
# TYPE can be: L, Q, K, R, X, or L with variants like Q(K), K(Q+B) etc.
REF_RE = re.compile(
    r"^(\d?[A-Z][A-Za-z0-9]*)"  # book code (optionally numeral-prefixed, e.g. 1Sa, 2Ch)
    r"\.(\d+)"                 # chapter
    r"\.(\d+)"                 # verse  (may have suffix like .36a)
    r"#(\d+)"                  # word number
    r"=([A-Za-z]+)"            # primary text type (L/Q/K/R/X)
)

# Extracts root Strong's from dStrongs column: finds content inside {curly braces}
STRONGS_ROOT_RE = re.compile(r"\{([^}]+)\}")

# Column indices (0-based, tab-separated)
COL_REF    = 0
COL_HEB    = 1
COL_TRANS  = 2
COL_GLOSS  = 3
COL_STRONG = 4
COL_MORPH  = 5

# ── Helper functions ──────────────────────────────────────────────────────────

def find_tahot_files() -> list[Path]:
    """Find all 4 TAHOT files in the STEPBible repo."""
    base = SOURCES_DIR / "stepbible" / "Translators Amalgamated OT+NT"
    files = sorted(base.glob("TAHOT*.txt"))
    # Exclude OLD format directory
    files = [f for f in files if "OLD" not in str(f) and "OLD format" not in str(f) and "Hebrew OT" in str(f)]
    if not files:
        raise FileNotFoundError(
            f"No TAHOT files found in {base}.\n"
            "Ensure the STEPBible repo cloned correctly."
        )
    return files


def parse_ref(ref_col: str) -> tuple[int, int, int, int, str] | None:
    """
    Parse reference column into (book_id, chapter, verse, word_num, text_type).
    Returns None if the reference cannot be parsed or book is unknown.
    """
    m = REF_RE.match(ref_col.strip())
    if not m:
        return None
    book_code = m.group(1)
    book_id   = BOOK_MAP.get(book_code)
    if book_id is None:
        return None
    try:
        chapter  = int(m.group(2))
        verse    = int(re.match(r"(\d+)", m.group(3)).group(1))  # strip any suffix
        word_num = int(m.group(4))
    except (ValueError, AttributeError):
        return None
    text_type = m.group(5).upper()
    return book_id, chapter, verse, word_num, text_type


def should_include(text_type: str) -> bool:
    """
    Decide whether to include a row based on its text type.
    Include: L (Leningrad), Q (Qere), R (Restored), X (LXX extra)
    Exclude: K (Ketiv — the text replaced by Qere)
    """
    return text_type in ("L", "Q", "R", "X")


def extract_root_strongs(dstrongs: str) -> str | None:
    """
    Extract the root lexical Strong's number from the dStrongs column.
    The root is enclosed in {curly braces}.
    H9000+ are STEPBible grammatical function tags; skip them.
    Returns a normalised string like 'H0430' or None.
    """
    m = STRONGS_ROOT_RE.search(dstrongs)
    if not m:
        return None
    raw = m.group(1).strip()
    # Strip any suffix annotations like H0430G → keep as-is (G is a disambiguator)
    # Just normalise the numeric part to 4 digits
    num_match = re.match(r"H(\d+)(.*)", raw)
    if not num_match:
        return None
    num = int(num_match.group(1))
    if num >= 9000:
        return None   # grammatical function tag, not a lexical entry
    suffix = num_match.group(2)  # e.g. 'G' in H0430G — preserve disambiguator
    return f"H{num:04d}{suffix}"


def clean_hebrew(text: str) -> str:
    """
    Remove the prefix/suffix separators (/ and \) from the Hebrew text
    to get the full word as it appears, keeping all pointing and cantillation.
    Also strips trailing punctuation markers like H9016 (verse end ׃).
    """
    # Remove backslash-separated punctuation/markers at end: \H9016 etc.
    text = re.sub(r"\\[A-Z0-9]+$", "", text)
    # Remove remaining backslashes (punctuation separators within word)
    text = text.replace("\\", "")
    # Remove forward slash prefix/suffix separators
    text = text.replace("/", "")
    return text.strip()


def is_header_line(line: str) -> bool:
    """True for the repeated column header lines."""
    return line.startswith("Eng (Heb) Ref")


def is_comment_line(line: str) -> bool:
    """True for # interlinear summary lines and blank lines."""
    stripped = line.strip()
    return not stripped or stripped.startswith("#")


# ── Main parser ───────────────────────────────────────────────────────────────

def parse_tahot() -> None:
    files = find_tahot_files()
    print(f"  Found {len(files)} TAHOT file(s):")
    for f in files:
        print(f"    {f.name}")

    batch: list[dict] = []
    total = 0

    # Track word position within each verse independently per text type.
    # Key: (book_id, chapter, verse) → next word_position for L/Q/R/X stream
    verse_positions: dict[tuple, int] = {}

    for tahot_path in files:
        print(f"\n  Parsing: {tahot_path.name}")

        with open(tahot_path, encoding="utf-8") as f:
            lines = f.readlines()

        for raw_line in tqdm(lines, desc=f"  {tahot_path.stem[:20]}", unit=" lines"):
            line = raw_line.rstrip("\n")

            # Skip blank lines, comment/interlinear lines, and header rows
            if is_comment_line(line) or is_header_line(line):
                continue

            cols = line.split("\t")
            if len(cols) < 6:
                continue

            ref_col    = cols[COL_REF].strip()
            heb_raw    = cols[COL_HEB].strip()
            translit   = cols[COL_TRANS].strip() or None
            # gloss    = cols[COL_GLOSS].strip()   # not stored for now
            dstrongs   = cols[COL_STRONG].strip()
            morph_code = cols[COL_MORPH].strip() or None

            parsed = parse_ref(ref_col)
            if parsed is None:
                continue

            book_id, chapter, verse, word_num, text_type = parsed

            if not should_include(text_type):
                continue   # skip Ketiv rows

            # Word position: use the #word_num from the reference as the
            # canonical position (it counts words in the Leningrad text).
            # Q rows reuse the same word_num as the L row they replace,
            # so we treat word_num as the stable position key.
            word_position = word_num

            # Flags
            is_ketiv = False                        # we never include K rows
            has_qere = (text_type == "Q")

            hebrew_text = clean_hebrew(heb_raw)
            strongs     = extract_root_strongs(dstrongs)

            batch.append({
                "book_id":        book_id,
                "chapter":        chapter,
                "verse":          verse,
                "word_position":  word_position,
                "word_text":      hebrew_text,
                "transliteration": translit,
                "lemma":          None,  # dStrongs is used instead
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

    print(f"\n  ✓ Hebrew words inserted: {total:,}")
    _validate(total)


def _validate(count: int) -> None:
    expected_min, expected_max = 280_000, 340_000
    if not (expected_min <= count <= expected_max):
        print(
            f"  ⚠ Word count {count:,} is outside the expected range "
            f"({expected_min:,}–{expected_max:,}). "
            "Check that all 4 TAHOT files were present and parsed correctly."
        )


if __name__ == "__main__":
    parse_tahot()
