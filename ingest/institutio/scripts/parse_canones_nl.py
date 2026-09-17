#!/usr/bin/env python3
"""Parse the traditional Dutch translation of the Canones Synodi Dordrechtanae
(Canons of Dort) into a translation layer (JSONL), aligned to the
already-ingested Latin `segment` rows by ref.

Source: Dordtse-Leerregels-klassieke-versie.pdf -- the classic churchly Dutch
wording used across Dutch Reformed churches for centuries, not a modern
paraphrase. Same situation as the NGB's 'traditioneel' layer (see
parse_ngb_nl.py): the underlying translation text is centuries old and in
the public domain.

Structure mirrors parse_canones.py's Latin ingestion exactly (same four
chapter headings -- "Eerste"/"Tweede"/"Derde en Vierde"/"Vijfde Hoofdstuk
der Leer" -- each followed by numbered "Artikel" paragraphs then that
chapter's own numbered "Verwerping der Dwalingen" paragraphs, plus a
Voorrede and Besluit), so segment refs line up one-to-one with the Latin
work except for one gap: Canones voorwoord.1 is a standalone Latin
dedication line ("In nomine Domini... Amen.") that has no counterpart
in this Dutch source -- left unmatched (see load_canones_nl.py) rather
than invented.

The Voorrede and Besluit have no numbered markers in the source (unlike
the chapters' "Artikel"/"Verwerping" paragraphs, which are), and their
paragraph breaks in this PDF don't line up with the Latin segment
boundaries -- so their text is split on fixed anchor phrases instead,
matching the Latin segments' actual boundaries word-for-word.

Usage:
    python scripts/parse_canones_nl.py --pdf "path/to/Dordtse-Leerregels-klassieke-versie.pdf" -o canones_nl.jsonl
    python scripts/parse_canones_nl.py --pdf ... --dry-run

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

_CHAPTER_RE = re.compile(r"^HET (EERSTE|TWEEDE|DERDE EN VIERDE|VIJFDE) HOOFDSTUK DER LEER\s*$")
_REJECTION_RE = re.compile(r"^VERWERPING DER DWALINGEN\b")
_VOORREDE_RE = re.compile(r"^VOORREDE\s*$")
_BESLUIT_RE = re.compile(r"^BESLUIT\s*$")
_NUMBER_RE = re.compile(r"^(\d+)\s*$")

# Voorrede: split at the start of each Latin segment's Dutch counterpart.
# voorwoord.1 (the Latin dedication line) has no match here -- skipped.
_VOORREDE_SPLITS = [
    ("Canones voorwoord.2", "Onder zeer vele vertroostingen"),
    ("Canones voorwoord.3", "Met een gelijke weldaad"),
    ("Canones voorwoord.4", "Deze eerwaardige Synode"),
]

# Besluit: same idea -- these anchors don't match the PDF's own visual
# paragraph breaks, but do match the Latin segments' real boundaries.
_BESLUIT_SPLITS = [
    ("Canones besluit.1", "En dit is de naakte"),
    ("Canones besluit.2", '"dat de leer der Gereformeerde Kerken'),
    ("Canones besluit.3", "en wat dergelijke andere dingen"),
    ("Canones besluit.4", "Ten laatste vermaant deze Synode"),
]


def _split_by_anchors(text: str, splits: list[tuple[str, str]]) -> list[tuple[str, str]]:
    positions = []
    for ref, anchor in splits:
        idx = text.find(anchor)
        if idx == -1:
            raise ValueError(f"anchor not found for {ref}: {anchor!r}")
        positions.append((idx, ref))
    positions.sort()
    rows = []
    for i, (idx, ref) in enumerate(positions):
        end = positions[i + 1][0] if i + 1 < len(positions) else len(text)
        rows.append((ref, text[idx:end].strip()))
    return rows


def parse(pdf_path: Path) -> list[dict]:
    doc = fitz.open(pdf_path)
    full_text = "".join(page.get_text() for page in doc)
    lines = [ln.strip() for ln in full_text.split("\n")]

    rows: list[dict] = []
    mode: str | None = None       # None | 'voorwoord' | 'chapter' | 'besluit'
    chapter_num = 0
    kind: str | None = None       # 'article' | 'rejection'
    counters: dict[tuple, int] = {}
    pending_number: int | None = None
    buf: list[str] = []
    section_buf: list[str] = []   # accumulates raw text for voorwoord/besluit

    def flush_numbered():
        nonlocal pending_number, buf
        if pending_number is not None and buf:
            key = (chapter_num, kind)
            counters[key] = counters.get(key, 0) + 1
            section = counters[key]
            ref = (f"Canones {chapter_num}.{section}" if kind == "article"
                   else f"Canones {chapter_num}.verwerping.{section}")
            text = re.sub(r"\s+", " ", " ".join(buf)).strip()
            rows.append({"ref": ref, "layer": LAYER, "text": text, "model": MODEL})
        pending_number = None
        buf = []

    for line in lines:
        if not line:
            continue

        m = _CHAPTER_RE.match(line)
        if m:
            flush_numbered()
            chapter_num += 1
            kind = "article"
            mode = "chapter"
            continue

        if _REJECTION_RE.match(line):
            flush_numbered()
            kind = "rejection"
            continue

        if _VOORREDE_RE.match(line):
            flush_numbered()
            mode = "voorwoord"
            section_buf = []
            continue

        if _BESLUIT_RE.match(line):
            flush_numbered()
            for ref, text in _split_by_anchors(" ".join(section_buf), _VOORREDE_SPLITS):
                text = re.sub(r"\s+", " ", text).strip()
                rows.append({"ref": ref, "layer": LAYER, "text": text, "model": MODEL})
            mode = "besluit"
            section_buf = []
            continue

        if mode == "chapter":
            nm = _NUMBER_RE.match(line)
            if nm:
                flush_numbered()
                pending_number = int(nm.group(1))
                continue
            if pending_number is None:
                continue  # chapter subtitle / rejection intro sentence
            buf.append(line)
            continue

        if mode in ("voorwoord", "besluit"):
            section_buf.append(line)
            continue

    # end of document: flush the Besluit section
    for ref, text in _split_by_anchors(" ".join(section_buf), _BESLUIT_SPLITS):
        text = re.sub(r"\s+", " ", text).strip()
        rows.append({"ref": ref, "layer": LAYER, "text": text, "model": MODEL})

    return rows


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--pdf", type=Path, required=True)
    ap.add_argument("-o", "--output", type=Path, default=Path("canones_nl.jsonl"))
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    rows = parse(args.pdf)
    n_articles = sum(1 for r in rows if ".verwerping." not in r["ref"] and "voorwoord" not in r["ref"] and "besluit" not in r["ref"])
    n_rejections = sum(1 for r in rows if ".verwerping." in r["ref"])
    n_other = len(rows) - n_articles - n_rejections
    print(f"[parse] {len(rows)} rows: {n_articles} articles, {n_rejections} rejections, {n_other} voorwoord/besluit")

    if args.dry_run:
        for r in rows:
            print(f"  {r['ref']:<28} len={len(r['text'])}")
        return 0

    with args.output.open("w", encoding="utf-8") as f:
        for r in rows:
            f.write(json.dumps(r, ensure_ascii=False) + "\n")
    print(f"[ok]    wrote {args.output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
