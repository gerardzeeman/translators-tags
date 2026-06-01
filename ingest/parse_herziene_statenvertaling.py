"""
parse_herziene_statenvertaling.py
Scrapes the Herziene Statenvertaling (HSV) from herzienestatenvertaling.nl and inserts:
  - translation_verses  (one row per verse)
  - translation_words   (one row per tokenised Dutch word)

The site is server-rendered. Each chapter page lives at:
  https://herzienestatenvertaling.nl/teksten/{book_slug}/{chapter}

HTML structure per page (inside <div class="bible-content">):
  <p class="p">                                  ← one or more per chapter section
    <span class="verse-span"
          data-verse-id="GEN.1.1"
          data-verse-org-ids="GEN.1.1">          ← FIRST span: verse number only
      1
    </span>
    <span class="verse-span"
          data-verse-id="GEN.1.1"
          data-verse-org-ids="GEN.1.1">          ← SECOND span: verse text (+ noise)
      <a href="/teksten-rd/verse/JOB.38.4">…</a> ← cross-references  → stripped
      In het begin schiep God …                  ← keep this
      <span class="verse-note">…</span>          ← inline footnotes  → stripped
    </span>
  </p>

Usage:
  python parse_herziene_statenvertaling.py            # all books
  python parse_herziene_statenvertaling.py --book GEN # single book
  python parse_herziene_statenvertaling.py --dry-run  # print first chapter, no DB writes
"""
import argparse
import re
import sys
import time
import unicodedata
from typing import Generator
from urllib.parse import quote

import requests
from bs4 import BeautifulSoup, Tag

from db.connection import get_connection
from db.loaders import upsert_translation_verse, bulk_insert_translation_words

# ─── Constants ────────────────────────────────────────────────────────────────

TRANSLATION_ID   = 2       # HSV — must match the row inserted in translations table
BASE_URL         = "https://herzienestatenvertaling.nl/teksten"
REQUEST_DELAY    = 1.2     # seconds between HTTP requests (be a polite scraper)
REQUEST_TIMEOUT  = 30
MAX_RETRIES      = 3
RETRY_DELAY      = 5       # seconds before retrying a failed request

# ─── Book catalogue ───────────────────────────────────────────────────────────
# (book_id, usfm_code, chapter_count, url_slug)
# url_slug: lowercase Dutch name, diacritics stripped, spaces → hyphens.

BOOKS: list[tuple[int, str, int, str]] = [
    # Old Testament
    (1,  "GEN",  50, "genesis"),
    (2,  "EXO",  40, "exodus"),
    (3,  "LEV",  27, "leviticus"),
    (4,  "NUM",  36, "numeri"),
    (5,  "DEU",  34, "deuteronomium"),
    (6,  "JOS",  24, "jozua"),
    (7,  "JDG",  21, "richteren"),
    (8,  "RUT",   4, "ruth"),
    (9,  "1SA",  31, "1 samuel"),
    (10, "2SA",  24, "2 samuel"),
    (11, "1KI",  22, "1 koningen"),
    (12, "2KI",  25, "2 koningen"),
    (13, "1CH",  29, "1 kronieken"),
    (14, "2CH",  36, "2 kronieken"),
    (15, "EZR",  10, "ezra"),
    (16, "NEH",  13, "nehemia"),
    (17, "EST",  10, "esther"),
    (18, "JOB",  42, "job"),
    (19, "PSA", 150, "psalm"),
    (20, "PRO",  31, "spreuken"),
    (21, "ECC",  12, "prediker"),
    (22, "SNG",   8, "hooglied"),
    (23, "ISA",  66, "jesaja"),
    (24, "JER",  52, "jeremia"),
    (25, "LAM",   5, "klaagliederen"),
    (26, "EZK",  48, "ezechiël"),
    (27, "DAN",  12, "daniël"),
    (28, "HOS",  14, "hosea"),
    (29, "JOL",   3, "joël"),
    (30, "AMO",   9, "amos"),
    (31, "OBA",   1, "obadja"),
    (32, "JON",   4, "jona"),
    (33, "MIC",   7, "micha"),
    (34, "NAM",   3, "nahum"),
    (35, "HAB",   3, "habakuk"),
    (36, "ZEP",   3, "zefanja"),
    (37, "HAG",   2, "haggaï"),
    (38, "ZEC",  14, "zacharia"),
    (39, "MAL",   4, "maleachi"),
    # New Testament
    (40, "MAT",  28, "mattheüs"),
    (41, "MRK",  16, "markus"),
    (42, "LUK",  24, "lukas"),
    (43, "JHN",  21, "johannes"),
    (44, "ACT",  28, "handelingen"),
    (45, "ROM",  16, "romeinen"),
    (46, "1CO",  16, "1 korinthe"),
    (47, "2CO",  13, "2 korinthe"),
    (48, "GAL",   6, "galaten"),
    (49, "EPH",   6, "efeze"),
    (50, "PHP",   4, "filippenzen"),
    (51, "COL",   4, "kolossenzen"),
    (52, "1TH",   5, "1 thessalonicenzen"),
    (53, "2TH",   3, "2 thessalonicenzen"),
    (54, "1TI",   6, "1 timotheüs"),
    (55, "2TI",   4, "2 timotheüs"),
    (56, "TIT",   3, "titus"),
    (57, "PHM",   1, "filemon"),
    (58, "HEB",  13, "hebreeën"),
    (59, "JAS",   5, "jakobus"),
    (60, "1PE",   5, "1 petrus"),
    (61, "2PE",   3, "2 petrus"),
    (62, "1JN",   5, "1 johannes"),
    (63, "2JN",   1, "2 johannes"),
    (64, "3JN",   1, "3 johannes"),
    (65, "JUD",   1, "judas"),
    (66, "REV",  22, "openbaring"),
]

# Quick look-up: usfm_code → (book_id, chapter_count, url_slug)
_BOOK_BY_USFM: dict[str, tuple[int, int, str]] = {
    usfm: (bid, ch, slug) for bid, usfm, ch, slug in BOOKS
}

# ─── Text helpers ─────────────────────────────────────────────────────────────

_PUNCT_EDGE = re.compile(r"^[^\w֐-׿]+|[^\w֐-׿]+$", re.UNICODE)


def normalise(text: str) -> str:
    nfd = unicodedata.normalize("NFD", text.lower())
    return "".join(c for c in nfd if unicodedata.category(c) != "Mn")


def tokenise_verse(verse_text: str) -> list[dict]:
    """Split verse_text into word tokens (mirrors parse_statenvertaling.py)."""
    tokens = []
    position = 1

    for m in re.finditer(r"\S+", verse_text):
        raw_token = m.group()
        char_start = m.start()
        char_end   = m.end()

        clean = _PUNCT_EDGE.sub("", raw_token)
        if not clean:
            continue

        tokens.append({
            "word_position":   position,
            "word_text":       clean,
            "word_normalised": normalise(clean),
            "char_start":      char_start,
            "char_end":        char_end,
        })
        position += 1

    return tokens


# ─── HTML parsing helpers ─────────────────────────────────────────────────────

# Classes whose content is NOT verse text and should be removed before extraction.
# Add more here if the scraper picks up unwanted footnote/commentary text.
_NOISE_CLASSES = {
    "verse-note",       # inline footnotes (e.g. "1:5 Toen … geweest – Letterlijk: …")
    "study-note",       # study-bible commentary
    "note",
    "footnote",
    "fn",
    "xref",             # cross-reference container (if wrapped separately)
}


def _extract_verse_tokens(span: Tag) -> tuple[str, list[dict]]:
    """
    Extract verse text and word tokens from a verse-span element.

    Words inside ``<span class="add">`` are marked ``is_filler=True`` — these
    are the HSV cursive/bracketed words that have no direct source-language
    backing (e.g. "maar" in "Houd [maar] op").

    Returns
    -------
    verse_text : str
        Full plain-text verse string (for translation_verses.verse_text).
    tokens : list[dict]
        One dict per word with keys:
          word_position, word_text, word_normalised,
          char_start, char_end, is_filler
    """
    from bs4 import NavigableString

    # Work on a deep copy so we don't mutate the live soup
    span = BeautifulSoup(str(span), "lxml").find("span")
    if span is None:
        return "", []

    # ── strip noise ───────────────────────────────────────────────────────────
    for a in span.find_all("a"):
        a.decompose()
    for tag in span.find_all(True):
        if set(tag.get("class") or []) & _NOISE_CLASSES:
            tag.decompose()

    # ── walk DOM collecting (raw_token, is_filler) pairs ─────────────────────
    raw_words: list[tuple[str, bool]] = []

    def _walk(node, in_filler: bool) -> None:
        if isinstance(node, NavigableString):
            for m in re.finditer(r"\S+", str(node)):
                raw_words.append((m.group(), in_filler))
        else:
            classes = set(node.get("class") or [])
            _walk_filler = in_filler or ("add" in classes)
            for child in node.children:
                _walk(child, _walk_filler)

    _walk(span, False)

    # ── build tokens and verse text ───────────────────────────────────────────
    tokens: list[dict] = []
    verse_parts: list[str] = []
    position   = 1
    char_offset = 0

    for raw_token, is_filler in raw_words:
        clean = _PUNCT_EDGE.sub("", raw_token)
        verse_parts.append(raw_token)

        if clean:
            tokens.append({
                "word_position":   position,
                "word_text":       clean,
                "word_normalised": normalise(clean),
                "char_start":      char_offset,
                "char_end":        char_offset + len(raw_token),
                "is_filler":       is_filler,
            })
            position += 1

        char_offset += len(raw_token) + 1  # +1 for the space separator

    verse_text = re.sub(r"\s+", " ", " ".join(verse_parts)).strip()
    return verse_text, tokens


def _parse_verse_id(verse_id: str) -> tuple[str, int, int] | None:
    """Parse 'GEN.1.1' → ('GEN', 1, 1). Returns None on bad format."""
    parts = verse_id.split(".")
    if len(parts) != 3:
        return None
    usfm, chapter_s, verse_s = parts
    try:
        return usfm, int(chapter_s), int(verse_s)
    except ValueError:
        return None


# ─── HTTP fetching ────────────────────────────────────────────────────────────

_session = requests.Session()
_session.headers.update({
    "User-Agent": (
        "Mozilla/5.0 (compatible; BibleCompareIngest/1.0; "
        "+https://github.com/yourproject)"
    ),
    "Accept-Language": "nl,en;q=0.9",
})


def fetch_chapter_html(slug: str, chapter: int) -> str | None:
    """Fetch a chapter page and return raw HTML. Retries on failure."""
    slug_safe = quote(slug)
    url = f"{BASE_URL}/{slug_safe}/{chapter}"
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            resp = _session.get(url, timeout=REQUEST_TIMEOUT)
            resp.raise_for_status()
            return resp.text
        except requests.RequestException as exc:
            if attempt < MAX_RETRIES:
                print(f"    ⚠  {url} – attempt {attempt} failed: {exc}; "
                      f"retrying in {RETRY_DELAY}s …", flush=True)
                time.sleep(RETRY_DELAY)
            else:
                print(f"    ✗  {url} – all {MAX_RETRIES} attempts failed: {exc}",
                      flush=True)
                return None


# ─── Core extraction ──────────────────────────────────────────────────────────

def iter_verses(html: str, expected_usfm: str | None = None
                ) -> Generator[tuple[str, int, int, str], None, None]:
    """
    Parse a chapter page and yield (usfm_code, chapter, verse, verse_text)
    for every verse found.

    expected_usfm: if given, only yield verses whose data-verse-id starts
    with this USFM code. Useful to avoid picking up the parallel SV column
    that the site also renders on the same page.
    """
    soup = BeautifulSoup(html, "lxml")

    # The HSV content lives inside div.bible-content
    bible_div = soup.find("div", class_="bible-content")
    if bible_div is None:
        # Fall back: search the whole document
        bible_div = soup

    # Find all verse-span elements in document order
    all_spans = bible_div.find_all("span", class_="verse-span")

    # Some verses have inline icons that produce extra verse-span elements
    # between text spans. Structure per verse (HSV column):
    #   span 1 : verse number  (text is a plain integer)
    #   span 2 : first text fragment
    #   span 3 : icon  (empty after stripping <a> tags)
    #   span 4 : second text fragment
    #   …
    # The SV parallel column re-uses the same data-verse-id values, so once
    # we see a *number span* for a verse-id we already collected, we know the
    # HSV column has ended and we stop.

    seen_number: set[str] = set()           # verse_ids whose number span was seen
    closed: set[str] = set()                # verse_ids where SV column was detected
    verse_text_spans: dict[str, list] = {}  # verse_id → list of candidate spans

    for span in all_spans:
        verse_id = span.get("data-verse-id", "").strip()
        if not verse_id:
            continue

        # If expected_usfm is set, skip spans whose verse-id doesn't match
        if expected_usfm and not verse_id.startswith(expected_usfm + "."):
            continue

        if verse_id in closed:
            continue

        # Skip verse-spans nested inside <span class="add"> — these are
        # filler-word markers whose content is already captured when we
        # walk the parent span in _extract_verse_tokens.
        parent = span.parent
        if parent and "add" in (parent.get("class") or []):
            continue

        raw_text = span.get_text(separator="").strip()
        is_number_span = bool(re.match(r"^\d+$", raw_text))

        if verse_id not in seen_number:
            # First occurrence → this is the number span; initialise bucket
            seen_number.add(verse_id)
            verse_text_spans[verse_id] = []
        else:
            if is_number_span:
                # A second number span means the SV parallel column started
                closed.add(verse_id)
            else:
                verse_text_spans[verse_id].append(span)

    # Yield in document order (dict preserves insertion order, Python ≥ 3.7)
    for verse_id, spans in verse_text_spans.items():
        parsed = _parse_verse_id(verse_id)
        if parsed is None:
            continue
        usfm, chapter, verse = parsed

        # Extract tokens (with filler flags) and verse text from each span,
        # then merge into a single verse.
        all_tokens: list[dict] = []
        verse_parts: list[str] = []
        position = 1
        for span in spans:
            verse_text_part, span_tokens = _extract_verse_tokens(span)
            if verse_text_part:
                verse_parts.append(verse_text_part)
            for tok in span_tokens:
                tok["word_position"] = position
                all_tokens.append(tok)
                position += 1

        if all_tokens:
            verse_text = re.sub(r"\s+", " ", " ".join(verse_parts)).strip()
            yield usfm, chapter, verse, verse_text, all_tokens


# ─── DB helpers ───────────────────────────────────────────────────────────────

def ensure_hsv_translation() -> None:
    """Insert the HSV translation row if it doesn't exist yet."""
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO translations (id, code, name, language, direction)
                VALUES (%s, 'HSV', 'Herziene Statenvertaling', 'nld', 'LTR')
                ON CONFLICT (id) DO NOTHING
                """,
                (TRANSLATION_ID,),
            )


# ─── Main scrape loop ─────────────────────────────────────────────────────────

def scrape_book(book_id: int, usfm: str, chapter_count: int, slug: str,
                dry_run: bool = False) -> tuple[int, int]:
    """Scrape all chapters of one book. Returns (verse_count, word_count)."""
    total_verses = 0
    total_words  = 0
    word_batch: list[dict] = []

    for chapter in range(1, chapter_count + 1):
        html = fetch_chapter_html(slug, chapter)
        if html is None:
            print(f"    Skipping {usfm} {chapter} (fetch failed)", flush=True)
            time.sleep(REQUEST_DELAY)
            continue

        for verse_usfm, verse_ch, verse_num, verse_text, tokens in iter_verses(html, expected_usfm=usfm):
            if dry_run:
                filler_count = sum(1 for t in tokens if t["is_filler"])
                print(f"  {verse_usfm} {verse_ch}:{verse_num}  "
                      f"{verse_text[:100]}"
                      f"  [{filler_count} filler]" if filler_count else
                      f"  {verse_usfm} {verse_ch}:{verse_num}  {verse_text[:120]}")
                continue

            # Resolve book_id from the data-verse-id usfm code
            # (guards against accidentally ingesting the SV parallel column)
            resolved = _BOOK_BY_USFM.get(verse_usfm)
            if resolved is None:
                continue
            resolved_book_id = resolved[0]

            verse_id = upsert_translation_verse(
                TRANSLATION_ID, resolved_book_id, verse_ch, verse_num, verse_text
            )
            total_verses += 1

            for token in tokens:
                word_batch.append({"verse_id": verse_id, **token})
                total_words += 1

            if len(word_batch) >= 2000:
                bulk_insert_translation_words(word_batch)
                word_batch.clear()

        time.sleep(REQUEST_DELAY)

    if word_batch and not dry_run:
        bulk_insert_translation_words(word_batch)

    return total_verses, total_words


def parse_herziene_statenvertaling(
    only_book: str | None = None,
    dry_run: bool = False,
) -> None:
    if not dry_run:
        ensure_hsv_translation()

    # Determine which books to process
    if only_book:
        only_book = only_book.upper()
        book_list = [(bid, usfm, ch, slug)
                     for bid, usfm, ch, slug in BOOKS
                     if usfm == only_book]
        if not book_list:
            print(f"ERROR: Unknown USFM code '{only_book}'", file=sys.stderr)
            sys.exit(1)
    else:
        book_list = BOOKS

    grand_verses = 0
    grand_words  = 0

    for book_id, usfm, chapter_count, slug in book_list:
        print(f"  [{usfm}] {slug} ({chapter_count} chapters) …", flush=True)
        v, w = scrape_book(book_id, usfm, chapter_count, slug, dry_run=dry_run)
        grand_verses += v
        grand_words  += w
        if not dry_run:
            print(f"    ✓ {v:,} verses, {w:,} words", flush=True)

    if not dry_run:
        print(f"\n  ✓ Total verses inserted: {grand_verses:,}")
        print(f"  ✓ Total words inserted:  {grand_words:,}")


# ─── CLI entry point ──────────────────────────────────────────────────────────

if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Scrape the Herziene Statenvertaling and load it into the database."
    )
    parser.add_argument(
        "--book",
        metavar="USFM",
        default=None,
        help="Process a single book only (e.g. GEN, MAT, REV).",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Print extracted verses to stdout; do not write to the database.",
    )
    args = parser.parse_args()

    parse_herziene_statenvertaling(
        only_book=args.book,
        dry_run=args.dry_run,
    )
