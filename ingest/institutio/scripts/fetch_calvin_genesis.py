#!/usr/bin/env python3
"""Fetch the sources for the Calvin Genesis-commentary pilot (a second
Calvin work next to the Institutio):

  - Latin: Calvini Opera vol. 23 (= Corpus Reformatorum vol. 51, Baum/
    Cunitz/Reuss, Brunswick 1882), "Commentarius in Genesin", as the open-
    access PDF from the University of Geneva (archive-ouverte.unige.ch/
    unige:650, file bf_433x_51.pdf -- the file names there are CR volume
    numbers; CO = CR - 28). The PDF has an OCR text layer of mixed quality;
    ocr_pdf_pages.py adds a second, independent OCR and
    parse_calvin_genesis_la.py combines the two.
  - Dutch: "Genesis. Uitlegging van Johannes Calvijn", translated from the
    Latin by S.O. Los (1871-1944), introduction by H. Bavinck (1854-1921),
    Middelburg: K. le Cointre, 1900 -- public domain. Two volumes on
    archive.org (Princeton Theological Seminary Library scans) with their
    OCR text (_djvu.txt), which is of good quality, and their scan PDFs
    (for a second OCR pass that finds the Hebrew quotations).

Everything is cached under --out-dir; existing files are not re-fetched.

Usage:
    python scripts/fetch_calvin_genesis.py

Requires: requests
"""
from __future__ import annotations

import argparse
import sys
import time
from pathlib import Path

import requests

OUT_DIR = Path("/data/institutio/raw/calvin_genesis")
SOURCES = {
    "co23_cr51.pdf":
        "https://access.archive-ouverte.unige.ch/access/metadata/8457e60a-8151-4bed-b197-8ae8eec8d702/download",
    "los1900_deel1.txt":
        "https://archive.org/download/genesisuitleggin01calv/genesisuitleggin01calv_djvu.txt",
    "los1900_deel2.txt":
        "https://archive.org/download/genesisuitleggin02calv/genesisuitleggin02calv_djvu.txt",
    # The scans themselves (~120 MB each), for the Hebrew pass: the OCR text
    # above can't read the (pointed) Hebrew quotations.
    "los1900_deel1.pdf":
        "https://archive.org/download/genesisuitleggin01calv/genesisuitleggin01calv.pdf",
    "los1900_deel2.pdf":
        "https://archive.org/download/genesisuitleggin02calv/genesisuitleggin02calv.pdf",
}


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--out-dir", type=Path, default=OUT_DIR)
    args = ap.parse_args()
    args.out_dir.mkdir(parents=True, exist_ok=True)

    for name, url in SOURCES.items():
        path = args.out_dir / name
        if path.exists() and path.stat().st_size > 0:
            print(f"[skip]  {path} ({path.stat().st_size} bytes)")
            continue
        # archive.org answers large downloads with the odd transient 500:
        # retry, and write to a .part file so an interrupted download is
        # never mistaken for a complete one on the next run.
        part = path.with_suffix(path.suffix + ".part")
        for attempt in range(1, 5):
            print(f"[fetch] {url} (attempt {attempt})")
            try:
                with requests.get(url, headers={"User-Agent": "Mozilla/5.0"}, timeout=120, stream=True) as resp:
                    resp.raise_for_status()
                    with part.open("wb") as fh:
                        for chunk in resp.iter_content(1 << 20):
                            fh.write(chunk)
                part.replace(path)
                break
            except requests.RequestException as exc:
                print(f"[warn]  {exc}")
                if attempt == 4:
                    raise
                time.sleep(20 * attempt)
        print(f"[ok]    {path} ({path.stat().st_size} bytes)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
