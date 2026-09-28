#!/usr/bin/env python3
"""Fetch full-resolution page images of Los's Dutch Genesis commentary --
only the pages that hold a comment whose Latin has Hebrew in it -- and OCR
them with Tesseract nld+heb, for parse_calvin_genesis_nl.py's Hebrew step.

Why: the archive.org scan PDFs (fetch_calvin_genesis.py) are downsampled to
~1611x2677 px, at which Tesseract can't find Los's pointed Hebrew (Gen 1:1:
"2%'", "N93"); the full-resolution page images (2684x4461) it can
("'צךָ", "בָרָא" -- letters still poor, but the spot is found, which is all
this step needs). Fetching every page (~730 MB) isn't worth it: the Hebrew
letters come from the Latin anyway, so only pages with a Latin Hebrew word
to place are fetched (~0.9 MB each).

Pages are found through archive.org's per-page OCR (_djvu.xml): the pages
on which a matched Dutch comment's text lies (start, end, and every ~60
words in between).

Output: <raw>/los_ocr_hires{1,2}/pNNNN.txt, NNNN = 0-based page index, the
same numbering as los_ocr{1,2} (the PDF pass) -- the parser prefers these.

Usage:
    python scripts/fetch_los_hires_pages.py --latin /data/institutio/calvin_genesis_la.jsonl \\
        --dutch /data/institutio/calvin_genesis_nl.jsonl

Requires: requests, tesseract (nld, heb)
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import time
from concurrent.futures import ProcessPoolExecutor
from pathlib import Path

import requests

RAW = Path("/data/institutio/raw/calvin_genesis")
VOLUMES = {1: "genesisuitleggin01calv", 2: "genesisuitleggin02calv"}
_HEB_RE = re.compile("[א-ת]")
_WORD_RE = re.compile(r"[a-zà-ÿ]+")


def get(url: str, dest: Path) -> None:
    for attempt in range(1, 5):
        try:
            with requests.get(url, headers={"User-Agent": "Mozilla/5.0"}, timeout=120) as resp:
                resp.raise_for_status()
                dest.write_bytes(resp.content)
                return
        except requests.RequestException as exc:
            print(f"[warn]  {url}: {exc}")
            if attempt == 4:
                raise
            time.sleep(15 * attempt)


def page_words(djvu_xml: str) -> list[list[str]]:
    pages = []
    for obj in djvu_xml.split("<OBJECT ")[1:]:
        text = " ".join(re.findall(r">([^<]+)</WORD>", obj))
        pages.append(_WORD_RE.findall(text.lower()))
    return pages


WORDS_PER_PAGE = 300  # a full page of Los holds ~330-350 words


def find_pages(words: list[str], pages: list[list[str]]) -> set[int]:
    """The pages a comment's text lies on: the page of its first 5-word
    anchor that is found, then only as many pages after it as the comment's
    length allows -- a 5-word phrase can recur elsewhere in the book, and
    searching the whole book for every anchor let one stray hit stretch a
    comment over 100+ pages."""
    joined = [" " + " ".join(p) + " " for p in pages]
    anchors = [words[i:i + 5] for i in list(range(0, max(1, len(words) - 5), 60)) + [max(0, len(words) - 6)]]
    anchors = [" " + " ".join(a) + " " for a in anchors if len(a) == 5]
    start = None
    for needle in anchors:
        start = next((i for i, page in enumerate(joined) if needle in page), None)
        if start is not None:
            break
    if start is None:
        return set()
    span = len(words) // WORDS_PER_PAGE + 2
    last = start
    for needle in anchors:
        hit = next((i for i in range(start, min(len(joined), start + span)) if needle in joined[i]), None)
        if hit is not None:
            last = max(last, hit)
    return set(range(start, last + 1))


def ocr(job: tuple[str, str]) -> str:
    image, out = job
    result = subprocess.run(["tesseract", image, "-", "-l", "nld+heb", "--psm", "3"],
                            capture_output=True, text=True, env={**os.environ, "OMP_THREAD_LIMIT": "1"})
    Path(out).write_text(result.stdout, encoding="utf-8")
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--raw-dir", type=Path, default=RAW)
    ap.add_argument("--latin", type=Path, required=True)
    ap.add_argument("--dutch", type=Path, required=True)
    args = ap.parse_args()

    read = lambda p: {json.loads(l)["ref"]: json.loads(l) for l in p.read_text(encoding="utf-8").splitlines() if l.strip()}
    latin, dutch = read(args.latin), read(args.dutch)
    wanted = [ref for ref, r in latin.items() if _HEB_RE.search(r["text"]) and ref in dutch]
    print(f"[pages] {len(wanted)} Dutch comments whose Latin has Hebrew")

    volume_pages = {}
    for n, ident in VOLUMES.items():
        xml = args.raw_dir / f"los1900_deel{n}_djvu.xml"
        if not xml.exists():
            print(f"[fetch] {ident}_djvu.xml")
            get(f"https://archive.org/download/{ident}/{ident}_djvu.xml", xml)
        volume_pages[n] = page_words(xml.read_text(encoding="utf-8", errors="replace"))

    todo: dict[int, set[int]] = {1: set(), 2: set()}
    not_found = []
    for ref in wanted:
        words = _WORD_RE.findall(dutch[ref]["text"].lower())
        hit = False
        for n in (1, 2):
            pages = find_pages(words, volume_pages[n])
            if pages:
                todo[n] |= pages
                hit = True
                break
        if not hit:
            not_found.append(ref)
    if not_found:
        print(f"[warn]  pages not found for: {', '.join(not_found)}")
    print(f"[pages] volume 1: {len(todo[1])} pages, volume 2: {len(todo[2])} pages")

    jobs = []
    for n, pages in todo.items():
        img_dir = args.raw_dir / f"los_hires{n}"
        out_dir = args.raw_dir / f"los_ocr_hires{n}"
        img_dir.mkdir(parents=True, exist_ok=True)
        out_dir.mkdir(parents=True, exist_ok=True)
        for p in sorted(pages):
            image = img_dir / f"p{p:04d}.jpg"
            if not image.exists():
                get(f"https://archive.org/download/{VOLUMES[n]}/page/n{p}.jpg", image)
                time.sleep(1)
            out = out_dir / f"p{p:04d}.txt"
            if not out.exists():
                jobs.append((str(image), str(out)))
    print(f"[ocr]   {len(jobs)} pages to OCR")
    with ProcessPoolExecutor(max_workers=os.cpu_count() or 4) as pool:
        for _ in pool.map(ocr, jobs):
            pass
    print("[ok]    done")
    return 0


if __name__ == "__main__":
    sys.exit(main())
