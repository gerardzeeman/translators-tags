"""
parse_statenvertaling.py
Parses the Zefania XML Statenvertaling file and inserts:
  - translation_verses  (one row per verse)
  - translation_words   (one row per tokenised Dutch word)

Zefania XML structure:
  <XMLBIBLE>
    <BIBLEBOOK bnumber="1" bname="Genesis" bsname="Gen">
      <CHAPTER cnumber="1">
        <VERS vnumber="1">In den beginne schiep God …</VERS>
      </CHAPTER>
    </BIBLEBOOK>
  </XMLBIBLE>
"""
import re
import unicodedata
from pathlib import Path
from lxml import etree
from tqdm import tqdm
from db.loaders import upsert_translation_verse, bulk_insert_translation_words

SOURCES_DIR    = Path("/data/sources")
XML_PATH       = SOURCES_DIR / "dut-statenvertaling.zefania.xml"
TRANSLATION_ID = 1   # as seeded in schema.sql

# Map Zefania bnumber (1-based) to our canonical book_id
# Zefania uses 1-66 matching our book ids directly
def zefania_book_to_id(bnumber: int) -> int | None:
    if 1 <= bnumber <= 66:
        return bnumber
    return None


# Punctuation to strip from word edges when tokenising
_PUNCT_EDGE = re.compile(r"^[^\w\u0590-\u05FF]+|[^\w\u0590-\u05FF]+$", re.UNICODE)
# Matches whitespace runs (split boundary)
_WHITESPACE = re.compile(r"\s+")


def tokenise_verse(verse_text: str) -> list[dict]:
    """
    Split verse_text into word tokens. Returns a list of dicts with:
      word_position, word_text, word_normalised, char_start, char_end
    char_start / char_end are character offsets into verse_text.
    """
    tokens = []
    position = 1

    for m in re.finditer(r"\S+", verse_text):
        raw_token = m.group()
        char_start = m.start()
        char_end   = m.end()

        # Strip leading/trailing punctuation
        clean = _PUNCT_EDGE.sub("", raw_token)
        if not clean:
            continue

        # Normalise: lowercase, NFD decomposition, drop combining marks
        nfd = unicodedata.normalize("NFD", clean.lower())
        normalised = "".join(
            c for c in nfd if unicodedata.category(c) != "Mn"
        )

        tokens.append({
            "word_position":  position,
            "word_text":      clean,
            "word_normalised": normalised,
            "char_start":     char_start,
            "char_end":       char_end,
        })
        position += 1

    return tokens


def extract_verse_text(vers_elem) -> str:
    """
    Extract plain text from a <VERS> element, stripping child XML tags
    (e.g. <BR/>, <NOTE>, <STYLE>) and collapsing whitespace.
    """
    # itertext() walks all text nodes including tail text of child elements
    parts = list(vers_elem.itertext())
    text = " ".join(p.strip() for p in parts if p.strip())
    # Collapse multiple spaces
    return re.sub(r"\s+", " ", text).strip()


def parse_statenvertaling() -> None:
    if not XML_PATH.exists():
        raise FileNotFoundError(
            f"Statenvertaling XML not found at {XML_PATH}\n"
            "Run fetch_sources.py first."
        )

    print(f"  Parsing: {XML_PATH.name}")
    tree = etree.parse(str(XML_PATH))
    root = tree.getroot()

    total_verses = 0
    total_words  = 0
    word_batch: list[dict] = []

    bible_books = root.findall(".//BIBLEBOOK")
    for book_elem in tqdm(bible_books, desc="  Statenvertaling books", unit=" book"):
        bnumber_raw = book_elem.get("bnumber", "0")
        try:
            bnumber = int(bnumber_raw)
        except ValueError:
            continue

        book_id = zefania_book_to_id(bnumber)
        if book_id is None:
            continue

        for chapter_elem in book_elem.findall("CHAPTER"):
            cnumber_raw = chapter_elem.get("cnumber", "0")
            try:
                chapter = int(cnumber_raw)
            except ValueError:
                continue

            for vers_elem in chapter_elem.findall("VERS"):
                vnumber_raw = vers_elem.get("vnumber", "0")
                try:
                    verse = int(vnumber_raw)
                except ValueError:
                    continue

                verse_text = extract_verse_text(vers_elem)
                if not verse_text:
                    continue

                # Insert / update the verse row
                verse_id = upsert_translation_verse(
                    TRANSLATION_ID, book_id, chapter, verse, verse_text
                )
                total_verses += 1

                # Tokenise and collect word rows
                tokens = tokenise_verse(verse_text)
                for token in tokens:
                    word_batch.append({"verse_id": verse_id, **token})
                    total_words += 1

                # Flush periodically
                if len(word_batch) >= 5000:
                    bulk_insert_translation_words(word_batch)
                    word_batch.clear()

    if word_batch:
        bulk_insert_translation_words(word_batch)

    print(f"  ✓ Verses inserted:           {total_verses:,}")
    print(f"  ✓ Translation words inserted: {total_words:,}")


if __name__ == "__main__":
    parse_statenvertaling()
