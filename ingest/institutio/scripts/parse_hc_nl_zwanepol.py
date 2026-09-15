#!/usr/bin/env python3
"""Parse the Zwanepol-HSV Dutch translation of the Heidelberg Catechism
into a translation layer (JSONL), aligned to the already-ingested Latin
`segment` rows by official question number.

Source: "Belijdenisgeschriften editie Zwanepol-HSV" as hosted at
https://herzienestatenvertaling.nl/teksten/belijdenisgeschriften%20editie%20zwanepol-hsv%201/1
-- Dr. Klaas Zwanepol's modern-Dutch translation, published by
Boekencentrum/Protestantse Pers (2004, rev. 2009) as "Belijdenisgeschriften
voor de Protestantse Kerk in Nederland". This is an actively copyrighted,
commercially published contemporary work -- unlike the 'denheijer' layer
(a centuries-old public-domain translation), this text is used here only
because the user stated they have obtained permission for this reuse; see
the ingest commit/PR for that context before reusing this script's output
elsewhere.

The page is a single flat text with "ZONDAG N" and "Vraag N: ...\n\nAntwoord:
..." markers -- no per-question HTML structure to rely on, so this parses
the plain extracted text directly (see fetch step in the ingest notes:
the page content was pulled via a JS `document.querySelector('main').innerText`
capture, not a static HTML fetch, since the site is a JS-rendered app).

Usage:
    python scripts/parse_hc_nl_zwanepol.py --input hsv_zwanepol_raw.txt -o hc_nl_zwanepol.jsonl
    python scripts/parse_hc_nl_zwanepol.py --input ... --dry-run
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

LAYER = "zwanepol-hsv"
MODEL = "manual-transcription"

_QA_SPLIT_RE = re.compile(r"Vraag (\d+):\s*(.*?)\n\nAntwoord:\s*(.*?)(?=\n\nVraag \d+:|\Z)", re.DOTALL)


def parse(text: str) -> list[dict]:
    rows: list[dict] = []
    for m in _QA_SPLIT_RE.finditer(text):
        number = int(m.group(1))
        question = re.sub(r"\s+", " ", m.group(2)).strip()
        answer = re.sub(r"\s+", " ", m.group(3)).strip()
        combined = f"{question} {answer}".strip()
        rows.append({"ref": f"HC {number}", "layer": LAYER, "text": combined, "model": MODEL})

    rows.sort(key=lambda r: int(r["ref"].split()[1]))
    return rows


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--input", type=Path, required=True, help="plain-text capture of the source page")
    ap.add_argument("-o", "--output", type=Path, default=Path("hc_nl_zwanepol.jsonl"))
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    text = args.input.read_text(encoding="utf-8")
    rows = parse(text)
    numbers = [int(r["ref"].split()[1]) for r in rows]
    print(f"[parse] {len(rows)} questions (expect 129)")
    if numbers != list(range(1, len(numbers) + 1)):
        print(f"[warn]  question numbers not a clean 1..N sequence: "
              f"missing {sorted(set(range(1, 130)) - set(numbers))}")

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
