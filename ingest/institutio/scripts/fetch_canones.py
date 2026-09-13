#!/usr/bin/env python3
"""Fetch the Latin text of the Canones Synodi Dordrechtanae (Canons of Dort,
1619) from Philip Schaff's Creeds of Christendom, Vol. III (1877), hosted on
CCEL -- a clean, professionally transcribed public-domain text (not raw OCR),
confirmed against archive.org's own raw-OCR scans of the 1620 Acta Synodi
Nationalis and the 1840 Niemeyer Collectio Confessionum, both of which are
significantly noisier.

The whole Latin text (Preface, five heads of doctrine with their "Rejectio
Errorum" sections, Conclusion) lives on a single CCEL page.

Usage:
    python scripts/fetch_canones.py

Requires: requests
"""
from __future__ import annotations

import sys
from pathlib import Path

import requests

URL = "https://www.ccel.org/ccel/schaff/creeds3.iv.xvi.html"
RAW_PATH = Path("/data/institutio/raw/canones.html")


def main() -> int:
    RAW_PATH.parent.mkdir(parents=True, exist_ok=True)
    print(f"[fetch] {URL}")
    resp = requests.get(URL, headers={"User-Agent": "Mozilla/5.0"}, timeout=30)
    resp.raise_for_status()
    RAW_PATH.write_bytes(resp.content)
    print(f"[ok]    wrote {RAW_PATH} ({len(resp.content)} bytes)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
