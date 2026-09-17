#!/usr/bin/env python3
"""Fetch the Latin text of the Nederlandse Geloofsbelijdenis (Belgic
Confession) from heidelblog.net -- a clean, hand-transcribed text (not OCR),
by R. Scott Clark. All 37 articles, confirmed by inspection.

The live site blocks non-browser requests (Cloudflare, HTTP 403), so this
fetches a stable Wayback Machine snapshot instead -- verified to carry the
full article text.

Usage:
    python scripts/fetch_ngb.py

Requires: requests
"""
from __future__ import annotations

import sys
from pathlib import Path

import requests

URL = "https://web.archive.org/web/2023id_/https://heidelblog.net/2015/08/confessio-belgica/"
RAW_PATH = Path("/data/institutio/raw/ngb.html")


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
