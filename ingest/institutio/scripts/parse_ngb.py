#!/usr/bin/env python3
"""Parse the cached heidelblog.net page (fetch_ngb.py) into segments (JSONL).

Structure: flat "Articulus N: Title" paragraphs, no chapter grouping (book
and chapter are both NULL, same convention the Institutio uses for its own
front matter) -- 37 articles total.

Each article is a single <p> whose text is "Articulus N: Title" followed
directly by the body (no separating tag), with the "Articulus N: Title"
portion also duplicated in a nested <strong>. The heading is taken from
that <strong> text; the body is the paragraph text with that same prefix
stripped.

A few isolated transcription artifacts (a mis-encoded space rendered as the
Unicode replacement character) are cleaned up; nothing else is altered.

Usage:
    python scripts/parse_ngb.py -o /data/institutio/ngb_segments.jsonl
    python scripts/parse_ngb.py --dry-run

Requires: beautifulsoup4, lxml
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

from bs4 import BeautifulSoup

RAW_PATH = Path("/data/institutio/raw/ngb.html")

_ARTICLE_RE = re.compile(r"^(?:Articulus|Art\.)\s+([IVXLCDM]+)\s*:\s*(.+)$")
_ROMAN_VALUES = {"I": 1, "V": 5, "X": 10, "L": 50, "C": 100, "D": 500, "M": 1000}


def _roman_to_int(s: str) -> int:
    s = s.upper()
    total = 0
    prev = 0
    for ch in reversed(s):
        v = _ROMAN_VALUES[ch]
        if v < prev:
            total -= v
        else:
            total += v
            prev = v
    return total


def _clean(text: str) -> str:
    text = text.replace("�", " ")  # mis-encoded space artifact seen in the source
    return re.sub(r"\s+", " ", text).strip()


def parse(html: str) -> list[dict]:
    soup = BeautifulSoup(html, "lxml")
    article_tag = soup.find("article") or soup.find("div", class_=re.compile("entry-content|post-content"))
    if article_tag is None:
        raise ValueError("could not find the article body container in the fetched page")

    segments = []
    seq = 0
    for p in article_tag.find_all("p"):
        strong = p.find("strong")
        if strong is None:
            continue
        strong_text = _clean(strong.get_text())
        m = _ARTICLE_RE.match(strong_text)
        if not m:
            continue
        number = _roman_to_int(m.group(1))
        heading = m.group(2).strip()

        # The source has one confirmed roman-numeral typo (article 18's
        # <strong> heading reads "XVII", duplicating article 17's number,
        # instead of "XVIII"). Trust the paragraph's position over a numeral
        # that doesn't advance from the previous one, rather than silently
        # dropping or misnumbering an article.
        expected = (segments[-1]["section"] + 1) if segments else 1
        if number <= (segments[-1]["section"] if segments else 0):
            print(f"[warn]  Articulus {m.group(1)!r} does not advance past {segments[-1]['section']} "
                  f"-- source typo, renumbering as {expected}")
            number = expected

        # The <strong> title is duplicated as plain text at the very start of
        # the same paragraph (see module docstring) -- strip that exact
        # duplicate prefix, not a regex guess, so the body split is exact
        # regardless of what punctuation/wording the title itself contains.
        full_text = _clean(p.get_text())
        if not full_text.startswith(strong_text):
            raise ValueError(f"Articulus {m.group(1)}: paragraph text does not start with its own <strong> title as expected")
        body = _clean(full_text[len(strong_text):])

        seq += 1
        segments.append({
            "ref": f"NGB {number}", "book": None, "chapter": None, "section": number,
            "kind": None, "heading": heading, "text": body,
            "seq": seq, "annotations": [],
        })

    return segments


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("-o", "--output", type=Path, default=Path("/data/institutio/ngb_segments.jsonl"))
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--input", type=Path, default=RAW_PATH)
    args = ap.parse_args()

    segments = parse(args.input.read_text(encoding="utf-8"))
    print(f"[parse] {len(segments)} articles (expect 37)")
    numbers = [s["section"] for s in segments]
    if numbers != list(range(1, len(numbers) + 1)):
        print(f"[warn]  article numbers not a clean 1..N sequence: {numbers}")

    if args.dry_run:
        for s in segments:
            print(f"  {s['ref']:<8} {s['heading']:<50} {s['text'][:70]}")
        return 0

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8") as f:
        for s in segments:
            f.write(json.dumps(s, ensure_ascii=False) + "\n")
    print(f"[ok]    wrote {args.output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
