#!/usr/bin/env python3
"""Parse the traditional Dutch translation of the Heidelberg Catechism into a
translation layer (JSONL), aligned to the already-ingested Latin `segment`
rows by official question number.

Source: a plain-text transcription (Martin den Heijer, 2020, Word/PDF) of
the traditional Dutch rendering of the Heidelberg Catechism -- the classic
churchly wording ("Wat is uw enige troost...") used across Dutch Reformed
churches for centuries, not the site-hosted modern paraphrase at
heidelbergse-catechismus.nl (which carries an explicit copyright notice
limiting reuse to <=30% of a work -- see PROJECTDOSSIER.md). The underlying
translation text itself is centuries old and in the public domain; this
2020 PDF is a plain mechanical transcription of it, not a new creative
work.

Deliberately does NOT reuse the "translation" table's 'llm' layer
(reserved for LLM-generated Dutch, per db/migrate_add_institutio_schema.sql)
-- this is a manual/historical layer, same pattern as the Institutio's own
'weijenberg1865' layer for its 1865 Dutch translation.

The PDF has a real (Word-generated) text layer, confirmed 129/129 unique
"Vraag en antwoord N" markers with no gaps -- no OCR needed.

Usage:
    python scripts/parse_hc_nl_denheijer.py --pdf "path/to/Heidelbergse-Catechismus-denheijer.pdf" -o hc_nl_denheijer.jsonl
    python scripts/parse_hc_nl_denheijer.py --pdf ... --dry-run

Requires: pymupdf
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

import fitz  # pymupdf

LAYER = "denheijer"
MODEL = "manual-transcription"

_HEADER_RE = re.compile(r"HEIDELBERGSE CATECHISMUS\s*\nPagina \d+ van \d+\s*\n?")
_QA_SPLIT_RE = re.compile(r"Vraag en antwoord (\d+)\s*\n")


def parse(pdf_path: Path) -> list[dict]:
    doc = fitz.open(pdf_path)
    full_text = "".join(page.get_text() for page in doc)
    full_text = _HEADER_RE.sub("", full_text)

    parts = _QA_SPLIT_RE.split(full_text)
    # parts[0] is the front-matter table of contents (discarded); then
    # alternating (question_number, body_text).
    rows: list[dict] = []
    for i in range(1, len(parts), 2):
        number = int(parts[i])
        body = re.sub(r"[ \t]+", " ", parts[i + 1]).strip()
        body = re.sub(r"\s*\n\s*", " ", body)
        rows.append({"ref": f"HC {number}", "layer": LAYER, "text": body, "model": MODEL})

    rows.sort(key=lambda r: int(r["ref"].split()[1]))
    return rows


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--pdf", type=Path, required=True)
    ap.add_argument("-o", "--output", type=Path, default=Path("hc_nl_denheijer.jsonl"))
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    rows = parse(args.pdf)
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
