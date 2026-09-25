#!/usr/bin/env python3
"""OCR a page range of a scanned PDF with Tesseract (Latin), one text file
per page, in parallel. Output is cached: pages that already have a text
file are skipped, so a run can be resumed.

Used as the second, independent OCR for sources whose own text layer is
mediocre (the Corpus Reformatorum volumes of Calvin's commentaries -- see
parse_calvin_genesis_la.py, which combines both). Page numbers are 0-based
PDF page indices, as in PyMuPDF.

Usage:
    python scripts/ocr_pdf_pages.py --pdf /data/institutio/raw/calvin_genesis/co23_cr51.pdf \\
        --pages 30-338 --out-dir /data/institutio/raw/calvin_genesis/ocr

Requires: pymupdf, tesseract + tesseract-ocr-lat (installed in the image)
"""
from __future__ import annotations

import argparse
import os
import subprocess
import sys
import tempfile
from concurrent.futures import ProcessPoolExecutor
from pathlib import Path

import pymupdf

DPI = 300


def ocr_page(pdf: str, page: int, out_dir: str) -> tuple[int, str]:
    out = Path(out_dir) / f"p{page:04d}.txt"
    if out.exists():
        return page, "cached"
    with tempfile.TemporaryDirectory() as tmp:
        png = Path(tmp) / "page.png"
        pymupdf.open(pdf)[page].get_pixmap(dpi=DPI).save(png)
        base = Path(tmp) / "out"
        # psm 1: automatic page segmentation with orientation detection --
        # reads the two-column layout column by column.
        # One OpenMP thread per Tesseract: parallelism comes from the worker
        # processes. Without this every Tesseract spins up a thread per core
        # and a full pool grinds to a halt (seen: 12 workers x 12 threads,
        # no page finished in 10 minutes).
        subprocess.run(["tesseract", str(png), str(base), "-l", "lat", "--psm", "1"],
                       check=True, capture_output=True, env={**os.environ, "OMP_THREAD_LIMIT": "1"})
        out.write_text((base.with_suffix(".txt")).read_text(encoding="utf-8"), encoding="utf-8")
    return page, "ok"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--pdf", type=Path, required=True)
    ap.add_argument("--pages", required=True, help="0-based range, e.g. 30-338")
    ap.add_argument("--out-dir", type=Path, required=True)
    ap.add_argument("--workers", type=int, default=os.cpu_count() or 4)
    args = ap.parse_args()

    first, last = (int(x) for x in args.pages.split("-"))
    args.out_dir.mkdir(parents=True, exist_ok=True)
    pages = list(range(first, last + 1))
    done = 0
    with ProcessPoolExecutor(max_workers=args.workers) as pool:
        for page, status in pool.map(ocr_page, [str(args.pdf)] * len(pages), pages,
                                     [str(args.out_dir)] * len(pages)):
            done += 1
            if done % 25 == 0 or done == len(pages):
                print(f"[ocr]   {done}/{len(pages)} (last: page {page}, {status})", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
