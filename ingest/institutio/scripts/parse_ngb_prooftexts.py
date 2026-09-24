#!/usr/bin/env python3
"""Parse the Scripture proof-text references ("bewijsteksten") of the
Nederlandse Geloofsbelijdenis from nederlandse-geloofsbelijdenis.nl into the
same JSONL format as parse_hc_prooftexts_cgk.py, for load_hc_prooftexts.py.

Source: https://www.nederlandse-geloofsbelijdenis.nl/artikel-N (N = 1..37):
the traditional Dutch text with lettered markers
(<a class="verwijzing a" data-verwijstekst="1-a">a</a>) and, per letter, its
references as structured links (...&bijbelboek=45&hoofdstuk=10&vers_start=10
[&vers_einde=12]; bijbelboek in the standard 1..66 book order, the same as
this app's books.id). Only the references and where each letter sits are
taken over -- not the site's written-out Bible text (copyrighted; the app
shows its own HSV/SV text for every reference), nor its modern-language
version. 263 letters, 739 references over the 37 articles (2026-09).

The pages are fetched once, politely (one request per second, article pages
only -- robots.txt disallows just /zoeken and /tekst-downloaden), and cached
under --cache-dir; later runs parse the cache.

Anchoring works exactly as for the HC (see parse_hc_prooftexts_cgk.py):
each letter gets the normalized words of our own NGB text (translation layer
'traditioneel') right before it, found by aligning the site's text against
ours.

Usage:
    python scripts/parse_ngb_prooftexts.py -o /data/institutio/ngb_prooftexts.jsonl
    python scripts/parse_ngb_prooftexts.py --cache-dir local/ngbsite --traditioneel-jsonl ngb.jsonl --dry-run

Requires: requests (fetching), psycopg (unless --traditioneel-jsonl)
"""
from __future__ import annotations

import argparse
import html
import json
import re
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from parse_hc_prooftexts_cgk import compute_anchors  # noqa: E402

BASE_URL = "https://www.nederlandse-geloofsbelijdenis.nl/artikel-{n}"
CACHE_DIR = Path("/data/institutio/raw/ngb_site")
SOURCE = "nederlandse-geloofsbelijdenis.nl"
WORK_SLUG = "ngb"
LAYER = "traditioneel"
ARTICLES = range(1, 38)

# Standard 1..66 book order (= books.id in this app) => USFM code.
USFM = [
    "GEN", "EXO", "LEV", "NUM", "DEU", "JOS", "JDG", "RUT", "1SA", "2SA", "1KI", "2KI", "1CH", "2CH",
    "EZR", "NEH", "EST", "JOB", "PSA", "PRO", "ECC", "SNG", "ISA", "JER", "LAM", "EZK", "DAN", "HOS",
    "JOL", "AMO", "OBA", "JON", "MIC", "NAM", "HAB", "ZEP", "HAG", "ZEC", "MAL",
    "MAT", "MRK", "LUK", "JHN", "ACT", "ROM", "1CO", "2CO", "GAL", "EPH", "PHP", "COL", "1TH", "2TH",
    "1TI", "2TI", "TIT", "PHM", "HEB", "JAS", "1PE", "2PE", "1JN", "2JN", "3JN", "JUD", "REV",
]

_CONTENT_RE = re.compile(r'<div class="belijdenis_inhoud">(.*?)</div>', re.DOTALL)
_MARKER_RE = re.compile(r'<a [^>]*class="verwijzing ([a-z]+)"[^>]*>.*?</a>', re.DOTALL)
_BLOCK_RE = re.compile(r'<div class="blok_bewijsteksten" data-referentie="([a-z]+)"\s*>(.*?)</div>', re.DOTALL)
_LOCATION_RE = re.compile(r'<a href="[^"]*type=bewijstekst([^"]*)"[^>]*class="locatie"[^>]*>(.*?)</a>', re.DOTALL)


def fetch(cache_dir: Path) -> None:
    import requests
    cache_dir.mkdir(parents=True, exist_ok=True)
    for n in ARTICLES:
        path = cache_dir / f"artikel-{n}.html"
        if path.exists() and path.stat().st_size > 1000:
            continue
        url = BASE_URL.format(n=n)
        print(f"[fetch] {url}")
        resp = requests.get(url, headers={"User-Agent": "Mozilla/5.0"}, timeout=30)
        resp.raise_for_status()
        path.write_bytes(resp.content)
        time.sleep(1)


def _plain(fragment: str) -> str:
    text = re.sub(r"<br\s*/?>", " ", fragment)
    text = re.sub(r"<[^>]+>", "", text)
    return re.sub(r"\s+", " ", html.unescape(text)).strip()


def parse_article(page: str) -> tuple[str, list[dict]]:
    """Returns (article text with "(x)" markers, [{glyph, refs, refs_text}])."""
    content = _CONTENT_RE.search(page)
    if content is None:
        raise ValueError("article text not found")
    text = _plain(_MARKER_RE.sub(lambda m: f" ({m.group(1)}) ", content.group(1)))
    text = re.sub(r"\s+\(", " (", text)

    glyphs = []
    for block in _BLOCK_RE.finditer(page):
        refs = []
        for loc in _LOCATION_RE.finditer(block.group(2)):
            q = dict(re.findall(r"&(\w+)=(\d*)", html.unescape(loc.group(1))))
            book = int(q["bijbelboek"])
            start = int(q["vers_start"])
            end = int(q["vers_einde"]) if q.get("vers_einde") else start
            refs.append({"usfm": USFM[book - 1], "chapter": int(q["hoofdstuk"]),
                         "verse_start": start, "verse_end": max(start, end), "label": _plain(loc.group(2))})
        glyphs.append({"glyph": block.group(1), "refs": refs,
                       "refs_text": "; ".join(r["label"] for r in refs)})
    return text, glyphs


def load_layer(jsonl: Path | None) -> dict[int, str]:
    if jsonl is not None:
        rows = [json.loads(line) for line in jsonl.read_text(encoding="utf-8").splitlines() if line.strip()]
        return {int(r["ref"].split()[1]): r["text"] for r in rows}
    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
    from db import get_connection
    with get_connection() as conn, conn.cursor() as cur:
        cur.execute(
            """SELECT s.ref, t.text_nl FROM translation t
               JOIN segment s ON s.id = t.segment_id
               JOIN work w ON w.id = s.work_id
               WHERE w.slug = %s AND t.layer = %s""", (WORK_SLUG, LAYER))
        return {int(ref.split()[1]): text for ref, text in cur.fetchall() if re.fullmatch(r"NGB \d+", ref)}


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--cache-dir", type=Path, default=CACHE_DIR)
    ap.add_argument("--traditioneel-jsonl", type=Path, default=None)
    ap.add_argument("-o", "--output", type=Path)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    fetch(args.cache_dir)
    ours = load_layer(args.traditioneel_jsonl)

    rows: list[dict] = []
    warnings: list[str] = []
    for n in ARTICLES:
        page = (args.cache_dir / f"artikel-{n}.html").read_text(encoding="utf-8")
        site_text, glyphs = parse_article(page)
        # Single letters only: the text itself has "(het)" in article 3.
        markers = re.findall(r"\(([a-z])\)", site_text)
        if markers != [g["glyph"] for g in glyphs]:
            warnings.append(f"NGB {n}: letters in text {markers} vs list {[g['glyph'] for g in glyphs]}")
        if n not in ours:
            warnings.append(f"NGB {n}: no '{LAYER}' text -- letters not anchored")
        anchors = compute_anchors(site_text, ours[n]) if n in ours else {}
        for ordinal, g in enumerate(glyphs, start=1):
            if not g["refs"]:
                warnings.append(f"NGB {n} ({g['glyph']}): no references")
            rows.append({"ref": f"NGB {n}", "source": SOURCE, "glyph": g["glyph"], "ordinal": ordinal,
                         "anchors": {LAYER: anchors.get(g["glyph"])},
                         "refs_text": g["refs_text"], "refs": g["refs"]})

    for w in warnings:
        print(f"[warn]  {w}")
    unanchored = [f"{r['ref'].split()[1]}{r['glyph']}" for r in rows if r["anchors"][LAYER] is None]
    print(f"[ok]    {len(rows)} letters, {sum(len(r['refs']) for r in rows)} references over {len(ARTICLES)} articles; "
          f"{len(rows) - len(unanchored)}/{len(rows)} anchored in '{LAYER}'"
          + (f"; listed only: {', '.join(unanchored)}" if unanchored else ""))

    if args.dry_run:
        return 0
    if args.output is None:
        ap.error("-o/--output is required unless --dry-run")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8") as fh:
        for r in rows:
            fh.write(json.dumps(r, ensure_ascii=False) + "\n")
    print(f"[ok]    wrote {args.output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
