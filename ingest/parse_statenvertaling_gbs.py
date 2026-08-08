"""
parse_statenvertaling_gbs.py
Scrapes the Statenvertaling text edition (Gereformeerde Bijbelstichting,
gbs.nl) from statenvertaling.nl and inserts:
  - translation_verses  (one row per verse)
  - translation_words   (one row per tokenised Dutch word)

This is a distinct edition from the Zefania-based 'SV' translation
(id 1, parsed by parse_statenvertaling.py) — same base text tradition,
different critical edition/typesetting.

The site is server-rendered. Each chapter page ("Alleen Bijbeltekst" view,
without kanttekeningen or cross-references) lives at:
  https://statenvertaling.nl/tekst.php?bb={bb}&hf={chapter}&ind=4

HTML structure per page (inside <table class="tekst">):
  <tr><td class="tekstbreed">
    <a name="vers1"></a>1 In den beginne schiep God …
  </td></tr>

Some rows are section headers, not verses (e.g. the Hebrew acrostic
headings in Psalm 119: <td class="tekstpar119 cursief">Aleph</td>) —
these lack class "tekstbreed" and are skipped automatically.

Words wrapped in <i>...</i> are typographic additions — words with no
direct equivalent in the source text, added by the translators for
readability. Same convention as is_filler on the HSV parser.

Usage:
  python parse_statenvertaling_gbs.py            # all books
  python parse_statenvertaling_gbs.py --book GEN  # single book
  python parse_statenvertaling_gbs.py --dry-run   # print first chapter, no DB writes
"""
import argparse
import re
import sys
import time
import unicodedata
from typing import Generator

import requests
from bs4 import BeautifulSoup, NavigableString, Tag

from db.connection import get_connection
from db.loaders import upsert_translation_verse, bulk_insert_translation_words

# ─── Constants ────────────────────────────────────────────────────────────────

TRANSLATION_ID   = 3       # SV-GBS — must match the row inserted in translations table
BASE_URL         = "https://statenvertaling.nl/tekst.php"
REQUEST_DELAY    = 1.2     # seconds between HTTP requests (be a polite scraper)
REQUEST_TIMEOUT  = 30
MAX_RETRIES      = 3
RETRY_DELAY      = 5       # seconds before retrying a failed request

# ─── Book catalogue ───────────────────────────────────────────────────────────
# (book_id, usfm_code, chapter_count)
# The site's own "bb" query parameter is the book number 1-66 in exactly
# this order, so bb == book_id directly — no slug lookup needed.

BOOKS: list[tuple[int, str, int]] = [
    # Old Testament
    (1,  "GEN",  50), (2,  "EXO",  40), (3,  "LEV",  27), (4,  "NUM",  36),
    (5,  "DEU",  34), (6,  "JOS",  24), (7,  "JDG",  21), (8,  "RUT",   4),
    (9,  "1SA",  31), (10, "2SA",  24), (11, "1KI",  22), (12, "2KI",  25),
    (13, "1CH",  29), (14, "2CH",  36), (15, "EZR",  10), (16, "NEH",  13),
    (17, "EST",  10), (18, "JOB",  42), (19, "PSA", 150), (20, "PRO",  31),
    (21, "ECC",  12), (22, "SNG",   8), (23, "ISA",  66), (24, "JER",  52),
    (25, "LAM",   5), (26, "EZK",  48), (27, "DAN",  12), (28, "HOS",  14),
    (29, "JOL",   3), (30, "AMO",   9), (31, "OBA",   1), (32, "JON",   4),
    (33, "MIC",   7), (34, "NAM",   3), (35, "HAB",   3), (36, "ZEP",   3),
    (37, "HAG",   2), (38, "ZEC",  14), (39, "MAL",   4),
    # New Testament
    (40, "MAT",  28), (41, "MRK",  16), (42, "LUK",  24), (43, "JHN",  21),
    (44, "ACT",  28), (45, "ROM",  16), (46, "1CO",  16), (47, "2CO",  13),
    (48, "GAL",   6), (49, "EPH",   6), (50, "PHP",   4), (51, "COL",   4),
    (52, "1TH",   5), (53, "2TH",   3), (54, "1TI",   6), (55, "2TI",   4),
    (56, "TIT",   3), (57, "PHM",   1), (58, "HEB",  13), (59, "JAS",   5),
    (60, "1PE",   5), (61, "2PE",   3), (62, "1JN",   5), (63, "2JN",   1),
    (64, "3JN",   1), (65, "JUD",   1), (66, "REV",  22),
]

# ─── Text helpers ─────────────────────────────────────────────────────────────

_PUNCT_EDGE = re.compile(r"^[^\w֐-׿]+|[^\w֐-׿]+$", re.UNICODE)
_VERSE_ANCHOR = re.compile(r"^vers(\d+)$")


def normalise(text: str) -> str:
    nfd = unicodedata.normalize("NFD", text.lower())
    return "".join(c for c in nfd if unicodedata.category(c) != "Mn")


def _extract_verse_tokens(td: Tag, verse_num: int) -> tuple[str, list[dict]]:
    """
    Extract verse text and word tokens from a <td class="tekstbreed"> element.

    Words inside <i> are marked is_filler=True — typographic additions with
    no direct source-language backing. The leading verse-number token (the
    plain-text "N" preceding the verse, outside any tag) is dropped.
    """
    raw_words: list[tuple[str, bool]] = []

    def _walk(node, in_filler: bool) -> None:
        if isinstance(node, NavigableString):
            for m in re.finditer(r"\S+", str(node)):
                raw_words.append((m.group(), in_filler))
        elif isinstance(node, Tag):
            if node.name == "a":
                return  # verse-number anchor: empty, but skip defensively
            child_filler = in_filler or node.name == "i"
            for child in node.children:
                _walk(child, child_filler)

    for child in td.children:
        _walk(child, False)

    # Drop the leading plain verse-number token, e.g. "1" before "In".
    if raw_words and raw_words[0] == (str(verse_num), False):
        raw_words = raw_words[1:]

    tokens: list[dict] = []
    verse_parts: list[str] = []
    position    = 1
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


# ─── HTTP fetching ────────────────────────────────────────────────────────────

_session = requests.Session()
_session.headers.update({
    "User-Agent": (
        "Mozilla/5.0 (compatible; BibleCompareIngest/1.0; "
        "+https://github.com/yourproject)"
    ),
    "Accept-Language": "nl,en;q=0.9",
})


def fetch_chapter_html(bb: int, chapter: int) -> str | None:
    """Fetch a chapter page ('Alleen Bijbeltekst' view) and return raw HTML."""
    url = f"{BASE_URL}?bb={bb}&hf={chapter}&ind=4"
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

def iter_verses(html: str) -> Generator[tuple[int, str, list[dict]], None, None]:
    """Parse a chapter page and yield (verse, verse_text, tokens) per verse."""
    soup = BeautifulSoup(html, "lxml")

    table = soup.find("table", class_="tekst")
    if table is None:
        return

    for td in table.find_all("td", class_="tekstbreed"):
        anchor = td.find("a")
        name = anchor.get("name", "") if anchor else ""
        m = _VERSE_ANCHOR.match(name or "")
        if not m:
            continue
        verse_num = int(m.group(1))

        verse_text, tokens = _extract_verse_tokens(td, verse_num)
        if tokens:
            yield verse_num, verse_text, tokens


# ─── DB helpers ───────────────────────────────────────────────────────────────

def ensure_svgbs_translation() -> None:
    """Insert the SV-GBS translation row if it doesn't exist yet."""
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO translations (id, code, name, language, direction, family, source_lang_authority)
                VALUES (%s, 'SV-GBS', 'Statenvertaling (GBS-editie)', 'nld', 'LTR', 'SV', FALSE)
                ON CONFLICT (id) DO NOTHING
                """,
                (TRANSLATION_ID,),
            )


# ─── Main scrape loop ─────────────────────────────────────────────────────────

def scrape_book(book_id: int, usfm: str, chapter_count: int,
                dry_run: bool = False) -> tuple[int, int]:
    """Scrape all chapters of one book. Returns (verse_count, word_count)."""
    total_verses = 0
    total_words  = 0
    word_batch: list[dict] = []

    for chapter in range(1, chapter_count + 1):
        html = fetch_chapter_html(book_id, chapter)
        if html is None:
            print(f"    Skipping {usfm} {chapter} (fetch failed)", flush=True)
            time.sleep(REQUEST_DELAY)
            continue

        for verse_num, verse_text, tokens in iter_verses(html):
            if dry_run:
                filler_count = sum(1 for t in tokens if t["is_filler"])
                suffix = f"  [{filler_count} filler]" if filler_count else ""
                print(f"  {usfm} {chapter}:{verse_num}  {verse_text[:120]}{suffix}")
                continue

            verse_id = upsert_translation_verse(
                TRANSLATION_ID, book_id, chapter, verse_num, verse_text
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


def parse_statenvertaling_gbs(
    only_book: str | None = None,
    dry_run: bool = False,
) -> None:
    if not dry_run:
        ensure_svgbs_translation()

    if only_book:
        only_book = only_book.upper()
        book_list = [(bid, usfm, ch) for bid, usfm, ch in BOOKS if usfm == only_book]
        if not book_list:
            print(f"ERROR: Unknown USFM code '{only_book}'", file=sys.stderr)
            sys.exit(1)
    else:
        book_list = BOOKS

    grand_verses = 0
    grand_words  = 0

    for book_id, usfm, chapter_count in book_list:
        print(f"  [{usfm}] ({chapter_count} chapters) …", flush=True)
        v, w = scrape_book(book_id, usfm, chapter_count, dry_run=dry_run)
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
        description="Scrape the Statenvertaling (GBS-editie, statenvertaling.nl) into the database."
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

    parse_statenvertaling_gbs(
        only_book=args.book,
        dry_run=args.dry_run,
    )
