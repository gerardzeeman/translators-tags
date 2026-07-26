#!/usr/bin/env python3
"""Parse the Weijenberg (1865-1891, Zalsman/Kampen) Dutch translation of
Calvin's Institutio from its Google Books EPUB export into segments.jsonl,
for loading as an additional translation layer (layer='weijenberg1865')
alongside the LLM translation -- see load_weijenberg.py.

Source: Google Books scan (public domain), downloaded manually -- Google
requires an authenticated session for the actual file download, so this
can't be fetched by script the way calvin.reformation.nl is; the operator
downloads deel1/2/3.epub from Google Books/Play Books by hand.

Structure (confirmed by inspecting the actual EPUB): one page per
<div class="flow"> in OEBPS/content/content-NNNN.xml, in the order listed
in OEBPS/volume.opf's <spine> (not just ascending filename order, though
they happen to coincide here -- some numbers are skipped, e.g. blank/image
pages excluded from the spine entirely). Each paragraph is a
<p class="gtxt_body" ...> element (footnotes use a distinct
"gtxt_footnote" class and are naturally excluded by selecting only
gtxt_body). A book starts with a paragraph reading exactly "EERSTE BOEK."
/ "TWEEDE BOEK." / "DERDE BOEK." / "VIERDE BOEK."; a chapter starts with
"HOOFDSTUK <roman>."; a numbered paragraph starts with "<n>. " -- matching
this corpus's existing book/chapter/section structure exactly, so text can
be matched to the already-loaded Latin segments by `ref` alone, with no
alignment step needed.

Paragraphs routinely continue across a page (i.e. file) boundary with no
marker at all (confirmed: the "1. De geheele..." paragraph of Inst. 1.1.1
runs from content-0012.xml straight into content-0013.xml with no restart
marker), so text is accumulated across files until the next
section/chapter/book marker is seen, not per-file.

Calvin's dedicatory letter to Francis I (the sole "front matter" segment,
ref 'Inst. front.1') IS in this edition, but not where the rest of the
book's structure would suggest: it shares deel1's very first content page
(content-0011.xml) with an entirely unrelated document, Calvin's separate
"Aan den Lezer" ("To the Reader") preface -- which isn't part of this
corpus at all (the existing Latin segments have no matching ref for it).
Unlike every numbered section elsewhere, the letter has no "<n>. " markers
of its own, so it can't go through parse_volume's normal state machine --
extract_front_matter() instead locates it by its distinctive opening
("Den zeer magtigen...") and closing ("Bazel, den 1 Augustus in het jaar
1536.") lines and joins everything between them as one block, matching the
single 'Inst. front.1' segment. Deel 3's opening pages ("VOORWOORD VAN DEN
VERTALER") are a third, separate document -- Weijenberg's own translator's
foreword, not Calvin's -- and are correctly left out of the corpus as they
have no matching segment either.

Usage:
    python scripts/parse_weijenberg.py deel1.epub deel2.epub deel3.epub -o /data/institutio/weijenberg.jsonl

Requires: beautifulsoup4, lxml (already in requirements.txt)
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import zipfile
from pathlib import Path

from bs4 import BeautifulSoup

BOOK_RE = re.compile(r'^(EERSTE|TWEEDE|DERDE|VIERDE) BOEK\.$')
# The roman numeral allows internal whitespace: OCR occasionally letter-spaces
# a heading (confirmed: book II chapter 16's heading came through as literal
# "HOOFDSTUK X V I." with spaces between each letter), which would otherwise
# silently fail to match and let that whole chapter's content bleed into the
# previous one. Stripped before roman_to_int() -- see the match sites below.
CHAPTER_RE = re.compile(r'^HOOFDSTUK\s+((?:[IVXLCDM]\s*)+)\.$')
SECTION_RE = re.compile(r'^(\d+)\.\s+(.*)$', re.DOTALL)

BOOK_WORDS = {'EERSTE': 1, 'TWEEDE': 2, 'DERDE': 3, 'VIERDE': 4}
ROMAN_VALUES = {'I': 1, 'V': 5, 'X': 10, 'L': 50, 'C': 100, 'D': 500, 'M': 1000}

FRONT_MATTER_START = 'Den zeer magtigen en doorluchtigen Monarch,'
FRONT_MATTER_END_CONTAINS = 'Bazel, den 1 Augustus in het jaar 1536.'


def roman_to_int(s: str) -> int:
    total, prev = 0, 0
    for ch in reversed(s):
        val = ROMAN_VALUES[ch]
        total += -val if val < prev else val
        prev = max(prev, val)
    return total


def spine_files(z: zipfile.ZipFile) -> list[str]:
    """True reading order from OEBPS/volume.opf's <spine> -- more robust
    than assuming filename sort order, even though they coincide here."""
    container = z.read('META-INF/container.xml').decode('utf-8')
    opf_path = re.search(r'full-path="([^"]+)"', container).group(1)
    opf = z.read(opf_path).decode('utf-8')
    opf_dir = str(Path(opf_path).parent)

    manifest = dict(re.findall(r'<item\s+id="([^"]+)"\s+href="([^"]+)"', opf))
    spine_ids = re.findall(r'<itemref\s+idref="([^"]+)"', opf)
    return [f"{opf_dir}/{manifest[i]}" for i in spine_ids if i in manifest]


def iter_paragraphs(epub_path: Path):
    """Yields cleaned body-paragraph text strings, in true reading order,
    across the whole EPUB -- page/file boundaries are invisible here."""
    with zipfile.ZipFile(epub_path) as z:
        for path in spine_files(z):
            try:
                raw = z.read(path)
            except KeyError:
                continue
            soup = BeautifulSoup(raw, 'lxml-xml')
            for p in soup.select('p.gtxt_body'):
                text = re.sub(r'\s+', ' ', p.get_text(' ', strip=True)).strip()
                if text:
                    yield text


def extract_front_matter(epub_path: Path) -> dict | None:
    """Calvin's dedicatory letter to Francis I -- see the module docstring
    for why this needs its own extraction instead of going through
    parse_volume. Returns None if the volume doesn't contain it (only
    deel1 does)."""
    paras = list(iter_paragraphs(epub_path))
    start = next((i for i, p in enumerate(paras) if p.startswith(FRONT_MATTER_START)), None)
    end = next((i for i, p in enumerate(paras) if FRONT_MATTER_END_CONTAINS in p), None)
    if start is None or end is None or end < start:
        return None
    text = ' '.join(paras[start:end + 1]).strip()
    return {'ref': 'Inst. front.1', 'book': None, 'chapter': None, 'section': 1, 'text': text}


def parse_volume(epub_path: Path, state: dict, fallback_book: int) -> list[dict]:
    """Parses one EPUB volume. `state` carries book/chapter across volume
    boundaries (mutated in place).

    `fallback_book` covers a real inconsistency between volumes in this
    scan: deel1 prints its own "EERSTE BOEK."/"TWEEDE BOEK." headings, and
    deel2 prints "DERDE BOEK.", but deel3 (Book IV) never prints a "VIERDE
    BOEK." heading at all -- it opens directly on "HOOFDSTUK I." (confirmed
    by exhaustive search: "VIERDE" only ever appears as the ordinary word
    "vierde" in running prose, never as a standalone heading). So: if a
    volume hits a chapter marker before it has seen any book marker of its
    own, `fallback_book` is used instead -- once a real "... BOEK." marker
    *is* seen (deel1's internal transition), it takes priority as normal.
    """
    segments = []
    book = state.get('book')
    chapter = state.get('chapter')
    section = None
    buf: list[str] = []
    book_declared_this_volume = False

    def flush():
        nonlocal buf
        if section is not None and buf:
            text = ' '.join(buf).strip()
            if text:
                segments.append({
                    'ref': f"Inst. {book}.{chapter}.{section}",
                    'book': book, 'chapter': chapter, 'section': section,
                    'text': text,
                })
        buf = []

    for para in iter_paragraphs(epub_path):
        m_book = BOOK_RE.match(para)
        if m_book:
            flush()
            book = BOOK_WORDS[m_book.group(1)]
            book_declared_this_volume = True
            chapter, section = None, None
            continue

        m_chapter = CHAPTER_RE.match(para) if (book or fallback_book) else None
        if m_chapter:
            flush()
            if not book_declared_this_volume:
                book = fallback_book
                book_declared_this_volume = True
            chapter, section = roman_to_int(m_chapter.group(1).replace(' ', '')), None
            continue

        m_section = SECTION_RE.match(para) if book and chapter else None
        if m_section:
            flush()
            section = int(m_section.group(1))
            buf = [m_section.group(2)]
            continue

        # Chapter-title lines and anything else before a chapter's first
        # numbered paragraph aren't part of any segment's body -- skip.
        if section is None:
            continue

        buf.append(para)

    flush()
    state['book'], state['chapter'] = book, chapter
    return segments


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('epubs', type=Path, nargs='+', help='deel1.epub deel2.epub deel3.epub, in order')
    ap.add_argument('-o', '--output', type=Path, default=Path('/data/institutio/weijenberg.jsonl'))
    ap.add_argument('--start-books', default='1,3,4',
                    help='comma-separated book number each volume falls back to if it opens '
                         'directly on a chapter with no "... BOEK." heading of its own '
                         '(confirmed necessary for deel3/Book IV in this scan)')
    args = ap.parse_args()

    fallback_books = [int(x) for x in args.start_books.split(',')]
    if len(fallback_books) != len(args.epubs):
        print(f"[error] --start-books has {len(fallback_books)} values, expected {len(args.epubs)} "
              f"(one per epub)")
        return 1

    all_segments = []
    for epub_path in args.epubs:
        front = extract_front_matter(epub_path)
        if front:
            print(f"[parse] {epub_path.name}: front matter, {len(front['text']):,} chars")
            all_segments.append(front)
            break
    else:
        print("[warn] front matter (dedicatory letter to Francis I) not found in any volume")

    state: dict = {}
    for epub_path, fallback_book in zip(args.epubs, fallback_books):
        segs = parse_volume(epub_path, state, fallback_book)
        print(f"[parse] {epub_path.name}: {len(segs)} sections "
              f"(ending at book {state.get('book')}, chapter {state.get('chapter')})")
        all_segments.extend(segs)

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open('w', encoding='utf-8') as fh:
        for seg in all_segments:
            fh.write(json.dumps(seg, ensure_ascii=False) + '\n')

    n_words = sum(len(s['text'].split()) for s in all_segments)
    print(f"\n[done] {len(all_segments)} segments, ~{n_words:,} words, written to {args.output}")
    return 0


if __name__ == '__main__':
    sys.exit(main())
