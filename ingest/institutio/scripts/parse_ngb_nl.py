#!/usr/bin/env python3
"""Parse the traditional Dutch translation of the Nederlandse Geloofsbelijdenis
(Belgic Confession) into a translation layer (JSONL), aligned to the
already-ingested Latin `segment` rows by article number.

Source: nederlandse-geloofsbelijdenis.pdf -- the classic churchly Dutch
wording ("Wij geloven allen met het hart...") used across Dutch Reformed
churches for centuries, not a modern paraphrase. Same situation as the
Heidelberg Catechism's 'denheijer' layer (see parse_hc_nl_denheijer.py):
the underlying translation text is centuries old and in the public domain.

The PDF has a real text layer (no OCR needed) and, unlike the HC PDF, no
repeating page header/footer to strip. It does carry translator footnotes
(archaic-word glosses, e.g. "* politie betekent hier regering.") both as
inline "*" reference marks and as their own explanatory lines -- these are
editorial asides, not part of the confessional text, and are stripped.

Each article body in the PDF is preceded by a Dutch title (e.g. "Dat er een
enig GOD is."), same as segment.heading already holds the Latin title
separately from text_la -- so the Dutch title is likewise split off and
discarded here (not stored anywhere yet) rather than folded into text_nl,
keeping the two languages structurally parallel.

Usage:
    python scripts/parse_ngb_nl.py --pdf "path/to/nederlandse-geloofsbelijdenis.pdf" -o ngb_nl.jsonl
    python scripts/parse_ngb_nl.py --pdf ... --dry-run

Requires: pymupdf
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

import fitz  # pymupdf

LAYER = "traditioneel"
MODEL = "manual-transcription"

_ARTICLE_RE = re.compile(r"Artikel (\d+)\.\s*")
# Footnote blocks: a line starting with "*" (an explanatory gloss), running
# until the next article heading or the next footnote line.
_FOOTNOTE_BLOCK_RE = re.compile(
    r"^\*.*?(?=(?:^Artikel \d+\.)|(?:^\*)|\Z)", re.DOTALL | re.MULTILINE
)
# The article title runs from just after "Artikel N." to the first
# period-then-newline -- long titles (e.g. article 19, 23) wrap across two
# PDF lines before that period, so a plain first-newline split is not
# enough.
_TITLE_END_RE = re.compile(r"\.\s*\n")


def parse(pdf_path: Path) -> list[dict]:
    doc = fitz.open(pdf_path)
    full_text = "".join(page.get_text() for page in doc)

    full_text = _FOOTNOTE_BLOCK_RE.sub("", full_text)
    full_text = full_text.replace("*", "")  # remaining inline reference marks

    # Drop the table of contents: it repeats "Artikel N <title>" without the
    # period the body headings use, so it never matches _ARTICLE_RE and is
    # simply skipped by starting the split from the first real match.
    parts = _ARTICLE_RE.split(full_text)
    # parts[0] is whatever precedes the first body heading (the ToC);
    # then alternating (article_number, body_text).
    rows: list[dict] = []
    for i in range(1, len(parts), 2):
        number = int(parts[i])
        raw = parts[i + 1]
        title_match = _TITLE_END_RE.search(raw)
        body = raw[title_match.end():] if title_match else raw
        body = re.sub(r"[ \t]+", " ", body).strip()
        body = re.sub(r"\s*\n\s*", " ", body)
        rows.append({"ref": f"NGB {number}", "layer": LAYER, "text": body, "model": MODEL})

    rows.sort(key=lambda r: int(r["ref"].split()[1]))
    return rows


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--pdf", type=Path, required=True)
    ap.add_argument("-o", "--output", type=Path, default=Path("ngb_nl.jsonl"))
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    rows = parse(args.pdf)
    numbers = [int(r["ref"].split()[1]) for r in rows]
    print(f"[parse] {len(rows)} articles (expect 37)")
    if numbers != list(range(1, len(numbers) + 1)):
        print(f"[warn]  article numbers not a clean 1..N sequence: "
              f"missing {sorted(set(range(1, 38)) - set(numbers))}")

    if args.dry_run:
        for r in rows:
            print(f"  {r['ref']:<8} len={len(r['text'])}")
        return 0

    with args.output.open("w", encoding="utf-8") as f:
        for r in rows:
            f.write(json.dumps(r, ensure_ascii=False) + "\n")
    print(f"[ok]    wrote {args.output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
