"""
parse_hsv_cross_references.py
Scrapes verse-level cross-references ("zie ook") from herzienestatenvertaling.nl
and stores them in cross_references (source='HSV'), anchored to the exact
word they follow in the verse.

Reuses the same chapter pages already scraped for verse text
(parse_herziene_statenvertaling.py) — cross-references live in the same HTML,
previously stripped out during verse-text extraction.

HTML structure per page:
  <span class="verse-span" data-verse-id="GEN.1.27" ...>naar Zijn beeld; ... </span>
  <span class="x" data-verse-id="GEN.1.27" id="GEN.1.27!x.2">
    <span class="xt">
      <span id="MAT.19.4">
        <a class="showExternalVerse" data-title="Matt. 19:4"
           href="/teksten-rd/verse/MAT.19.4">Matt. 19:4</a>
      </span>
      <!-- possibly more <a>'s in the same group -->
    </span>
  </span>
  <span class="verse-span" data-verse-id="GEN.1.27" ...>mannelijk en vrouwelijk schiep Hij hen.</span>

HSV has no numbered/lettered footnote distinction like SV(GBS) — every
<span class="x"> group is a pure cross-reference. Letters (a, b, c, ...) are
assigned sequentially per verse in document order, purely for display,
matching the convention requested for the UI.

`word_position` is tracked by walking verse-span/x-span siblings in document
order, counting real word tokens via the same tokeniser used for the base
text scrape (imported from parse_herziene_statenvertaling.py, so counts stay
consistent with the stored translation_words.word_position) up to each
<span class="x"> group. Each chapter page renders this block twice (two
identical "bible-content" divs) — scoped to the first one only, matching
parse_herziene_statenvertaling.py's own iter_verses().

Usage:
  python parse_hsv_cross_references.py            # all books
  python parse_hsv_cross_references.py --book GEN # single book
  python parse_hsv_cross_references.py --dry-run  # print, no DB writes
"""
import argparse
import re
import sys
import time
from urllib.parse import quote

import requests
from bs4 import BeautifulSoup

from db.loaders import bulk_insert_cross_references
from parse_herziene_statenvertaling import _extract_verse_tokens

# ─── Constants ────────────────────────────────────────────────────────────────

SOURCE           = "HSV"
BASE_URL         = "https://herzienestatenvertaling.nl/teksten"
REQUEST_DELAY    = 1.2
REQUEST_TIMEOUT  = 30
MAX_RETRIES      = 3
RETRY_DELAY      = 5

# ─── Book catalogue (book_id, usfm_code, chapter_count, url_slug) ─────────────
# Identical to parse_herziene_statenvertaling.py — same site, same slugs.

BOOKS: list[tuple[int, str, int, str]] = [
    (1,  "GEN",  50, "genesis"), (2,  "EXO",  40, "exodus"), (3,  "LEV",  27, "leviticus"),
    (4,  "NUM",  36, "numeri"), (5,  "DEU",  34, "deuteronomium"), (6,  "JOS",  24, "jozua"),
    (7,  "JDG",  21, "richteren"), (8,  "RUT",   4, "ruth"), (9,  "1SA",  31, "1 samuel"),
    (10, "2SA",  24, "2 samuel"), (11, "1KI",  22, "1 koningen"), (12, "2KI",  25, "2 koningen"),
    (13, "1CH",  29, "1 kronieken"), (14, "2CH",  36, "2 kronieken"), (15, "EZR",  10, "ezra"),
    (16, "NEH",  13, "nehemia"), (17, "EST",  10, "esther"), (18, "JOB",  42, "job"),
    (19, "PSA", 150, "psalm"), (20, "PRO",  31, "spreuken"), (21, "ECC",  12, "prediker"),
    (22, "SNG",   8, "hooglied"), (23, "ISA",  66, "jesaja"), (24, "JER",  52, "jeremia"),
    (25, "LAM",   5, "klaagliederen"), (26, "EZK",  48, "ezechiël"), (27, "DAN",  12, "daniël"),
    (28, "HOS",  14, "hosea"), (29, "JOL",   3, "joël"), (30, "AMO",   9, "amos"),
    (31, "OBA",   1, "obadja"), (32, "JON",   4, "jona"), (33, "MIC",   7, "micha"),
    (34, "NAM",   3, "nahum"), (35, "HAB",   3, "habakuk"), (36, "ZEP",   3, "zefanja"),
    (37, "HAG",   2, "haggaï"), (38, "ZEC",  14, "zacharia"), (39, "MAL",   4, "maleachi"),
    (40, "MAT",  28, "mattheüs"), (41, "MRK",  16, "markus"), (42, "LUK",  24, "lukas"),
    (43, "JHN",  21, "johannes"), (44, "ACT",  28, "handelingen"), (45, "ROM",  16, "romeinen"),
    (46, "1CO",  16, "1 korinthe"), (47, "2CO",  13, "2 korinthe"), (48, "GAL",   6, "galaten"),
    (49, "EPH",   6, "efeze"), (50, "PHP",   4, "filippenzen"), (51, "COL",   4, "kolossenzen"),
    (52, "1TH",   5, "1 thessalonicenzen"), (53, "2TH",   3, "2 thessalonicenzen"),
    (54, "1TI",   6, "1 timotheüs"), (55, "2TI",   4, "2 timotheüs"), (56, "TIT",   3, "titus"),
    (57, "PHM",   1, "filemon"), (58, "HEB",  13, "hebreeën"), (59, "JAS",   5, "jakobus"),
    (60, "1PE",   5, "1 petrus"), (61, "2PE",   3, "2 petrus"), (62, "1JN",   5, "1 johannes"),
    (63, "2JN",   1, "2 johannes"), (64, "3JN",   1, "3 johannes"), (65, "JUD",   1, "judas"),
    (66, "REV",  22, "openbaring"),
]

_BOOK_ID_BY_USFM: dict[str, int] = {usfm: bid for bid, usfm, _ch, _slug in BOOKS}

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
    url = f"{BASE_URL}/{quote(slug)}/{chapter}"
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            resp = _session.get(url, timeout=REQUEST_TIMEOUT)
            resp.raise_for_status()
            return resp.text
        except requests.RequestException as exc:
            if attempt < MAX_RETRIES:
                print(f"    ⚠  {url} – attempt {attempt} failed: {exc}; retrying …", flush=True)
                time.sleep(RETRY_DELAY)
            else:
                print(f"    ✗  {url} – all {MAX_RETRIES} attempts failed: {exc}", flush=True)
                return None


# ─── Extraction ────────────────────────────────────────────────────────────────

_HREF_REF = re.compile(r"/verse/([1-3]?[A-Z]{2,3})\.(\d+)\.(\d+)$")


def _parse_verse_id(verse_id: str) -> tuple[str, int, int] | None:
    parts = (verse_id or "").split(".")
    if len(parts) != 3:
        return None
    usfm, ch, vs = parts
    try:
        return usfm, int(ch), int(vs)
    except ValueError:
        return None


def _letter_for(n: int) -> str:
    """0-indexed ordinal -> 'a', 'b', ..., 'z', 'aa', 'ab', ... (extremely unlikely to be needed)."""
    if n < 26:
        return chr(ord('a') + n)
    return chr(ord('a') + n // 26 - 1) + chr(ord('a') + n % 26)


def iter_cross_refs(html: str, expected_usfm: str):
    """Yield (chapter, verse, letter, word_position, ordinal, target_book_id, target_chapter, target_verse, label)."""
    soup = BeautifulSoup(html, "lxml")
    bible_div = soup.find("div", class_="bible-content")
    if bible_div is None:
        bible_div = soup

    seen_number: set[str] = set()
    closed: set[str] = set()
    seen_group_ids: set[str] = set()
    word_count_by_verse: dict[str, int] = {}
    letter_ord_by_verse: dict[str, int] = {}

    for span in bible_div.find_all("span"):
        verse_id = span.get("data-verse-id", "").strip()
        if not verse_id:
            continue
        parsed = _parse_verse_id(verse_id)
        if parsed is None or parsed[0] != expected_usfm:
            continue
        classes = span.get("class") or []

        if "x" in classes:
            if verse_id in closed or verse_id not in seen_number:
                continue
            group_id = span.get("id")
            if group_id:
                if group_id in seen_group_ids:
                    continue
                seen_group_ids.add(group_id)

            _, chapter, verse = parsed
            word_position = word_count_by_verse.get(verse_id, 0)

            targets = []
            for a in span.find_all("a", class_="showExternalVerse"):
                m = _HREF_REF.search(a.get("href", ""))
                if not m:
                    continue
                target_usfm, target_chapter, target_verse = m.group(1), int(m.group(2)), int(m.group(3))
                target_book_id = _BOOK_ID_BY_USFM.get(target_usfm)
                if target_book_id is None:
                    continue
                label = a.get("data-title") or a.get_text(strip=True)
                targets.append((target_book_id, target_chapter, target_verse, label))

            if targets:
                letter_n = letter_ord_by_verse.get(verse_id, 0)
                letter = _letter_for(letter_n)
                letter_ord_by_verse[verse_id] = letter_n + 1
                for ordinal, (tb, tc, tv, label) in enumerate(targets, start=1):
                    yield chapter, verse, letter, word_position, ordinal, tb, tc, tv, label
            continue

        if "verse-span" not in classes:
            continue

        # Mirrors parse_herziene_statenvertaling.py's iter_verses() number/text-span
        # detection, so word counts stay consistent with the stored word_position.
        if verse_id not in seen_number:
            seen_number.add(verse_id)
            word_count_by_verse[verse_id] = 0
            continue

        if verse_id in closed:
            continue

        raw_text = span.get_text(separator="").strip()
        if re.match(r"^\d+$", raw_text):
            closed.add(verse_id)
            continue

        _, span_tokens = _extract_verse_tokens(span)
        word_count_by_verse[verse_id] = word_count_by_verse.get(verse_id, 0) + len(span_tokens)


# ─── Main scrape loop ─────────────────────────────────────────────────────────

def scrape_book(book_id: int, usfm: str, chapter_count: int, slug: str,
                dry_run: bool = False) -> int:
    total_refs = 0
    row_batch: list[dict] = []

    for chapter in range(1, chapter_count + 1):
        html = fetch_chapter_html(slug, chapter)
        if html is None:
            print(f"    Skipping {usfm} {chapter} (fetch failed)", flush=True)
            time.sleep(REQUEST_DELAY)
            continue

        for ch, vs, letter, word_pos, ordinal, tgt_book_id, tgt_ch, tgt_vs, label in iter_cross_refs(html, usfm):
            if dry_run:
                print(f"  {usfm} {ch}:{vs} [{letter}@{word_pos}.{ordinal}] -> book {tgt_book_id} {tgt_ch}:{tgt_vs} ({label})")
                continue

            row_batch.append({
                "source": SOURCE, "book_id": book_id, "chapter": ch, "verse": vs,
                "letter": letter, "word_position": word_pos, "ordinal": ordinal,
                "target_book_id": tgt_book_id, "target_chapter": tgt_ch, "target_verse": tgt_vs,
                "label": label,
            })
            total_refs += 1

            if len(row_batch) >= 2000:
                bulk_insert_cross_references(row_batch)
                row_batch.clear()

        time.sleep(REQUEST_DELAY)

    if row_batch and not dry_run:
        bulk_insert_cross_references(row_batch)

    return total_refs


def parse_hsv_cross_references(only_book: str | None = None, dry_run: bool = False) -> None:
    if only_book:
        only_book = only_book.upper()
        book_list = [(bid, usfm, ch, slug) for bid, usfm, ch, slug in BOOKS if usfm == only_book]
        if not book_list:
            print(f"ERROR: Unknown USFM code '{only_book}'", file=sys.stderr)
            sys.exit(1)
    else:
        book_list = BOOKS

    grand_total = 0
    for book_id, usfm, chapter_count, slug in book_list:
        print(f"  [{usfm}] {slug} ({chapter_count} chapters) …", flush=True)
        n = scrape_book(book_id, usfm, chapter_count, slug, dry_run=dry_run)
        grand_total += n
        if not dry_run:
            print(f"    ✓ {n:,} cross-references", flush=True)

    if not dry_run:
        print(f"\n  ✓ Total cross-references inserted: {grand_total:,}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Scrape HSV verse-level cross-references into cross_references (source='HSV')."
    )
    parser.add_argument("--book", metavar="USFM", default=None, help="Process a single book only (e.g. GEN).")
    parser.add_argument("--dry-run", action="store_true", help="Print extracted references; do not write to the database.")
    args = parser.parse_args()

    parse_hsv_cross_references(only_book=args.book, dry_run=args.dry_run)
