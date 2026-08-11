"""
parse_svgbs_cross_references.py
Scrapes PURE cross-references ("kruisverwijzingen") from the
"Met kanttekeningen"-weergave (ind=1) on statenvertaling.nl and stores them
in cross_references (source='SVGBS'), anchored to the exact word they
follow in the verse.

The site distinguishes two kinds of footnotes:
  - numbered (1, 2, 3, ...): explanatory commentary, often citing verses
    inline as supporting prose -- DELIBERATELY EXCLUDED here.
  - lettered (a, b, c, ...): pure cross-reference lists -- these are what
    gets stored.

HTML structure per page (inside <table class='tekst'>):
  <tr><td class='tekstbreed'><a name='vers1'></a>1 IN den <sup>1</sup><sup>a</sup>beginne ...</td></tr>
  <tr><td class='tussenonder'></td><td class='kanttonder'><b>1</b> Van den tijd ...
      <a href='javascript:fverwijs(1)' title='Tekstverwijzingen'><img .../></a></td></tr>
  <tr id='tr1' style='display: none'>...(footnote 1's expansion -- SKIPPED, numbered)...</tr>
  <tr><td class='tussenonder'></td><td class='kanttonder'><b>a</b> Job 38:4. Ps. 33:6; ...
      <a href='javascript:fverwijs(2)' title='Tekstverwijzingen'><img .../></a></td></tr>
  <tr id='tr2' style='display: none'><td class='tussenonder'>&nbsp;</td><td class='kanttonder'>
      <br /><a href='tekst.php?bb=18&hf=38&ind=1#vers4'>Job 38:4</a>&nbsp;&nbsp;[verse text]<br />
      ... one <a> per referenced verse ...
  </td></tr>

`word_position` is computed by walking the verse-text <td> and counting real
word tokens (same punctuation-stripping rule as the base text scraper) up to
each <sup>letter</sup> marker -- numeric <sup> markers are walked over (so
they don't throw off the count) but never produce a stored reference.
`fverwijs(N)` correlates a footnote-header row to its <tr id='trN'> expansion;
the footnote-header's <b>label</b> (numeric vs. lettered) is looked up via N
to decide whether to keep it.

Usage:
  python parse_svgbs_cross_references.py            # all books
  python parse_svgbs_cross_references.py --book GEN # single book
  python parse_svgbs_cross_references.py --dry-run  # print, no DB writes
"""
import argparse
import re
import sys
import time

import requests
from bs4 import BeautifulSoup, NavigableString, Tag

from db.loaders import bulk_insert_cross_references

# ─── Constants ────────────────────────────────────────────────────────────────

SOURCE           = "SVGBS"
BASE_URL         = "https://statenvertaling.nl/tekst.php"
REQUEST_DELAY    = 1.2
REQUEST_TIMEOUT  = 30
MAX_RETRIES      = 3
RETRY_DELAY      = 5

# ─── Book catalogue (book_id, usfm_code, chapter_count) ───────────────────────
# bb == book_id directly on this site, identical to parse_statenvertaling_gbs.py.

BOOKS: list[tuple[int, str, int]] = [
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
    (40, "MAT",  28), (41, "MRK",  16), (42, "LUK",  24), (43, "JHN",  21),
    (44, "ACT",  28), (45, "ROM",  16), (46, "1CO",  16), (47, "2CO",  13),
    (48, "GAL",   6), (49, "EPH",   6), (50, "PHP",   4), (51, "COL",   4),
    (52, "1TH",   5), (53, "2TH",   3), (54, "1TI",   6), (55, "2TI",   4),
    (56, "TIT",   3), (57, "PHM",   1), (58, "HEB",  13), (59, "JAS",   5),
    (60, "1PE",   5), (61, "2PE",   3), (62, "1JN",   5), (63, "2JN",   1),
    (64, "3JN",   1), (65, "JUD",   1), (66, "REV",  22),
]

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
    """Fetch a chapter page in the 'Met kanttekeningen' view (ind=1)."""
    url = f"{BASE_URL}?bb={bb}&hf={chapter}&ind=1"
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


# ─── Word-position tracking (mirrors parse_statenvertaling_gbs.py's tokenizer) ─

_PUNCT_EDGE = re.compile(r"^[^\w֐-׿]+|[^\w֐-׿]+$", re.UNICODE)
_LETTER_LABEL = re.compile(r"^[a-z]+$")


def _verse_marker_positions(td: Tag, verse_num: int) -> dict[str, int]:
    """
    Walk the verse-text <td>, returning {letter: word_position} where
    word_position is the count of real words already seen when <sup>letter</sup>
    is encountered. Numeric <sup> markers are walked over (kept out of the
    word count, same as real tokenisation) but never added to the result.
    """
    items: list[tuple[str, str]] = []  # ("WORD", token) or ("MARK", label)

    def walk(node):
        if isinstance(node, NavigableString):
            for m in re.finditer(r"\S+", str(node)):
                items.append(("WORD", m.group()))
        elif isinstance(node, Tag):
            if node.name == "sup":
                items.append(("MARK", node.get_text(strip=True)))
                return
            if node.name == "a":
                return  # verse-number anchor: empty, but skip defensively
            for child in node.children:
                walk(child)

    for child in td.children:
        walk(child)

    # Drop the leading plain verse-number token, e.g. "1" before "IN".
    if items and items[0] == ("WORD", str(verse_num)):
        items = items[1:]

    positions: dict[str, int] = {}
    word_count = 0
    for kind, val in items:
        if kind == "WORD":
            if _PUNCT_EDGE.sub("", val):
                word_count += 1
        elif _LETTER_LABEL.match(val) and val not in positions:
            positions[val] = word_count

    return positions


# ─── Extraction ────────────────────────────────────────────────────────────────

_VERSE_ANCHOR  = re.compile(r"^vers(\d+)$")
_TR_ID         = re.compile(r"^tr(\d+)$")
_FVERWIJS_HREF = re.compile(r"^javascript:fverwijs\((\d+)\)")
_TARGET_HREF   = re.compile(r"bb=(\d+)&hf=(\d+)&ind=\d+#vers(\d+)")


def iter_cross_refs(html: str):
    """Yield (verse, letter, word_position, ordinal, target_book_id, target_chapter, target_verse, label)."""
    soup = BeautifulSoup(html, "lxml")
    table = soup.find("table", class_="tekst")
    if table is None:
        return

    current_verse: int | None = None
    marker_positions: dict[str, int] = {}
    footnote_label_by_n: dict[int, str] = {}

    for tr in table.find_all("tr"):
        td = tr.find("td")

        anchor = tr.find("a", attrs={"name": _VERSE_ANCHOR})
        if anchor and td and "tekstbreed" in (td.get("class") or []):
            m = _VERSE_ANCHOR.match(anchor["name"])
            if m:
                current_verse = int(m.group(1))
                marker_positions = _verse_marker_positions(td, current_verse)
            continue

        # Footnote header row: <b>label</b> ... <a href="javascript:fverwijs(N)">
        b_tag = tr.find("b")
        fverwijs_a = tr.find("a", href=_FVERWIJS_HREF)
        if b_tag and fverwijs_a:
            fm = _FVERWIJS_HREF.match(fverwijs_a["href"])
            if fm:
                footnote_label_by_n[int(fm.group(1))] = b_tag.get_text(strip=True)
            continue

        tr_id = tr.get("id", "")
        trm = _TR_ID.match(tr_id)
        if trm and current_verse is not None:
            n = int(trm.group(1))
            letter = footnote_label_by_n.get(n, "")
            if not _LETTER_LABEL.match(letter):
                continue  # numbered explanatory footnote -- skip

            word_position = marker_positions.get(letter, 0)

            ordinal = 0
            for a in tr.find_all("a", href=re.compile(r"^tekst\.php\?bb=")):
                tm = _TARGET_HREF.search(a.get("href", ""))
                if not tm:
                    continue
                tgt_book_id, tgt_chapter, tgt_verse = int(tm.group(1)), int(tm.group(2)), int(tm.group(3))
                label = a.get_text(strip=True)
                ordinal += 1
                yield current_verse, letter, word_position, ordinal, tgt_book_id, tgt_chapter, tgt_verse, label


# ─── Main scrape loop ─────────────────────────────────────────────────────────

def scrape_book(book_id: int, usfm: str, chapter_count: int, dry_run: bool = False) -> int:
    total_refs = 0
    row_batch: list[dict] = []

    for chapter in range(1, chapter_count + 1):
        html = fetch_chapter_html(book_id, chapter)
        if html is None:
            print(f"    Skipping {usfm} {chapter} (fetch failed)", flush=True)
            time.sleep(REQUEST_DELAY)
            continue

        for verse, letter, word_pos, ordinal, tgt_book_id, tgt_ch, tgt_vs, label in iter_cross_refs(html):
            if dry_run:
                print(f"  {usfm} {chapter}:{verse} [{letter}@{word_pos}.{ordinal}] -> book {tgt_book_id} {tgt_ch}:{tgt_vs} ({label})")
                continue

            row_batch.append({
                "source": SOURCE, "book_id": book_id, "chapter": chapter, "verse": verse,
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


def parse_svgbs_cross_references(only_book: str | None = None, dry_run: bool = False) -> None:
    if only_book:
        only_book = only_book.upper()
        book_list = [(bid, usfm, ch) for bid, usfm, ch in BOOKS if usfm == only_book]
        if not book_list:
            print(f"ERROR: Unknown USFM code '{only_book}'", file=sys.stderr)
            sys.exit(1)
    else:
        book_list = BOOKS

    grand_total = 0
    for book_id, usfm, chapter_count in book_list:
        print(f"  [{usfm}] ({chapter_count} chapters) …", flush=True)
        n = scrape_book(book_id, usfm, chapter_count, dry_run=dry_run)
        grand_total += n
        if not dry_run:
            print(f"    ✓ {n:,} cross-references", flush=True)

    if not dry_run:
        print(f"\n  ✓ Total cross-references inserted: {grand_total:,}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Scrape SV(GBS) lettered cross-references (statenvertaling.nl, ind=1) into cross_references (source='SVGBS')."
    )
    parser.add_argument("--book", metavar="USFM", default=None, help="Process a single book only (e.g. GEN).")
    parser.add_argument("--dry-run", action="store_true", help="Print extracted references; do not write to the database.")
    args = parser.parse_args()

    parse_svgbs_cross_references(only_book=args.book, dry_run=args.dry_run)
