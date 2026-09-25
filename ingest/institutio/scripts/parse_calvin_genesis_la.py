#!/usr/bin/env python3
"""Parse Calvin's Latin Genesis commentary (Calvini Opera vol. 23, see
fetch_calvin_genesis.py) into segments (JSONL), from two independent OCRs:
the PDF's own text layer and Tesseract's (ocr_pdf_pages.py).

OCR COMBINATION
---------------
Neither OCR is clean on its own (measured against the Institutio's word
forms: ~13-17% unknown words each, much of it systematic -- the text layer
reads "m" as "rn" ("tarnen", "earn", "quern") and "c" as "e" ("nee",
"donee", "hie"); Tesseract has its own "c"/"e" slips ("peceati", "hine")),
but their errors mostly differ. Per page the two word sequences are aligned
(difflib) and, where they disagree word for word, a known Latin word wins
over an unknown one (known = a word form of the ingested Institutio, which
is Calvin's own Latin of the same period). On pages where the text layer is
unusable (>35% unknown, e.g. the Argumentum's second and third page),
Tesseract's text is used as is. Accents that the OCR invented on plain
Latin letters ("fidéles") are stripped. Greek and Hebrew quotations stay
garbled -- neither OCR reads them.

The combined text still has OCR errors (names, rarer words, the Greek/Hebrew);
the unknown-word rate is reported per run as a quality measure.

STRUCTURE
---------
Pages 30-338 (0-based PDF pages) hold the commentary: the Argumentum, then
50 chapters, each opening with a "CAPUT N." heading (OCR variants: "CAP. V.",
"CAPUT XLYII.", "CAPUT XL" for XI -- so headings are numbered by position,
1..50, and the count is checked), followed by Calvin's own Latin translation
of the whole chapter (numbered verses) and then his comments, each starting
at the beginning of a line with the verse number and the words commented on
("8. Audierunt vocem Domini."). The chapter text ends where the verse
numbering starts over at the beginning of a line.

Segments (ref / kind / chapter / section):
  "Comm. Gen. arg.N"      argument     NULL  N    Argumentum, per paragraph
  "Comm. Gen. 3 tekst.8"  scripture    3     8    Calvin's Latin of verse 3:8
  "Comm. Gen. 3:8"        commentary   3     8    comment on 3:8 (a second
                                                   comment on the same verse
                                                   gets "Comm. Gen. 3:8b")
Each row also carries `co_col`: the Calvini Opera column where it starts
(the page's left column number), for citation ("CO 23, 65").

Usage:
    python scripts/parse_calvin_genesis_la.py -o /data/institutio/calvin_genesis_la.jsonl
    python scripts/parse_calvin_genesis_la.py --pdf ... --ocr-dir ... --vocab vocab.txt --dry-run

Without --vocab the known-word list is read from the database (Institutio
tokens). Requires: pymupdf, psycopg (unless --vocab)
"""
from __future__ import annotations

import argparse
import difflib
import json
import re
import sys
import unicodedata
from pathlib import Path

import pymupdf

RAW = Path("/data/institutio/raw/calvin_genesis")
FIRST_PAGE, LAST_PAGE = 30, 338
CHAPTERS = 50
GARBLED_TEXT_LAYER = 0.35

_WORD_RE = re.compile(r"[A-Za-zÀ-ɏæœ]+")
# Hebrew (letters + points) and Greek words are single tokens too, so a
# quotation from the script-OCR pass can be aligned and put in as a whole.
_SCRIPT = "\u0590-\u05FF\uFB1D-\uFB4F\u0370-\u03FF\u1F00-\u1FFF"
_SCRIPT_RE = re.compile(f"[{_SCRIPT}]")
_HEBREW_RE = re.compile("[א-ת]")


def greek_letters(token: str) -> int:
    """Number of Greek letters, not counting accents and breathings
    (decomposed first: "ἀρχῇ" -> α ρ χ η + marks)."""
    return sum(1 for c in unicodedata.normalize("NFD", token) if "Α" <= c <= "ω")
_TOKEN_RE = re.compile(f"\\n|[{_SCRIPT}]+|[A-Za-zÀ-ɏæœ]+|\\d+|[^\\sA-Za-zÀ-ɏæœ\\d{_SCRIPT}]")
_HEADER_RE = re.compile(r"^\W*(\d+)?\W*[A-Z0-9 .,]*(?:GENESIN|GENES1N|GENE8IN)\W*(\d+)?\W*$")
_FOOTER_RE = re.compile(r"^\W*Ca\S{0,3}ini\s+o\S{1,3}era\b.*$", re.IGNORECASE)
_CHAPTER_RE = re.compile(r"^\s*C\s*[AÀ]\s*[PF]\s*(?:[UVÜ]\s*T\s*)?\.?\s+([IVXLCYl1]+|\w{1,6})\s*[.,]?\s*[.,]?\s*$", re.MULTILINE)
_MARKER_RE = re.compile(r"(?:(?<=\s)|^)(\d{1,2})\s?\.\s+(?=\S)")


def strip_accents(word: str) -> str:
    return "".join(c for c in unicodedata.normalize("NFD", word) if unicodedata.category(c) != "Mn")


def load_vocab(path: Path | None) -> set[str]:
    if path is not None:
        return {line.strip() for line in path.read_text(encoding="utf-8").splitlines() if line.strip()}
    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
    from db import get_connection
    with get_connection() as conn, conn.cursor() as cur:
        cur.execute(
            """SELECT DISTINCT lower(substring(s.text_la FROM t.char_start + 1 FOR t.char_end - t.char_start))
               FROM token t JOIN segment s ON s.id = t.segment_id JOIN work w ON w.id = s.work_id
               WHERE w.slug = 'institutio-1559' AND t.is_word""")
        return {r[0] for r in cur.fetchall()}


def unknown_rate(text: str, vocab: set[str]) -> float:
    words = [w.lower() for w in _WORD_RE.findall(text) if len(w) > 2]
    return sum(1 for w in words if strip_accents(w) not in vocab) / max(1, len(words))


def combine(text_layer: str, tesseract: str, vocab: set[str]) -> str:
    """Word-level combination of two OCRs of the same page (see docstring)."""
    def known(tok: str) -> bool:
        return strip_accents(tok.lower()) in vocab

    def known_ratio(tokens: list[str]) -> tuple[int, float]:
        words = [t for t in tokens if _WORD_RE.fullmatch(t) and len(t) > 1]
        return len(words), (sum(1 for w in words if known(w)) / len(words) if words else 0.0)

    # The base is the PDF's text layer -- its line breaks match the print
    # closely, and the verse numbers that start comments sit at line starts
    # (Tesseract's lines don't keep those as reliably: using it as the base
    # lost ~40 comment starts) -- unless the text layer is unusable on this
    # page (the Argumentum's second and third page).
    if unknown_rate(text_layer, vocab) > GARBLED_TEXT_LAYER:
        text_layer, tesseract = tesseract, text_layer
    a = _TOKEN_RE.findall(text_layer)
    b = _TOKEN_RE.findall(tesseract)
    # Compare without newlines (the two OCRs break lines differently), but
    # keep the base's newlines in the output: they carry the line starts.
    a_words = [t for t in a if t != "\n"]
    b_words = [t for t in b if t != "\n"]
    replacement: dict[int, list[str]] = {}   # base word index => tokens instead of it
    matcher = difflib.SequenceMatcher(a=a_words, b=b_words, autojunk=False)
    for op, a1, a2, b1, b2 in matcher.get_opcodes():
        if op == "replace" and a2 - a1 == b2 - b1:
            for k in range(a2 - a1):
                x, y = a_words[a1 + k], b_words[b1 + k]
                if _WORD_RE.fullmatch(x) and not known(x) and _WORD_RE.fullmatch(y) and known(y):
                    replacement[a1 + k] = [y]
        elif op in ("replace", "delete", "insert"):
            # Stretches the two OCRs cut up differently: take the other
            # OCR's reading when it is clearly the better Latin (e.g. a
            # garbled line in the base, or a line the base lost).
            n_a, ratio_a = known_ratio(a_words[a1:a2])
            n_b, ratio_b = known_ratio(b_words[b1:b2])
            if n_b >= 2 and ratio_b >= 0.7 and ratio_b - ratio_a >= 0.3:
                if a2 > a1:
                    replacement[a1] = b_words[b1:b2]
                    for k in range(a1 + 1, a2):
                        replacement[k] = []
                elif a1 < len(a_words):
                    replacement[a1] = b_words[b1:b2] + [a_words[a1]]
    out, i = [], 0
    for tok in a:
        if tok == "\n":
            out.append("\n")
            continue
        out.extend(replacement.get(i, [tok]))
        i += 1
    return detokenize(out)


def restore_headings(text: str, tesseract: str) -> str:
    """A chapter heading only Tesseract read (the text layer misses e.g.
    "CAPUT VIII.") is put back into the combined text, on its own line
    right before the words that follow it in Tesseract's text."""
    if _CHAPTER_RE.search(text):
        return text
    for h in _CHAPTER_RE.finditer(tesseract):
        following = _WORD_RE.findall(tesseract[h.end():])[:6]
        if len(following) < 3:
            continue
        pattern = r"\W+".join(re.escape(w) for w in following[:3])
        m = re.search(pattern, text)
        if m:
            line_start = text.rfind("\n", 0, m.start()) + 1
            # The verse number before those words belongs after the heading too.
            prefix = text[line_start:m.start()]
            cut = line_start if re.fullmatch(r"\s*\d{0,2}\s?\.?\s*", prefix) else m.start()
            text = text[:cut] + f"\n{h.group().strip()}\n" + text[cut:]
    return text


def detokenize(tokens: list[str]) -> str:
    text = ""
    for tok in tokens:
        if tok == "\n":
            text = text.rstrip(" ") + "\n"
        elif not text or text.endswith(("\n", " ", "(", "[", "„", '"')) and tok not in ".,;:!?)]":
            text += tok
        elif tok in ".,;:!?)]-":
            # "-" too: a line-end hyphen must stay attached to its word
            # ("pro-" + newline + "fectus") for dehyphenate() to rejoin it.
            text = text.rstrip(" ") + tok
        else:
            text += " " + tok
    return text


def clean_word_accents(text: str) -> str:
    return _WORD_RE.sub(lambda m: strip_accents(m.group()), text)


def clean_script_ocr(text: str) -> str:
    """Tesseract's lat+grc+heb output: drop the bidi marks it adds around
    Hebrew, and put punctuation it attached to the wrong (logical) side of a
    Hebrew word back after it (",יצר" -> "יצר,")."""
    text = re.sub("[\u200e\u200f\u202a-\u202e]", "", text)
    return re.sub(r"([.,;:!?])([\u0590-\u05FF\uFB1D-\uFB4F]+)", r"\2\1", text)


def load_hebrew_forms(path: Path | None) -> set[str]:
    """Every consonantal word form of the Hebrew Bible (hebrew_words, points
    and accents stripped, maqaf compounds split) plus Strong's lexical
    forms: a Hebrew token from the script pass is only believed if it is
    one of these (it also reads stray junk as "מ", "יי", "הפב")."""
    if path is not None:
        return {line.strip() for line in path.read_text(encoding="utf-8").splitlines() if line.strip()}
    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
    from db import get_connection
    with get_connection() as conn, conn.cursor() as cur:
        cur.execute(
            r"""SELECT DISTINCT regexp_replace(w, '[֑-ׇ]', '', 'g')
                FROM (SELECT unnest(string_to_array(word_text, E'־')) AS w FROM hebrew_words
                      UNION SELECT lemma FROM strongs_entries WHERE lang = 'HE' AND lemma IS NOT NULL) x""")
        return {r[0] for r in cur.fetchall()}


def merge_script_words(text: str, script_ocr: str, vocab: set[str], hebrew_forms: set[str]) -> str:
    """Put the Hebrew and Greek quotations from the lat+grc+heb pass into the
    combined text. The two Latin OCRs read Hebrew as Latin-looking junk
    ("IS1" for יצר, "N13" for ברא); the script pass reads it right, but
    also misreads some Latin as Hebrew, Greek or digits ("vocat" -> "70086")
    -- so only stretches where the combined text has no known Latin word
    are replaced, and only by the script pass's Hebrew/Greek tokens (plus
    their punctuation)."""
    a = _TOKEN_RE.findall(text)
    b = _TOKEN_RE.findall(clean_script_ocr(script_ocr))
    a_words = [t for t in a if t != "\n"]
    b_words = [t for t in b if t != "\n"]
    line_starts, k, after_newline = set(), 0, True
    for tok in a:
        if tok == "\n":
            after_newline = True
            continue
        if after_newline:
            line_starts.add(k)
        after_newline = False
        k += 1

    def known(tok: str) -> bool:
        return strip_accents(tok.lower()) in vocab

    replacement: dict[int, list[str]] = {}
    matcher = difflib.SequenceMatcher(a=a_words, b=b_words, autojunk=False)
    for op, a1, a2, b1, b2 in matcher.get_opcodes():
        if op not in ("replace", "insert"):
            continue
        script = [t for t in b_words[b1:b2] if _SCRIPT_RE.search(t)
                  and (not _HEBREW_RE.search(t) or re.sub("[֑-ׇ]", "", t) in hebrew_forms)]
        if not script:
            continue
        # Greek only when it's real words: the script pass turns stray Latin
        # junk into short Greek forms ("ἃ", "οἱ", "δὲ") far more often than
        # Calvin quotes Greek in this commentary.
        if not any(_HEBREW_RE.search(t) for t in script) and \
                not any(greek_letters(t) >= 3 for t in script):
            continue
        span = a_words[a1:a2]
        # Real Latin here (even "a", "et", "In") means the script pass misread
        # it. But the junk the Latin OCRs make of Hebrew ("IS 1" for יצר,
        # "D ^ N" for אלהים) has known-looking bits too -- capitals that
        # aren't a word's normal casing don't count.
        if any(_WORD_RE.fullmatch(t) and known(t) and (t.islower() or (t[0].isupper() and t[1:].islower()))
               for t in span):
            continue
        # A verse number ("9." at a line start or after a sentence) -- the
        # comment/verse structure hangs on these. Not the digits in junk
        # like "N 13." (for ברא), which follow a letter.
        def is_verse_number(k: int) -> bool:
            nxt = a_words[k + 1] if k + 1 < len(a_words) else ""
            prev = a_words[k - 1] if k > 0 else "."
            return a_words[k].isdigit() and nxt == "." and (k in line_starts or not _WORD_RE.fullmatch(prev))
        if any(is_verse_number(k) for k in range(a1, a2)):
            continue
        tail = [t for t in b_words[b1:b2] if not _SCRIPT_RE.search(t) and not _WORD_RE.fullmatch(t)
                and not t.isdigit()][-1:]  # keep trailing punctuation ("ברא.")
        new = script + tail
        if a2 > a1:
            replacement[a1] = new
            for k in range(a1 + 1, a2):
                replacement[k] = []
        elif a1 < len(a_words):
            replacement[a1] = new + [a_words[a1]]
    out, i = [], 0
    for tok in a:
        if tok == "\n":
            out.append("\n")
            continue
        out.extend(replacement.get(i, [tok]))
        i += 1
    return detokenize(out)


def page_text(pdf: pymupdf.Document, ocr_dir: Path, page: int, vocab: set[str],
              script_dir: Path | None = None, hebrew_forms: set[str] | None = None) -> tuple[str, int | None]:
    """Combined text of one page (header/footer removed) and its left CO column number."""
    text_layer = pdf[page].get_text()
    tesseract = (ocr_dir / f"p{page:04d}.txt").read_text(encoding="utf-8")
    text = combine(text_layer, tesseract, vocab)
    text = restore_headings(text, tesseract)
    script_file = script_dir / f"p{page:04d}.txt" if script_dir else None
    if script_file and script_file.exists():
        text = merge_script_words(text, script_file.read_text(encoding="utf-8"), vocab, hebrew_forms or set())
    column = None
    lines = []
    for line in text.splitlines():
        if not lines and not line.strip():
            continue
        # Running head anywhere on the page (the reading order doesn't
        # always put it first): all caps + "GENESIN", never real text.
        header = _HEADER_RE.match(line)
        if header:
            if header.group(1) and column is None:
                column = int(header.group(1))
            continue
        # ...or garbled by the OCR ("17 COMMENTAftlUS IN GENESIN. 18",
        # "43 COMMENTARH", "21 COM ^ ENTAMTJS") -- a short line near the
        # top with a column number and a mostly-capitals C/O-word.
        # The column numbers can also stand on lines of their own.
        if len(lines) < 3 and (is_garbled_header(line) or re.fullmatch(r"\W*\d{1,3}\W*", line)):
            if column is None:
                number = re.search(r"\d{1,3}", line)
                column = int(number.group()) if number else None
            continue
        if _FOOTER_RE.match(line):
            continue
        lines.append(line)
    return "\n".join(lines), column


# Single substitutions that undo the OCR confusions seen in this volume:
# "rn" for "m" (tarnen, earn, saltern), "e" for "c" (hie, nee, donee,
# seimus), "o" for "c" (neo, oommentarius), "l" for "I" at the start of a
# name (lahacob, loseph, lehova), and a few rarer ones.
_CONFUSIONS = [("rn", "m"), ("e", "c"), ("c", "e"), ("o", "c"), ("o", "e"), ("i", "l"), ("l", "i"),
               ("u", "n"), ("n", "u"), ("ii", "u"), ("in", "m")]


def correct_words(text: str, vocab: set[str]) -> tuple[str, int]:
    """Replace an unknown word by a one-substitution variant (see
    _CONFUSIONS) that is a known word occurring more often in this text, or
    a form this text itself uses far more often -- so a real but unusual
    word (a name, a rare form) that happens to be one substitution away from
    something is left alone unless the text shows the other reading is the
    usual one."""
    freq: dict[str, int] = {}
    for w in _WORD_RE.findall(text):
        freq[w.lower()] = freq.get(w.lower(), 0) + 1

    cache: dict[str, str | None] = {}

    def best(word: str) -> str | None:
        low = word.lower()
        if low in cache:
            return cache[low]
        result = None
        if len(low) > 2 and low not in vocab:
            candidates = set()
            for wrong, right in _CONFUSIONS:
                start = low.find(wrong)
                while start != -1:
                    candidates.add(low[:start] + right + low[start + len(wrong):])
                    start = low.find(wrong, start + 1)
            own = freq.get(low, 0)
            # Known word that is more common here than the OCR form; or,
            # for names the Institutio lacks ("Iahacob" vs OCR "lahacob"),
            # a form this text itself uses much more often.
            scored = [(freq.get(c, 0), c) for c in candidates
                      if (c in vocab and freq.get(c, 0) > own)
                      or (freq.get(c, 0) >= 20 and freq.get(c, 0) >= 3 * own)]
            if scored:
                result = max(scored)[1]
        cache[low] = result
        return result

    changes = 0

    def fix(m: re.Match) -> str:
        nonlocal changes
        word = m.group()
        replacement = best(word)
        if replacement is None:
            return word
        changes += 1
        if word[0].isupper():
            replacement = ("I" + replacement[1:]) if word[0] == "L" and replacement[0] == "i" else replacement.capitalize()
        return replacement

    return _WORD_RE.sub(fix, text), changes


def is_garbled_header(line: str) -> bool:
    stripped = line.strip()
    if _CHAPTER_RE.fullmatch(stripped) or re.match(r"\W*GENESIS\b", stripped):
        return False  # "CAPUT I." / "GENESIS." at the top of a page are content
    letters = [ch for ch in stripped if ch.isalpha()]
    upper = sum(ch.isupper() for ch in letters)
    return (len(stripped) <= 50
            and re.search(r"(?:^|\W)[CO0ÖÔ][A-Za-z^ÜüÖ]{4,}", stripped) is not None
            and upper >= 5 and upper >= 0.6 * len(letters))


def dehyphenate(text: str) -> str:
    return re.sub(r"(\w)-\s*\n\s*(\w)", r"\1\2", text)


def one_line(text: str) -> str:
    return re.sub(r"\s+", " ", text).strip()


def parse(full: str, columns: list[tuple[int, int | None]]) -> tuple[list[dict], list[str]]:
    """full: the combined text with "\\x00<column>\\x00" markers at page starts."""
    warnings: list[str] = []

    def column_at(pos: int) -> int | None:
        best = None
        for m in re.finditer(r"\x00(\d*)\x00", full[:pos]):
            best = int(m.group(1)) if m.group(1) else best
        return best

    headings = list(_CHAPTER_RE.finditer(full))
    if len(headings) != CHAPTERS:
        warnings.append(f"{len(headings)} chapter headings found, expected {CHAPTERS}: "
                        + ", ".join(repr(h.group().strip()) for h in headings))
    rows: list[dict] = []

    # Argumentum: everything before chapter 1, per paragraph (blank line).
    argument = full[:headings[0].start()] if headings else ""
    argument = re.sub(r"^.*?ARGUMENTUM\.?\s*", "", argument, count=1, flags=re.DOTALL)
    argument = re.sub(r"\n\s*GENESIS\.?\s*$", "", argument.strip())
    paragraphs = [p for p in re.split(r"\n\s*\n", argument) if len(one_line(p.replace("\x00", ""))) > 40]
    for n, p in enumerate(paragraphs, start=1):
        rows.append({"ref": f"Comm. Gen. arg.{n}", "kind": "argument", "chapter": None, "section": n,
                     "text": one_line(re.sub(r"\x00\d*\x00", " ", p)), "co_col": None})

    for idx, h in enumerate(headings):
        chapter = idx + 1
        end = headings[idx + 1].start() if idx + 1 < len(headings) else len(full)
        body = full[h.end():end]
        markers = list(_MARKER_RE.finditer(body))
        # Chapter text: markers counting up; the comments start at the first
        # line-start marker that doesn't continue that count.
        last = 0
        comment_start = None
        verse_markers = []  # only markers that continue the count: a stray
                            # number inside a verse is just text
        for m in markers:
            n = int(m.group(1))
            at_line_start = m.start() == 0 or body[m.start() - 1] == "\n"
            if at_line_start and last and n <= last:
                comment_start = m.start()
                break
            if n == last + 1 or (last and last < n <= last + 3):
                last = n
                verse_markers.append(m)
        if comment_start is None:
            warnings.append(f"chapter {chapter}: no start of the comments found")
            comment_start = len(body)
        text_part = body[:comment_start]
        verses = verse_markers
        for i, m in enumerate(verses):
            verse = int(m.group(1))
            v_end = verses[i + 1].start() if i + 1 < len(verses) else len(text_part)
            vtext = one_line(re.sub(r"\x00\d*\x00", " ", text_part[m.end():v_end]))
            if vtext:
                rows.append({"ref": f"Comm. Gen. {chapter} tekst.{verse}", "kind": "scripture",
                             "chapter": chapter, "section": verse, "text": vtext,
                             "co_col": column_at(h.end() + m.start())})
        comments = body[comment_start:]
        starts = [m for m in _MARKER_RE.finditer(comments)
                  if m.start() == 0 or comments[m.start() - 1] == "\n"]
        # Keep lemma starts in non-decreasing verse order (a line starting
        # with a lower number is a stray number inside a comment).
        kept, current = [], 0
        for m in starts:
            n = int(m.group(1))
            if n >= current and n <= max(last, current) + 5:
                kept.append(m)
                current = n
        seen: dict[int, int] = {}
        for i, m in enumerate(kept):
            verse = int(m.group(1))
            c_end = kept[i + 1].start() if i + 1 < len(kept) else len(comments)
            ctext = one_line(re.sub(r"\x00\d*\x00", " ", comments[m.start():c_end]))
            seen[verse] = seen.get(verse, 0) + 1
            suffix = "" if seen[verse] == 1 else chr(ord("a") + seen[verse] - 1)
            rows.append({"ref": f"Comm. Gen. {chapter}:{verse}{suffix}", "kind": "commentary",
                         "chapter": chapter, "section": verse, "text": ctext,
                         "co_col": column_at(h.end() + comment_start + m.start())})
        if last == 0:
            warnings.append(f"chapter {chapter}: no verse numbers in the chapter text")
    return rows, warnings


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--pdf", type=Path, default=RAW / "co23_cr51.pdf")
    ap.add_argument("--ocr-dir", type=Path, default=RAW / "ocr")
    ap.add_argument("--script-ocr-dir", type=Path, default=RAW / "ocr_script",
                    help="the lat+grc+heb pass (ocr_pdf_pages.py --lang lat+grc+heb); "
                         "skipped if the directory doesn't exist")
    ap.add_argument("--hebrew-forms", type=Path, default=None,
                    help="consonantal Hebrew word forms, one per line, instead of reading them "
                         "from hebrew_words / strongs_entries")
    ap.add_argument("--vocab", type=Path, default=None)
    ap.add_argument("-o", "--output", type=Path)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    vocab = {strip_accents(w) for w in load_vocab(args.vocab)}
    pdf = pymupdf.open(args.pdf)
    script_dir = args.script_ocr_dir if args.script_ocr_dir.exists() else None
    hebrew_forms = load_hebrew_forms(args.hebrew_forms) if script_dir else set()
    pieces, columns = [], []
    for page in range(FIRST_PAGE, LAST_PAGE + 1):
        text, column = page_text(pdf, args.ocr_dir, page, vocab, script_dir, hebrew_forms)
        columns.append((page, column))
        pieces.append(f"\x00{column if column is not None else ''}\x00\n{text}")
    full = clean_word_accents(dehyphenate("\n".join(pieces)))
    full, corrections = correct_words(full, vocab)
    print(f"[fix]   {corrections} words corrected by known OCR confusions")

    rows, warnings = parse(full, columns)
    for seq, r in enumerate(rows, start=1):
        r["seq"] = seq
    for w in warnings:
        print(f"[warn]  {w}")
    by_kind = {k: sum(1 for r in rows if r["kind"] == k) for k in ("argument", "scripture", "commentary")}
    all_text = " ".join(r["text"] for r in rows)
    print(f"[ok]    {len(rows)} segments {by_kind}; {len(all_text.split())} words; "
          f"unknown-word rate {100 * unknown_rate(all_text, vocab):.1f}%")

    if args.dry_run:
        return 0
    if args.output is None:
        ap.error("-o/--output is required unless --dry-run")
    with args.output.open("w", encoding="utf-8") as fh:
        for r in rows:
            fh.write(json.dumps(r, ensure_ascii=False) + "\n")
    print(f"[ok]    wrote {args.output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
