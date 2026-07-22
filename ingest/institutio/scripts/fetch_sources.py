"""
fetch_sources.py
Downloads Calvin's Institutio christianae religionis (1559, Latin) from
calvin.reformation.nl (Barth/Niesel critical edition, Kampen Theological
University).

Earlier versions of this pipeline used CCEL's ThML files
(ccel.org/ccel/c/calvin/institutio1.xml / institutio2.xml), but those turned
out to contain only page-scan image placeholders, no actual transcribed
Latin text (verified by inspecting the raw XML: every <p> is an empty
<img alt="Missing page-scan">, zero occurrences of real Latin words).
calvin.reformation.nl's parallel-edition viewer does have the running Latin
text, and — unlike what a login prompt in the browser might suggest — it is
served in the plain (unauthenticated) HTML response, no account needed.

Each chapter lives at a URL like:
    {BASE_URL}/{book}/{chapter}
where book -1 is the front matter (the dedicatory letter to Francis I) and
books 1-4 are the four books of the Institutio, with chapter counts fixed
by the canonical 1559 structure (18, 17, 25, 20 -> 80 total, matching the
project dossier). Pages past the last chapter of a book come back with no
Latin content, which fetch() also treats as "stop this book" as a safety
net independent of the hardcoded counts.

Caches each chapter's raw HTML in /data/institutio/raw/pages/{book}_{chapter}.html
so re-running only fetches what's missing.

Usage:
    python scripts/fetch_sources.py
"""
from __future__ import annotations

import sys
import time
from pathlib import Path

import requests

BASE_URL = (
    "https://calvin.reformation.nl/872-Institutes+of+the+Christian+Religion."
    "+Institutio+christianae+religionis.+Institution+de+la+religion+chrestienne"
)

# (book_id, chapter_count) - book -1 is front matter (dedicatory letter).
BOOKS: list[tuple[int, int]] = [
    (-1, 1),
    (1, 18),
    (2, 17),
    (3, 25),
    (4, 20),
]

RAW_DIR = Path("/data/institutio/raw/pages")
REQUEST_DELAY = 1.5    # seconds between requests - be polite to a small academic site
REQUEST_TIMEOUT = 30
MAX_RETRIES = 3
RETRY_DELAY = 5

_session = requests.Session()
_session.headers.update({
    "User-Agent": (
        "Mozilla/5.0 (compatible; InstitutioPipelineIngest/1.0; "
        "research use, translators-tags project)"
    ),
})


def fetch_page(book: int, chapter: int) -> str | None:
    """Fetch one chapter page, with caching and retries. Returns None on failure."""
    dest = RAW_DIR / f"{book}_{chapter}.html"
    if dest.exists() and dest.stat().st_size > 0:
        return dest.read_text(encoding="utf-8")

    url = f"{BASE_URL}/{book}/{chapter}"
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            resp = _session.get(url, timeout=REQUEST_TIMEOUT)
            resp.raise_for_status()
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_text(resp.text, encoding="utf-8")
            return resp.text
        except requests.RequestException as exc:
            if attempt < MAX_RETRIES:
                print(f"    warning: {url} attempt {attempt} failed: {exc}; "
                      f"retrying in {RETRY_DELAY}s", flush=True)
                time.sleep(RETRY_DELAY)
            else:
                print(f"    error: {url} all {MAX_RETRIES} attempts failed: {exc}",
                      flush=True)
                return None


def fetch_all() -> None:
    RAW_DIR.mkdir(parents=True, exist_ok=True)
    total = sum(count for _, count in BOOKS)
    done = 0
    for book, chapter_count in BOOKS:
        for chapter in range(1, chapter_count + 1):
            html = fetch_page(book, chapter)
            done += 1
            if html is None:
                print(f"  [skip]  book {book} chapter {chapter}: fetch failed")
            elif "lang='la'" not in html and 'lang="la"' not in html:
                print(f"  [empty] book {book} chapter {chapter}: no Latin content found")
            time.sleep(REQUEST_DELAY)
        print(f"  [..]    book {book}: {chapter_count} chapters ({done}/{total} total)")
    print(f"\nDone. Pages cached in {RAW_DIR}")


def main() -> int:
    fetch_all()
    return 0


if __name__ == "__main__":
    sys.exit(main())
