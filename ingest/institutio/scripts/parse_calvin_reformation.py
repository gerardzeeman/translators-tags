#!/usr/bin/env python3
"""Parse cached calvin.reformation.nl chapter pages into segments (JSONL).

Each segment is one numbered paragraph: the canonical unit book.chapter.section
(e.g. Inst. 1.1.1). Front matter (the dedicatory letter to Francis I) is a
single segment 'Inst. front.1' (the site doesn't subdivide it further).

HTML structure (per chapter page, inside the parallel-editions <table>):
  <TR>                                            <- edition-name header row
    <TD class="res res_title" rnum=0>Institutio christianae religionis...</TD>
  <TR>                                            <- bare chapter marker row
    <TD class="res" rnum=0>CAP. I.</TD>            <- Latin column always rnum=0
  <TR>                                            <- descriptive title row
    <TD class="res" rnum=0>Dei notitiam et nostri...</TD>   <- used as `heading`
  <TR>                                            <- one row per numbered paragraph
    <TD class="res res_text" lang='la' rnum=0><p>
      <strong class="subparagraph_num" paragraph=N>N.</strong>
      <span class="yearcol ..."> ... running Latin text ... <sup><span
        class="fn_text" fnum="107">1</span></sup> ... </span>
      <span class="pagenum">31</span>              <- inline original-page-number marker
    </p></TD>
    <TD rnum=1 ...>...</TD>  <TD rnum=2 ...>...</TD>  ...  <- other editions, ignored
  <TR><!--footnotes-->
    <TD class="res res_footnote" rnum=0>
      <p class="footnote" fnum="107"><span class="fn_foot">1</span> cf. Clem.
      Alex., Paed. 111,1. GCS t.12, p. 235.</p> ...
    </TD>
    <TD rnum=1 class="res_footnote">...</TD> ...    <- other editions' apparatus, ignored
  </TR>

The Latin column is always rnum=0, but only the numbered-paragraph rows
carry a `lang="la"` attribute -- the two heading rows above them are plain
`class="res"` cells with no `lang`. So all parsing selects `td[rnum="0"]`
(the Latin column throughout) and distinguishes header/body/footnote rows by
class/subparagraph_num rather than by the `lang` attribute alone.

Reference markers (fn_text spans) come in two kinds we care about, matched
by fnum against the footnote definitions: all footnotes for every paragraph
on the page are collected into a *single* res_footnote TD per column near
the end of the table (one `<!--footnotes-->` block per page, not one per
paragraph -- fnum is a page-wide, in practice edition-wide, unique id), so
parsing is a two-pass process: collect every paragraph's markers plus the
page's footnote definitions, then resolve markers against definitions
after the whole page has been walked.
  - letter glyphs (a, b, c, ...)  -> textual variant in another edition
  - digit glyphs  (1, 2, 3, ...)  -> a citation (Scripture / other work)
Pipe glyphs (|, ||, |||) are edition-scope markers, not real annotations,
and are dropped (their text contributes nothing to the body either way).
Each kept marker's position is recorded as a character offset into the
segment's cleaned text (not a token id), via a placeholder-substitution
trick: fn_text nodes are replaced with a private-use-area sentinel
character while the surrounding text is walked and whitespace-cleaned, then
the sentinel's final offset (adjusted for earlier removals) becomes
char_position, and the sentinel is removed from the visible text.

Usage:
    python scripts/parse_calvin_reformation.py -o /data/institutio/segments.jsonl
    python scripts/parse_calvin_reformation.py --dry-run   # print first chapter, no file written

Requires: beautifulsoup4, lxml
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from dataclasses import dataclass, field
from pathlib import Path

from bs4 import BeautifulSoup, NavigableString

RAW_DIR = Path("/data/institutio/raw/pages")

BOOKS: list[tuple[int, int]] = [
    (-1, 1),
    (1, 18),
    (2, 17),
    (3, 25),
    (4, 20),
]

# Private-use-area sentinel: stands in for a stripped fn_text marker while
# text is being cleaned/whitespace-collapsed, so its final offset can be
# recovered afterwards. Never appears in real source text.
_MARKER_SENTINEL = ""

_VARIANT_RE = re.compile(r"^[A-Za-z]+$")
_CITATION_RE = re.compile(r"^\d+$")

# The print edition's apparatus letters restart at 'a' (and citation digits at
# 1) on every physical page break -- confirmed against the raw source: a
# <span class="pagenum"> marker sits exactly between glyph "k" and the next
# "a" in Inst. 1.1.3. Our digital segments merge text across those page
# breaks, so the raw scraped glyph visibly (and confusingly) restarts
# mid-segment. Letters/digits below are therefore renumbered continuously per
# segment instead of using the scraped glyph -- the note text (the actual
# scholarly content) still comes from the source untouched, only the
# reference marker is redone. The source itself skips 'j' (old Latin
# typesetting convention, i/j not distinguished -- confirmed: 'j' never
# appears as a scraped glyph anywhere in the corpus), but this renumbering
# deliberately uses the full modern 26-letter alphabet instead, then doubles
# (aa, bb, ...) once the single letters run out.
_VARIANT_ALPHABET = "abcdefghijklmnopqrstuvwxyz"


def _variant_glyph(n: int) -> str:
    letter = _VARIANT_ALPHABET[n % len(_VARIANT_ALPHABET)]
    repeat = n // len(_VARIANT_ALPHABET) + 1
    return letter * repeat


@dataclass
class Segment:
    ref: str
    book: int | None
    chapter: int | None
    section: int | None
    heading: str | None
    text: str
    annotations: list[dict] = field(default_factory=list)
    seq: int = 0


@dataclass
class ParseState:
    segments: list[Segment] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)


def clean_text(text: str) -> str:
    text = unicodedata.normalize("NFC", text)
    return re.sub(r"\s+", " ", text).strip()


def classify_glyph(glyph: str) -> str | None:
    if _VARIANT_RE.fullmatch(glyph):
        return "variant"
    if _CITATION_RE.fullmatch(glyph):
        return "citation"
    return None  # pipe edition-markers etc. -- not a reference we track


# Calvin's own inline Scripture/other-work citations appear as plain bracketed
# prose, not special markup -- e.g. "[Gene. 18. d. 27]", "[Iesa. 2. c. 10, et
# d. 19]", "[Iudic. 13. d. 22; Iesa. 6. b. 5; Ezech. 2. a. 1, et alibi.]".
# Confirmed safe to treat every "[...]" as one citation: surveyed all 4,125
# occurrences across the corpus, all are reference-shaped (book/chapter/verse
# or Lib./Cap./Ibidem-style patristic citations); the only 2 anomalies (a
# stray unmatched "[" and one bracket mistakenly closed with ")" instead of
# "]") are handled by the bail-out below (no valid closing "]" reachable
# without crossing another "[" first) rather than mismatched.


def _interleave_bracket_citations(text: str, fn_markers: list[dict]) -> tuple[str, list[dict]]:
    """Replace the existing fn_text sentinels already in `text` and any
    bracket citations with the marker sentinel, returning the rewritten text
    plus a combined marker list in true left-to-right order.

    fn_markers was recorded during the DOM walk in document order, which
    matches the left-to-right order of the sentinel chars already in `text`.
    A manual scan (rather than a single regex) is needed because an existing
    footnote marker can fall *inside* a Calvin citation bracket (e.g. Inst.
    1.1.3 has a variant-footnote glyph sitting right inside "[Iudic. 13. d.
    22; ...; Ezech. 2. a. 1<marker>, et alibi.]") -- a first version that
    matched brackets wholesale via regex swallowed that embedded sentinel
    into the citation's note text, which desynced every later fn_text marker
    in the segment by one slot (confirmed: corrupted glyph/note pairings
    from that point on). This version keeps both: the bracket becomes one
    citation marker (note text with any embedded sentinel stripped out),
    immediately followed by the embedded marker(s) as their own entries --
    both collapse to the same position in the text once the bracket is
    removed, but each keeps its own correct content. (The raw bracket text
    can still be shown for validation purposes -- see
    InstitutioController::SHOW_RAW_CITATION_BRACKETS -- as a pure PHP
    rendering concern using the stored note, without touching text_la or
    the char offsets sentence_alignment depends on.)
    """
    fn_iter = iter(fn_markers)
    combined: list[dict] = []
    out: list[str] = []

    i, n = 0, len(text)
    while i < n:
        ch = text[i]
        if ch == _MARKER_SENTINEL:
            combined.append(next(fn_iter))
            out.append(_MARKER_SENTINEL)
            i += 1
            continue
        if ch == "[":
            close = text.find("]", i + 1)
            next_open = text.find("[", i + 1)
            if close == -1 or (next_open != -1 and next_open < close):
                out.append(ch)  # unbalanced/nested -- leave as literal text
                i += 1
                continue
            inner = text[i + 1:close]
            note = clean_text(inner.replace(_MARKER_SENTINEL, ""))
            combined.append({"fnum": None, "glyph": None, "kind": "citation", "note": note})
            out.append(_MARKER_SENTINEL)
            for _ in range(inner.count(_MARKER_SENTINEL)):
                combined.append(next(fn_iter))
                out.append(_MARKER_SENTINEL)
            i = close + 1
            continue
        out.append(ch)
        i += 1

    return "".join(out), combined


def extract_text_and_markers(td) -> tuple[str, list[dict]]:
    """Walk a paragraph TD, returning (cleaned_text, markers).

    markers is a list of {'fnum', 'glyph', 'char_position', ...} in document
    order, with char_position already adjusted to be an offset into the
    *returned* cleaned_text. Entries for Calvin's own inline bracket
    citations (see _interleave_bracket_citations) carry fnum=None plus an
    already-resolved 'kind'/'note' instead of an fnum to look up.
    """
    buf: list[str] = []
    raw_markers: list[dict] = []

    def walk(node) -> None:
        if isinstance(node, NavigableString):
            buf.append(str(node))
            return
        if getattr(node, "name", None) is None:
            return
        classes = node.get("class") or []
        if "pagenum" in classes or "subparagraph_num" in classes:
            return
        if "fn_text" in classes:
            glyph = node.get_text(strip=True)
            fnum = node.get("fnum")
            if glyph and fnum:
                buf.append(_MARKER_SENTINEL)
                raw_markers.append({"fnum": fnum, "glyph": glyph})
            return
        for child in node.children:
            walk(child)

    for child in td.children:
        walk(child)

    joined, combined_markers = _interleave_bracket_citations("".join(buf), raw_markers)
    cleaned = clean_text(joined)
    positions = [m.start() for m in re.finditer(_MARKER_SENTINEL, cleaned)]
    final_text = cleaned.replace(_MARKER_SENTINEL, "")

    if len(positions) != len(combined_markers):
        return final_text, []  # defensive: shouldn't happen, drop markers rather than misplace them

    # Multiple markers can legitimately land on the same char_position (a
    # citation bracket that had an existing footnote marker embedded inside
    # it collapses to one point once the bracket text is removed) -- `ord`
    # disambiguates them for the DB's UNIQUE (segment_id, char_position, ord)
    # constraint while preserving render order.
    markers = []
    last_pos = None
    ord_ = 0
    for i, (pos, marker) in enumerate(zip(positions, combined_markers)):
        char_position = pos - i
        ord_ = ord_ + 1 if char_position == last_pos else 0
        last_pos = char_position
        markers.append({**marker, "char_position": char_position, "ord": ord_})
    return final_text, markers


def parse_footnote_defs(td) -> dict[str, dict]:
    """Return {fnum: {'glyph': str, 'note': str}} for a res_footnote TD."""
    defs: dict[str, dict] = {}
    for p in td.select("p.footnote"):
        fnum = p.get("fnum")
        if not fnum:
            continue
        foot_span = p.select_one(".fn_foot")
        glyph = foot_span.get_text(strip=True) if foot_span else ""
        if foot_span:
            foot_span.decompose()
        note = clean_text(p.get_text(" "))
        defs[fnum] = {"glyph": glyph, "note": note}
    return defs


def parse_chapter_html(html: str) -> tuple[str | None, list[dict]]:
    """Return (heading, sections) for one chapter page.

    Each section is {'section': int, 'text': str, 'annotations': [...]}.
    """
    soup = BeautifulSoup(html, "lxml")
    # The Latin column is always rnum=0; body-paragraph and footnote cells
    # additionally carry lang="la" -- the two heading rows above them are
    # plain class="res" cells with no lang (see module docstring).
    la_tds = soup.select('td[rnum="0"]')

    heading_candidate: str | None = None
    raw_sections: list[dict] = []   # {'section', 'text', 'markers': [{'fnum','glyph','char_position'}]}
    footnote_defs: dict[str, dict] = {}   # fnum -> {'glyph','note'}, collected from anywhere on the page

    for td in la_tds:
        classes = td.get("class") or []

        if "res_title" in classes:
            continue

        if "res_footnote" in classes:
            footnote_defs.update(parse_footnote_defs(td))
            continue

        strong = td.select_one(".subparagraph_num")
        if strong is None:
            for noise in td.select(".fn_text, .pagenum"):
                noise.decompose()
            text = clean_text(td.get_text(" "))
            if text and not raw_sections:
                heading_candidate = text
            continue

        # The paragraph attribute is the authoritative section number; the
        # tag's own text is normally the same digits ("1.") but is empty for
        # front matter, which isn't visibly numbered.
        num_text = strong.get("paragraph") or re.sub(r"\D", "", strong.get_text())
        text, markers = extract_text_and_markers(td)
        if num_text and text:
            raw_sections.append({"section": int(num_text), "text": text, "markers": markers})

    # Resolve every paragraph's markers now that the whole page (and its
    # single page-wide footnote block) has been walked.
    sections: list[dict] = []
    for sec in raw_sections:
        annotations = []
        n_variant = 0
        n_citation = 0
        for m in sec["markers"]:
            if m["fnum"] is None:
                # Calvin's own inline bracket citation -- kind/note already
                # resolved in _interleave_bracket_citations, no footnote_defs
                # lookup needed.
                kind, note = m["kind"], m["note"]
            else:
                d = footnote_defs.get(m["fnum"])
                if d is None:
                    continue
                raw_glyph = d["glyph"] or m["glyph"]
                kind = classify_glyph(raw_glyph)
                if kind is None:
                    continue
                note = d["note"]
            if kind == "variant":
                glyph = _variant_glyph(n_variant)
                n_variant += 1
            else:
                n_citation += 1
                glyph = str(n_citation)
            annotations.append({
                "char_position": m["char_position"],
                "ord": m["ord"],
                "glyph": glyph,
                "kind": kind,
                "note": note,
            })
        sections.append({"section": sec["section"], "text": sec["text"], "annotations": annotations})

    return heading_candidate, sections


def extract(book: int, chapter: int, state: ParseState) -> None:
    path = RAW_DIR / f"{book}_{chapter}.html"
    if not path.exists():
        state.warnings.append(f"Missing cached page: book {book} chapter {chapter}")
        return

    html = path.read_text(encoding="utf-8")
    heading, sections = parse_chapter_html(html)
    if not sections:
        state.warnings.append(f"No sections found: book {book} chapter {chapter}")
        return

    next_auto = 1
    seen: set[int] = set()
    for sec in sections:
        num, text, annotations = sec["section"], sec["text"], sec["annotations"]
        if book == -1:
            ref = f"Inst. front.{num}"
            book_val, chapter_val, section_val = None, None, num
        else:
            ref = f"Inst. {book}.{chapter}.{num}"
            book_val, chapter_val, section_val = book, chapter, num
            if num in seen:
                state.warnings.append(f"Duplicate section number {ref}")
            if num != next_auto and next_auto != 1:
                state.warnings.append(
                    f"Non-sequential section number in Inst. {book}.{chapter}: "
                    f"expected {next_auto}, got {num}")
            seen.add(num)
            next_auto = num + 1
        state.segments.append(
            Segment(ref, book_val, chapter_val, section_val, heading, text, annotations))


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("-o", "--output", type=Path, default=Path("/data/institutio/segments.jsonl"))
    ap.add_argument("--dry-run", action="store_true",
                    help="parse only book 1 chapter 1 and print the result, no file written")
    args = ap.parse_args()

    state = ParseState()

    if args.dry_run:
        extract(1, 1, state)
        for seg in state.segments:
            print(json.dumps(seg.__dict__, ensure_ascii=False, indent=2))
        for w in state.warnings:
            print(f"  - {w}")
        return 0

    for book, chapter_count in BOOKS:
        for chapter in range(1, chapter_count + 1):
            extract(book, chapter, state)

    for i, seg in enumerate(state.segments, start=1):
        seg.seq = i

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8") as fh:
        for seg in state.segments:
            fh.write(json.dumps(seg.__dict__, ensure_ascii=False) + "\n")

    n_front = sum(1 for s in state.segments if s.book is None)
    n_body = len(state.segments) - n_front
    n_words = sum(len(s.text.split()) for s in state.segments)
    n_annotations = sum(len(s.annotations) for s in state.segments)
    n_variant = sum(1 for s in state.segments for a in s.annotations if a["kind"] == "variant")
    n_citation = n_annotations - n_variant
    print(f"\n[done] {len(state.segments)} segments ({n_body} body, {n_front} front matter)")
    print(f"       ~ {n_words:,} words (raw whitespace count)")
    print(f"       {n_annotations:,} annotations ({n_variant:,} variant, {n_citation:,} citation)")
    print(f"       written to {args.output}")

    if state.warnings:
        print(f"\n[warnings: {len(state.warnings)}]")
        for w in state.warnings[:25]:
            print(f"  - {w}")
        if len(state.warnings) > 25:
            print(f"  ... and {len(state.warnings) - 25} more")
    return 0


if __name__ == "__main__":
    sys.exit(main())
