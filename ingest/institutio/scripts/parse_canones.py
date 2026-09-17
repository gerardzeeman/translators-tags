#!/usr/bin/env python3
"""Parse the cached CCEL page (fetch_canones.py) into segments (JSONL).

Source: Philip Schaff, Creeds of Christendom, Vol. III (1877), the Latin
text of the Canones Synodi Dordrechtanae -- a clean, professionally
transcribed public-domain text (not raw OCR).

Structure captured (see README/PROJECTDOSSIER for the full source
discussion): a short Preface, four numbered chapters -- "Primum",
"Secundum", "Tertium et Quartum" (the traditional Third and Fourth Heads
are combined into a single chapter, per the historical structure: five
doctrinal *points* presented in four chapter-groupings), "Quintum" -- each
with numbered "Articulus" paragraphs followed by that chapter's own
"Rejectio Errorum" (separately numbered), and a closing Conclusio. Each
chapter's heading text is denormalized onto every segment of that chapter
(same convention as the Institutio's `segment.heading`).

Deliberately NOT captured: the delegate subscription-name blocks between
chapters (a list of ~100 proper names/titles, not doctrinal content), and
everything from "Sententia Synodi de Remonstrantibus" onward (the synod's
disciplinary judgment against named Remonstrant pastors and the States-
General's political approval -- not part of the confessional text itself).
Scripture citations (CCEL's <a class="scripRef"> links) are kept as their
plain visible text (e.g. "Rom. iii. 19") rather than converted to hover-
linked annotations -- a possible future enhancement, not attempted here.

`kind` distinguishes an ordinary "Articulus" paragraph from a "Rejectio
Errorum" paragraph within the same chapter (see db/migrate_add_confession_
documents.sql); both share the same `chapter` number. Preface and
Conclusio segments have kind=None, book=None, chapter=None -- exactly the
same "no chapter" shape the Institutio uses for its own front matter.

Usage:
    python scripts/parse_canones.py -o /data/institutio/canones_segments.jsonl
    python scripts/parse_canones.py --dry-run   # print parsed segments, no file written

Requires: beautifulsoup4, lxml
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

from bs4 import BeautifulSoup

RAW_PATH = Path("/data/institutio/raw/canones.html")

_ROMAN_RE = re.compile(r"^[IVXLCDM]+\.?$", re.IGNORECASE)
_CHAPTER_RE = re.compile(
    r"(Primum|Secundum|Tertium(?:\s+et\s+Quartum)?|Quintum)\s+Doctrin\w*\s+Caput",
    re.IGNORECASE,
)
_DASHES_RE = re.compile(r"^[—\-\s]+$")
_PRAEFATIO_RE = re.compile(r"^pr[æa]fatio", re.IGNORECASE)
_CONCLUSIO_RE = re.compile(r"^conclusio", re.IGNORECASE)
_ROMAN_VALUES = {"I": 1, "V": 5, "X": 10, "L": 50, "C": 100, "D": 500, "M": 1000}


def _roman_to_int(s: str) -> int:
    s = s.rstrip(".").upper()
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


def _clean_text(tag) -> str:
    # No separator: small-caps headings/names are marked up as adjacent
    # <span> fragments with NO whitespace between them (e.g. "S" + "ecundum"
    # -> "Secundum"); inserting a separator here would break word
    # reconstruction. Real inter-word spaces are already literal text nodes
    # between tags, so plain get_text() concatenation is correct.
    return re.sub(r"\s+", " ", tag.get_text()).strip()


def parse(html: str) -> list[dict]:
    soup = BeautifulSoup(html, "lxml")
    container = soup.find("div", class_="book-content")
    if container is None:
        raise ValueError("could not find div.book-content in the fetched page")

    # Page-break markers (<span class="pb"><a class="page">566</a></span>)
    # sit inline mid-sentence in the source and carry no text of their own
    # worth keeping -- left in place, get_text() would glue the page number
    # straight into the surrounding word (e.g. "Serio 566enim").
    for pb in container.find_all("span", class_="pb"):
        pb.decompose()

    segments: list[dict] = []
    seq = 0
    counters: dict[tuple, int] = {}

    mode: str | None = None      # None | 'preface' | 'chapter' | 'conclusion'
    chapter_num = 0
    chapter_heading: str | None = None
    kind: str | None = None      # 'article' | 'rejection', meaningful only in 'chapter' mode
    pending_number: int | None = None

    def next_section(key) -> int:
        counters[key] = counters.get(key, 0) + 1
        return counters[key]

    for tag in container.find_all(["h3", "h4", "p"]):
        text = _clean_text(tag)
        if not text or _DASHES_RE.match(text):
            continue
        low = text.lower()

        # "Rejectio Errorum" is marked up inconsistently in the source --
        # a <p> for chapters 1-3, an <h4> for chapter 4 -- so this check
        # must run regardless of tag name, before the h3/h4-only checks
        # below (which would otherwise never see it for chapters 1-3).
        if low.startswith("rejectio errorum"):
            kind, pending_number = "rejection", None
            continue

        if tag.name in ("h3", "h4"):
            m = _CHAPTER_RE.search(text)
            if m:
                chapter_num += 1
                chapter_heading = text
                mode, kind, pending_number = "chapter", "article", None
                continue
            if _PRAEFATIO_RE.match(text):
                mode, kind, pending_number = "preface", None, None
                continue
            if _CONCLUSIO_RE.match(text):
                mode, kind, pending_number = "conclusion", None, None
                continue
            # Any other heading (delegate-list section headers, "Sententia
            # Synodi de Remonstrantibus", the English abridgment that
            # follows) ends whatever body we were capturing.
            mode = None
            continue

        # tag.name == "p"
        if mode is None:
            continue

        if mode == "chapter":
            if _ROMAN_RE.match(text) or low.startswith("articulus primus"):
                pending_number = 1 if low.startswith("articulus primus") else _roman_to_int(text)
                continue
            if pending_number is None:
                continue  # an intro/italic line under the chapter or rejectio heading -- not numbered body
            section = next_section((chapter_num, kind))
            seq += 1
            ref = (f"Canones {chapter_num}.{section}" if kind == "article"
                   else f"Canones {chapter_num}.verwerping.{section}")
            segments.append({
                "ref": ref, "book": None, "chapter": chapter_num, "section": section,
                "kind": kind, "heading": chapter_heading, "text": text,
                "seq": seq, "annotations": [],
            })
            pending_number = None
            continue

        if mode in ("preface", "conclusion"):
            section = next_section(mode)
            seq += 1
            prefix = "voorwoord" if mode == "preface" else "besluit"
            segments.append({
                "ref": f"Canones {prefix}.{section}", "book": None, "chapter": None,
                "section": section, "kind": None, "heading": None, "text": text,
                "seq": seq, "annotations": [],
            })
            if mode == "conclusion" and low.rstrip(".").endswith("amen"):
                # The Conclusio's final paragraph always closes on "... Amen."
                # -- what immediately follows in the source is a note about
                # the deputies' signatures, then the delegate name list
                # itself, neither of which is doctrinal text worth ingesting.
                mode = None
            continue

    return segments


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("-o", "--output", type=Path, default=Path("/data/institutio/canones_segments.jsonl"))
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--input", type=Path, default=RAW_PATH)
    args = ap.parse_args()

    segments = parse(args.input.read_text(encoding="utf-8"))

    n_articles = sum(1 for s in segments if s["kind"] == "article")
    n_rejections = sum(1 for s in segments if s["kind"] == "rejection")
    n_other = len(segments) - n_articles - n_rejections
    n_chapters = len({s["chapter"] for s in segments if s["chapter"] is not None})
    print(f"[parse] {len(segments)} segments: {n_articles} articuli, {n_rejections} rejectio-paragrafen, "
          f"{n_other} voorwoord/besluit, across {n_chapters} chapters")

    if args.dry_run:
        for s in segments:
            print(f"  {s['ref']:<28} kind={s['kind']!s:<9} {s['text'][:90]}")
        return 0

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8") as f:
        for s in segments:
            f.write(json.dumps(s, ensure_ascii=False) + "\n")
    print(f"[ok]    wrote {args.output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
