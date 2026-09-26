#!/usr/bin/env python3
"""Parse S.O. Los's Dutch translation of Calvin's Genesis commentary (1900,
public domain -- see fetch_calvin_genesis.py) into a translation layer
(JSONL, layer 'los1900'), aligned to the Latin segments of
parse_calvin_genesis_la.py.

The archive.org OCR text reads well, but its structure is noisy: running
heads ("GENESIS 1 : 1. 2"), page and margin numbers and stray junk lines
between the text; chapter headings garbled ("8de HOOFDSTUK." for 3de,
"HOOEDSTUK RT:", "36ste HOORDSLUIG"); and the verse numbers that open each
comment often misread ("le" for "1.", "5D." for "5.", "g." for "9.",
"80." for "30."). So:

  - chapters: headings are recognised loosely (a short line with a
    capitalised "HO..." word) and numbered by position, 1..50 (checked);
  - comments: after a chapter's Bible text, every paragraph that starts
    with a (possibly misread) verse number opens a comment; unnumbered
    paragraphs ("God. -- Bij Mozes vindt men...", a sub-lemma) belong to
    the comment before them. The Dutch comments are then matched to the
    Latin ones of the same chapter by a sequence alignment on their verse
    numbers, tolerating typical OCR digit confusions (3/8, 5/6, 1/7 ...).
    A Dutch comment left unmatched is joined to the one before it; a Latin
    comment left unmatched gets no Dutch text (reported);
  - Argumentum: the Dutch paragraphs are distributed over the Latin
    Argumentum paragraphs by their relative position in the text (the two
    OCRs split paragraphs differently) -- approximate at the boundaries;
  - Calvin's Latin Bible text: not taken from Los. His Dutch Bible text has
    its verse numbers in the margin, which the OCR separated from the text,
    so it can't be split per verse reliably; the app shows the Dutch Bible
    verse (SV/HSV) from its own Bible corpus there instead.

Usage:
    python scripts/parse_calvin_genesis_nl.py --latin /data/institutio/calvin_genesis_la.jsonl \\
        -o /data/institutio/calvin_genesis_nl.jsonl
    python scripts/parse_calvin_genesis_nl.py --raw-dir local/ --latin gen_la.jsonl --dry-run
"""
from __future__ import annotations

import argparse
import difflib
import json
import re
import sys
from pathlib import Path

RAW = Path("/data/institutio/raw/calvin_genesis")
LAYER = "los1900"
MODEL = "manual-transcription"
CHAPTERS = 50

_ARGUMENT_START_RE = re.compile(r"Wijl Gods oneindige wijsheid")
_ARGUMENT_END_RE = re.compile(r"HET EERSTE BOEK VAN MOZES")
# A verse number as the OCR may have read it, then "." / "," / ":" (or the
# "e" of a misread "1."), then the start of the lemma.
_LEMMA_RE = re.compile(r"^\s*(\d{1,2}[A-Za-z]?|[lILgsS]\d?|le|Le|LE)\s?[.,:]?\s+(?=[A-Z„\"“‘'(])")
_DIGITS = {"l": "1", "I": "1", "L": "1", "i": "1", "S": "5", "s": "5", "O": "0", "o": "0",
           "B": "8", "b": "6", "Z": "2", "g": "9"}
# Pairs of digits the OCR confuses (3 read as 8, 5 as 6, ...).
_CONFUSABLE = {frozenset(p) for p in ("38", "56", "17", "08", "68", "35", "69", "01", "27", "89")}


def is_heading(line: str) -> bool:
    s = line.strip()
    return len(s) <= 32 and re.search(r"\bH[O0Q][O0A-Z]{4,}", s) is not None and "GENESIS" not in s


def is_noise(line: str) -> bool:
    s = line.strip()
    if not s:
        return False
    if len(s) < 45 and re.search(r"GEN[EÉ]?S[IL1]S", s, re.IGNORECASE):
        return True  # running head
    if re.fullmatch(r"[\W\d]{1,12}", s):
        return True  # page / margin numbers
    letters = [c for c in s if c.isalpha()]
    if len(s) < 25 and (not letters or sum(c.isupper() for c in letters) > 0.6 * len(letters)):
        return not is_heading(line)  # stray capitals / scan junk
    return False


def trim_trailing_junk(volume: str) -> str:
    """Cut a volume's text after its last line of real Dutch prose: at least
    five words, most of them words this volume uses often."""
    words = re.findall(r"[a-zà-ÿ]+", volume.lower())
    counts: dict[str, int] = {}
    for w in words:
        counts[w] = counts.get(w, 0) + 1
    lines = volume.split("\n")
    for i in range(len(lines) - 1, -1, -1):
        line_words = re.findall(r"[A-Za-zÀ-ÿ]{2,}", lines[i])
        if len(line_words) >= 5 and sum(counts.get(w.lower(), 0) >= 20 for w in line_words) >= 0.7 * len(line_words):
            return "\n".join(lines[:i + 1])
    return volume


_WORD_TOKEN_RE = re.compile(r"[A-Za-zÀ-ÿ]+")


def line_pages(djvu_xml: str) -> list[int]:
    """The page (djvu OBJECT index = PDF page index) of each line of the
    archive.org text: its non-empty lines are the LINE elements of
    _djvu.xml, in order."""
    pages = []
    for index, obj in enumerate(re.split(r"<OBJECT\b", djvu_xml)[1:]):
        pages += [index] * obj.count("<LINE")
    return pages


def word_counts(texts: list[str]) -> dict[str, int]:
    """How often the book uses each word (lower case), words broken at a
    line end joined -- else "ko-/nen" makes "nen" look common."""
    counts: dict[str, int] = {}
    for t in texts:
        for w in _WORD_TOKEN_RE.findall(re.sub(r"(\w)-\s*\n\s*(\w)", r"\1\2", t)):
            counts[w.lower()] = counts.get(w.lower(), 0) + 1
    return counts


def _match_case(model: str, word: str) -> str:
    if model.isupper() and len(model) > 1:
        return word.upper()
    if model[:1].isupper():
        return word[:1].upper() + word[1:]
    return word.lower() if model.islower() else word


def _edit_distance(a: str, b: str) -> int:
    row = list(range(len(b) + 1))
    for i, ca in enumerate(a, 1):
        prev, row[0] = row[0], i
        for j, cb in enumerate(b, 1):
            prev, row[j] = row[j], min(row[j] + 1, row[j - 1] + 1, prev + (ca != cb))
    return row[-1]


def _better_reading(old: str, new: str, counts: dict[str, int], vocab: set[str], rare: int, known: int,
                    confirmed: bool, in_phrase: bool) -> bool:
    """Whether our pass's `new` should replace the archive.org `old` (one
    word of a differing stretch of `in_phrase` or not):

      - `new` is a real word: in the Statenvertaling (`vocab`), or used
        often in the book (not for words of one or two letters, where the
        OCR's own junk -- "Zn", "Zu", "gi" -- is common too);
      - `old` is no word of the Statenvertaling ("schoof", "van ouds"), or
        both our passes (full resolution and PDF) read otherwise
        (`confirmed`: "zien Nen" / "zich zullen");
      - a letter or two off ("Katn" / "Kaïn", "wijshcid" / "wijsheid");
        further only in a confirmed stretch of words ("Nen" / "zullen");
      - not just `old` with its ending cut or extended ("achts" / "acht",
        genuine inflections as often as not);
      - when `old` is not rare in the book, a much commoner one ("En" is
        never turned into "Zn")."""
    a, b = old.lower(), new.lower()
    if a == b:
        return True
    n_old, n_new = counts.get(a, 0), counts.get(b, 0)
    if b not in vocab and (n_new < known or len(b) <= 2):
        return False
    if a in vocab and not confirmed:
        return False
    if min(len(a), len(b)) < 0.5 * max(len(a), len(b)) or a.startswith(b) or b.startswith(a):
        return False
    d, longest = _edit_distance(a, b), max(len(a), len(b))
    if not (d == 1 or (d <= 2 and longest >= 5) or (d <= 3 and longest >= 8)
            or (confirmed and in_phrase and d <= longest // 2 + 1)):
        return False
    return n_old <= rare or n_new >= 3 * n_old


def fix_initial_j(text: str, counts: dict[str, int]) -> tuple[str, int]:
    """Both OCRs read the capital J of this type as F ("Facob", "Fozef",
    "Fuda"): a capitalised word that is far commoner with J ("Jacob" 638x
    to "Facob" 37x) gets the J."""
    fixed = 0

    def repl(m: re.Match) -> str:
        nonlocal fixed
        word = m.group()
        n_f, n_j = counts.get(word.lower(), 0), counts.get("j" + word[1:].lower(), 0)
        if n_j >= max(10, 5 * n_f):
            fixed += 1
            return "J" + word[1:]
        return word

    return re.sub(r"\bF[a-zà-ÿ]{2,}", repl, text), fixed


def combine_dutch(volume: str, djvu_xml: str, page_texts: dict[int, tuple[str, str | None]],
                  counts: dict[str, int], vocab: set[str], changes: list[tuple[int, str, str, str]],
                  rare: int = 2, known: int = 20) -> str:
    """Correct the archive.org OCR with our own Tesseract pass of the same
    pages (ocr_pdf_pages.py / fetch_los_hires_pages.py, nld+heb): per page
    the two word sequences are aligned, and where they differ word for word
    and the base has a word the book doesn't use often (< `known` times),
    our reading replaces it when _better_reading() finds it the better one
    -- "zien Nen" -> "zich zullen", "wijshcid" -> "wijsheid", "Katn" ->
    "Kaïn". Line and paragraph structure stay the base's."""
    lines = volume.split("\n")
    pages = line_pages(djvu_xml)
    by_page: dict[int, list[int]] = {}
    k = 0
    for i, line in enumerate(lines):
        if line.strip():
            if k < len(pages):
                by_page.setdefault(pages[k], []).append(i)
            k += 1
    if k != len(pages):
        print(f"[warn]  {k} text lines vs {len(pages)} djvu lines -- Dutch OCR not combined")
        return volume
    edits: dict[int, list[tuple[int, int, str]]] = {}
    for page, line_ids in by_page.items():
        if page not in page_texts:
            continue
        # our pass of the page, and a second one (the PDF pass next to a
        # full-resolution one) to confirm a reading with
        other, second = page_texts[page]
        base = [(i, m) for i in line_ids for m in _WORD_TOKEN_RE.finditer(lines[i])]
        tess = _WORD_TOKEN_RE.findall(re.sub(r"(\w)-\s*\n\s*(\w)", r"\1\2", other))
        second_words = " " + " ".join(_WORD_TOKEN_RE.findall(
            re.sub(r"(\w)-\s*\n\s*(\w)", r"\1\2", second or ""))).lower() + " "
        matcher = difflib.SequenceMatcher(a=[m.group().lower() for _, m in base],
                                          b=[w.lower() for w in tess], autojunk=False)
        for op, a1, a2, b1, b2 in matcher.get_opcodes():
            if op != "replace" or a2 - a1 != b2 - b1 or a2 - a1 > 3:
                continue
            span = base[a1:a2]
            if len({i for i, _ in span}) != 1:
                continue  # across a line end (hyphenation)
            old = [m.group() for _, m in span]
            new = tess[b1:b2]
            if all(counts.get(w.lower(), 0) >= known for w in old):
                continue
            confirmed = f" {' '.join(new).lower()} " in second_words
            if all(_better_reading(o, n, counts, vocab, rare, known, confirmed, len(old) > 1)
                   for o, n in zip(old, new)):
                for (i, m), o, n in zip(span, old, new):
                    if o.lower() != n.lower():
                        # the case of the base where only letters inside the
                        # word changed; our pass's where the first letter did
                        # ("Nen" -> "zullen", "rods" -> "Gods")
                        n = _match_case(o, n) if o[0].lower() == n[0].lower() else n
                        if o[0].isupper() and n[0].islower() and re.search(
                                r"(?:^|[.!?„\"“])\s*$", lines[i][:m.start()]):
                            n = n[0].upper() + n[1:]  # a sentence start
                        edits.setdefault(i, []).append((m.start(), m.end(), n))
                        changes.append((page, o, n, lines[i].strip()))
    for i, line_edits in edits.items():
        line = lines[i]
        for start, end, replacement in sorted(line_edits, reverse=True):
            line = line[:start] + replacement + line[end:]
        lines[i] = line
    return "\n".join(lines)


# Characters the archive.org OCR makes of specks, rules, page edges and the
# binding -- checked against the scans: none of them is in Los's text,
# except "(*)" (a note mark) and the brackets ("[ja ook").
_JUNK = "_«»<>=*&°/\\}{|%#@$©®+"
_TREMA = "äëïöüÄËÏÖÜ"
_VOWELS = "aeiouAEIOUäëïöüéèêóòô"
# Accents Los prints for emphasis or a contraction: "één", "ééne", "vóór",
# "zóó(zeer)", "òf ... òf"; "weêr", "daarmêe", "éên" ("weder", "mede").
# Others ("dát", "nú", "èn", "éene", "óntelbare") the scans have only here
# and there -- the OCR makes as many accents of the specks above letters
# ("hét", "mênschen", "lêvens") -- so those are decided per word, from the
# scan (los1900_scan_readings.json).
_ACCENTED_OK = re.compile(r"(?i)^(?:[eé]é[eé]?n(?:e|en|s|ig\w*)?|vóór(?:dat)?|zóó(?:zeer)?|òf|weêr\w*|\w*mêe|éên)$")
_PLAIN = set("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.,;:!?'\"()[]-’„”…—")


def _odd(token: str) -> bool:
    return any(c not in _PLAIN and not "֐" <= c <= "׿" for c in token)


def _plain_letters(word: str) -> str:
    import unicodedata
    return "".join(c for c in unicodedata.normalize("NFD", word) if not unicodedata.combining(c))


def _known(word: str, counts: dict[str, int], vocab: set[str]) -> bool:
    w = word.lower()
    return counts.get(w, 0) >= 3 or w in vocab


def clean_token(token: str, tess: str, counts: dict[str, int], vocab: set[str],
                line_end: bool) -> tuple[str, bool]:
    """A word of the archive.org OCR with unexpected characters cleaned up
    (see _JUNK), and whether the result still needs a look at the scan
    (an accent that may or may not be Los's emphasis, Greek or Hebrew the
    OCR garbled, ...). `tess` is our own pass's reading of the word."""
    w = token
    if w == "(*)" or re.fullmatch(r"[…—]+", w):
        return w, False
    # "be=" / "tenauwer=-" at a line end: the hyphen of a broken word
    if line_end:
        w = re.sub(r"=-?$", "-", w)
    w = w.replace("iĳ", "ij").replace("ĳ", "ij")
    w = w.replace("í", "i").replace("Í", "I").replace("â", "a").replace("Â", "A")
    w = re.sub(r"^[‘']t", "’t", w)
    w = re.sub(r"^([HGZhgz])ú(?=\W*$)", r"\1ij", w)  # the italic "ij" of "Hij", "Gij", "zij"
    # opening marks: specks, unless our pass has Los's „ there
    if re.match(r"^[“‚‘]", w):
        w = ("„" if tess.startswith("„") else "") + w[1:]
    w = re.sub(r"“$", "”", re.sub(r"‚$", ",", w))
    w = re.sub(r"“([.,;:!?]*)$", r"”\1", w)
    # between two words the OCR ran together: a speck ("en“ook", "onze‘schuld")
    w = re.sub(r"(?<=[A-Za-zÀ-ÿ.,:;’])[“‘‚]+(?=[A-Za-zÀ-ÿ„:])", " ", w)
    w = re.sub(r"(?<=[A-Za-zÀ-ÿ.,:;])‘$", "", w)
    w = re.sub(r"^\[ij\b", "Hij", w)  # the H of "Hij" read as "["
    stripped_junk = w.strip(_JUNK) != w
    w = w.strip(_JUNK)
    if not w or not re.search(r"[A-Za-zÀ-ÿ0-9֐-׿]", w):
        return "", False  # a speck by itself
    # "Jsraël", "Jzaäk": a capital I read as J
    m = re.match(r"^J([szr]\w+)", w)
    if m and _known("I" + m.group(1), counts, vocab):
        w = "I" + w[1:]
    # a trema is Los's only between vowels ("Izaäk", "Israël", "Saraï"), and
    # not on a common word the book has without it ("maär", "dië", "geën")
    chars = list(w)
    for i, c in enumerate(chars):
        if c in _TREMA:
            plain = _plain_letters(c)
            if i == 0 or chars[i - 1] not in _VOWELS:
                chars[i] = plain
    w = "".join(chars)

    def untrema(m: re.Match) -> str:
        part = m.group()
        plain = _plain_letters(part)
        return plain if counts.get(plain.lower(), 0) >= 20 and counts.get(part.lower(), 0) <= 2 else part

    # per word of a compound ("niet-doör")
    w = re.sub(r"[A-Za-zÀ-ÿ]*[äëïöüÄËÏÖÜ][A-Za-zÀ-ÿ]*", untrema, w)
    core = re.sub(r"^[^\wÀ-ÿ]+|[^\wÀ-ÿ]+$", "", w)
    if not _odd(w):
        # junk stripped off Greek / Hebrew garble ("EP}") leaves garble
        return w, stripped_junk and bool(re.search(r"[A-Za-z]", core)) and (
            len(core) < 2 or not _known(core, counts, vocab))
    # what is left: accents, junk inside a word, Greek / Hebrew garble
    if any(c in _JUNK for c in w):
        t_core = re.sub(r"^[^\wÀ-ÿ]+|[^\wÀ-ÿ]+$", "", tess)
        without = "".join(c for c in w if c not in _JUNK)
        if re.search(r"\d", core):
            # a number our pass read whole ("1/." -> "17."): its digits, the
            # base's punctuation after it
            if re.fullmatch(r"[\d:.,]+", t_core) and len(re.findall(r"\d", t_core)) >= len(re.findall(r"\d", w)):
                return t_core.rstrip(".,;:") + re.search(r"[.,;:!?]*$", w).group(), False
        elif not _odd(without) and _known(re.sub(r"^[^\wÀ-ÿ]+|[^\wÀ-ÿ]+$", "", without), counts, vocab):
            return without, False  # "t&e" -> "te", "’*t" -> "’t"
        elif t_core and not _odd(t_core) and _known(t_core, counts, vocab):
            return w.replace(core, t_core), False
        return w, True
    letters = re.sub(r"[^A-Za-zÀ-ÿ]", "", core)
    if all(c in _TREMA or not _odd(c) for c in letters):
        return w, False
    if _ACCENTED_OK.match(core):
        return w, False
    return w, True


def djvu_words(djvu_xml: str) -> list[tuple[int, list[str]]]:
    """Per LINE of _djvu.xml: its page and the coords of its words."""
    out = []
    for page, obj in enumerate(re.split(r"<OBJECT\b", djvu_xml)[1:]):
        for line in re.split(r"<LINE\b", obj)[1:]:
            out.append((page, re.findall(r'<WORD coords="([^"]+)"', line)))
    return out


def clean_characters(volume: str, vol: int, djvu_xml: str, page_texts: dict[int, tuple[str, str | None]],
                     counts: dict[str, int], vocab: set[str], readings: dict[str, dict],
                     changes: list[tuple[str, str, str]]) -> tuple[str, list[str]]:
    """Unexpected characters in the archive.org OCR (see _JUNK, clean_token):
    each word that has any gets the reading checked on the scan
    (`readings`, by volume:page:word coords) or else clean_token()'s.
    Returns the text and the words neither could settle."""
    lines = volume.split("\n")
    xml_lines = djvu_words(djvu_xml)
    text_lines = [i for i, line in enumerate(lines) if line.strip()]
    if len(text_lines) != len(xml_lines):
        print(f"[warn]  volume {vol}: {len(text_lines)} text lines vs {len(xml_lines)} djvu lines -- "
              "characters not cleaned")
        return volume, []
    by_page: dict[int, list[tuple[int, list[str]]]] = {}
    for i, (page, coords) in zip(text_lines, xml_lines):
        by_page.setdefault(page, []).append((i, coords))
    open_words: list[str] = []
    for page, page_lines in by_page.items():
        tokens = [(i, k, tok, coords[k] if len(coords) == len(lines[i].split()) else None)
                  for i, coords in page_lines for k, tok in enumerate(lines[i].split())]
        if not any(_odd(t[2]) for t in tokens):
            continue
        tess = page_texts.get(page, ("", None))[0].split()
        norm = lambda w: re.sub(r"[^a-z]", "", _plain_letters(w).lower())
        matcher = difflib.SequenceMatcher(a=[norm(t[2]) for t in tokens], b=[norm(w) for w in tess], autojunk=False)
        aligned: dict[int, str] = {}
        for op, a1, a2, b1, b2 in matcher.get_opcodes():
            if op in ("equal", "replace") and b2 > b1:
                for n in range(a1, a2):
                    aligned[n] = tess[min(b2 - 1, b1 + (n - a1) * (b2 - b1) // (a2 - a1))]
        new_tokens: dict[int, dict[int, str]] = {}
        for n, (i, k, tok, coords) in enumerate(tokens):
            if not _odd(tok):
                continue
            key = f"{vol}:{page}:{coords}"
            if key in readings:
                scan = readings[key]["scan"]
                new, look = (tok if scan is None else scan), False
            else:
                new, look = clean_token(tok, aligned.get(n, ""), counts, vocab, k == len(lines[i].split()) - 1)
                words = re.findall(r"[A-Za-zÀ-ÿ]+", new)
                if re.search("[א-ת]", aligned.get(n, "")) and (
                        look or not words or not all(_known(x, counts, vocab) for x in words)):
                    continue  # garbled Hebrew: mark_hebrew() / fill_hebrew() put it back
            if look:
                # left as the OCR read it: garble half cleaned ("}°P)" ->
                # "P)") would no longer be found as the Hebrew quotation
                open_words.append(f"{key}\t{tok}")
                continue
            if new != tok:
                new_tokens.setdefault(i, {})[k] = new
                changes.append((key, tok, new))
        for i, repl in new_tokens.items():
            parts = [repl.get(k, p) for k, p in enumerate(lines[i].split())]
            # a line of nothing but specks goes (a blank line would be a
            # paragraph break)
            lines[i] = " ".join(p for p in parts if p) or None
    return "\n".join(line for line in lines if line is not None), open_words


def paragraphs(text: str) -> list[str]:
    # " |" is the scan's column rule / margin, read as a character
    out = [re.sub(r"\s+", " ", re.sub(r"(?<!\S)\|(?!\S)", " ", p)).strip() for p in re.split(r"\n\s*\n", text)]
    out = [p for p in out if len(p) > 3]
    # A page break leaves a blank line where the running head and page
    # number were taken out -- mid-sentence ("met een nieuw gewaad" / "is
    # toegerust, ..."). A paragraph that doesn't end a sentence and is
    # followed by one starting in lower case is one paragraph.
    return join_broken_sentences(out, capitals=False)


def join_broken_sentences(paras: list[str], capitals: bool) -> list[str]:
    """Join paragraphs that a page break split mid-sentence: the first
    doesn't end a sentence, and the next starts in lower case -- or, with
    `capitals`, the first ends on a word or comma ("wijst" / "Hij niet
    alleen"), unless the next opens a comment with its verse number.
    `capitals` only in the commentary: in the Bible text before it a verse
    often ends on a comma and the next starts with "En", and joining those
    turned verses into a long numbered paragraph taken for the first comment."""
    merged: list[str] = []
    for p in paras:
        if merged and not re.search(r"[.!?:;”’\"')—-]$", merged[-1]) and (
                re.match(r"[a-zà-ÿ(„‘]", p)
                or (capitals and re.search(r"[\w,]$", merged[-1]) and not lemma_number(p)[0])):
            merged[-1] += " " + p
        else:
            merged.append(p)
    return merged


# A comment can also start mid-paragraph, when the print's paragraph break
# was lost: sentence end, the verse number (". „8. Ook had de Heere",
# ". 27. En Abraham nam"), a capital. A false hit (a number in a reference)
# is harmless: it just doesn't fit the verse sequence in align() and is
# joined back to the comment before it.
_INLINE_LEMMA_RE = re.compile(r"(?<=[.!?”’])\s+[„|]?\s*(?=(?:\d{1,2}|[lI]\d?)\s?[.,]\s+[A-Z„])")


def split_inline_lemmas(paras: list[str]) -> list[str]:
    out = []
    for p in paras:
        out.extend(piece.strip() for piece in _INLINE_LEMMA_RE.split(p) if piece.strip())
    return out


def lemma_number(paragraph: str) -> tuple[bool, int | None]:
    """(starts a comment?, verse number as read -- None if unreadable)."""
    m = _LEMMA_RE.match(paragraph)
    if not m:
        return False, None
    token = m.group(1)
    if token in ("le", "Le", "LE"):
        return True, 1
    digits = "".join(_DIGITS.get(c, c) for c in token)
    digits = re.sub(r"\D+$", "", digits)  # "5D" -> "5"
    if not digits or not digits.isdigit():
        return True, None
    # A bare letter must still look like a misread number (+ lemma).
    return True, int(digits)


# The lemma (the quoted Bible words that open a comment) is set in italics,
# which the OCR reads worse: "Zn"/"Zu"/"Zoen" for "En", "F" for "J"
# ("Fozef", "Fuda"). Fixed only there, never in the running text.
_LEMMA_FIXES = [(re.compile(r"^(Z[nu]|Zoen|Zr|Zm)\b"), "En"),
                (re.compile(r"\bF(ozef|osef|uda|udah|acob|akob|ehova|ordaan|ezus|ethro)\b"), r"J\1")]


def normalise_lemma(text: str, verse: int) -> str:
    """Put the matched Latin verse number in place of the one the OCR read
    ("83." for 33, "le" for 1.) and fix the italic lemma's usual misreads."""
    m = _LEMMA_RE.match(text)
    if not m:
        return text
    rest = text[m.end():]
    head, sep, tail = rest.partition(". ")
    for pattern, repl in _LEMMA_FIXES:
        head = pattern.sub(repl, head)
    return f"{verse}. {head}{sep}{tail}"


# ── Hebrew quotations ─────────────────────────────────────────────────────────
# Los prints Hebrew pointed (בָּרָא, יָצַר); the archive.org OCR turns it into
# junk ("NN", "’9%°") and Tesseract can't read the points either. So the
# Hebrew is rebuilt from three sources:
#   where:   a nld+heb OCR pass over Los's scans (ocr_pdf_pages.py) finds
#            the Hebrew words; their Dutch neighbours locate the same spot in
#            the archive.org text, whose junk there becomes a ⟦H:guess⟧ marker;
#   letters: the matched Latin comment -- Los translated from the Latin, so
#            the n-th Hebrew word of a Dutch comment is the n-th of the Latin
#            one, which the lat+grc+heb pass reads well (unpointed, as printed
#            in the Calvini Opera);
#   points:  the same consonants in that verse / chapter of the Hebrew Bible
#            text (cantillation removed: בָּרָ֣א -> בָּרָא), else Strong's
#            lexical form (H3335 יָצַר), else left unpointed.

_HEB = "\u0590-\u05FF\uFB1D-\uFB4F"
_HEB_WORD_RE = re.compile(f"[{_HEB}]+")
_POINTS_RE = re.compile("[\u0591-\u05C7]")
_HEB_LETTER_RE = re.compile("[\u05D0-\u05EA]")
_CANTILLATION_RE = re.compile("[\u0591-\u05AF\u05BD\u05C0\u05C3]")
# ⟦H:<what the nld+heb pass read>‖<the archive.org OCR's junk there>⟧ -- the
# junk is kept so an unresolved marker can be put back as it was.
_MARKER_RE = re.compile(r"⟦H:([^‖⟧]*)‖([^⟧]*)⟧")
_DUTCH_WORD_RE = re.compile(r"[A-Za-zÀ-ÿ]+")


def consonants(word: str) -> str:
    return _POINTS_RE.sub("", word)


def clean_heb_ocr(text: str) -> str:
    text = re.sub("[\u200e\u200f\u202a-\u202e]", "", text)
    text = re.sub(r"(\w)-\s*\n\s*(\w)", r"\1\2", text)
    return re.sub(f"([.,;:!?])([{_HEB}]+)", r"\2\1", text)


def mark_hebrew(text: str, page_texts: list[str]) -> tuple[str, int, int]:
    """Replace the junk the archive.org OCR made of each Hebrew quotation by
    a ⟦H:guess⟧ marker, located via the Dutch words around the quotation in
    the nld+heb pass. Pages are processed in order, searching forward only.
    Returns (text, found, not located)."""
    cursor, found, missed = 0, 0, 0
    for page in page_texts:
        tokens = re.findall(f"[{_HEB}]+|[A-Za-zÀ-ÿ]+", clean_heb_ocr(page))
        i = 0
        while i < len(tokens):
            if not _HEB_WORD_RE.fullmatch(tokens[i]):
                i += 1
                continue
            j = i
            while j < len(tokens) and _HEB_WORD_RE.fullmatch(tokens[j]):
                j += 1
            before = [t for t in tokens[max(0, i - 3):i] if not _HEB_WORD_RE.fullmatch(t)]
            after = [t for t in tokens[j:j + 3] if not _HEB_WORD_RE.fullmatch(t)]
            guess = " ".join(tokens[i:j])
            i = j
            # A lone letter is mostly Tesseract seeing Hebrew in a smudge
            # or a Latin letter ("א", "ו", "ה" all over the page).
            if len(_HEB_LETTER_RE.findall(guess)) < 2:
                continue
            if len(before) < 2 or len(after) < 2:
                missed += 1
                continue
            # The quotation's junk may run over a line end; and the two
            # Dutch OCRs sometimes differ in one context word, so fall back
            # to fewer context words on either side.
            sep = r"[\W\d_]*"
            m = None
            for b_n, a_n in ((len(before), 2), (2, 1), (1, 2)):
                ctx_before, ctx_after = before[-b_n:], after[:a_n]
                if len(ctx_before) < b_n or len(ctx_after) < a_n:
                    continue
                pattern = (sep.join(re.escape(w) for w in ctx_before) + r"(?P<junk>[\s\S]{1,40}?)"
                           + r"(?<![A-Za-zÀ-ÿ])" + sep.join(re.escape(w) for w in ctx_after) + r"(?![A-Za-zÀ-ÿ])")
                m = re.compile(pattern, re.IGNORECASE).search(text, cursor, cursor + 60000)
                # Never across a verse number at a line start ("\n\n18. Is niet
                # goed...") -- the comment structure hangs on those. A blank
                # line alone is fine: page breaks mid-sentence leave one
                # ("Het Hebr. woord \n\n„95 (phalah)").
                if m and not re.search(r"\n\s*(?:\d{1,2}|[lIS]\d?)\s?[.,]\s", m.group("junk")) \
                        and not (_DUTCH_WORD_RE.search(m.group("junk").strip(" .,;:„”’\"'()"))
                                 and len(m.group("junk").strip()) > 12):
                    break
                m = None
            if not m:
                missed += 1
                continue
            junk_start, junk_end = m.span("junk")
            # keep the punctuation after the junk (the quotation's own ".")
            core = m.group("junk").rstrip(" .,;:”’\"'")
            text = (text[:junk_start] + f" ⟦H:{guess}‖{core.strip()}⟧" + text[junk_start + len(core):])
            cursor = junk_start + len(guess) + 6
            found += 1
    return text, found, missed


def load_dutch_vocab(path: Path | None) -> set[str]:
    """The words (lower case) of the Statenvertaling -- the spelling closest
    to Los's 1900 Dutch in the database -- as the list of real words the OCR
    correction checks against."""
    if path is not None:
        return {w.lower() for w in json.loads(path.read_text(encoding="utf-8"))}
    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
    from db import get_connection
    with get_connection() as conn, conn.cursor() as cur:
        cur.execute("""SELECT DISTINCT lower(w) FROM translation_verses tv
                       JOIN translations t ON t.id = tv.translation_id,
                       regexp_split_to_table(tv.verse_text, '[^[:alpha:]]+') AS w
                       WHERE t.code = 'SV' AND w <> ''""")
        return {r[0] for r in cur.fetchall()}


def load_hebrew_lexicon(path: Path | None) -> tuple[dict, dict]:
    """(Genesis Bible words: consonants -> [(chapter, verse, pointed)],
        Strong's: consonants -> [(pointed lemma, is a verb)])."""
    if path is not None:
        data = json.loads(path.read_text(encoding="utf-8"))
        bible_rows, strongs_rows = data["bible"], data["strongs"]
    else:
        sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
        from db import get_connection
        with get_connection() as conn, conn.cursor() as cur:
            cur.execute("SELECT chapter, verse, word_text FROM hebrew_words WHERE book_id = 1")
            bible_rows = cur.fetchall()
            cur.execute("SELECT lemma, pos FROM strongs_entries WHERE lang = 'HE' AND lemma IS NOT NULL")
            strongs_rows = [list(r) for r in cur.fetchall()]
    bible: dict[str, list] = {}
    for chapter, verse, word in bible_rows:
        for part in word.split("\u05BE"):  # maqaf-joined words separately
            pointed = _CANTILLATION_RE.sub("", part)
            bible.setdefault(consonants(pointed), []).append((chapter, verse, pointed))
    strongs: dict[str, list] = {}  # consonants -> [(pointed, is_verb)]
    for row in strongs_rows:
        lemma, pos = (row, None) if isinstance(row, str) else (row[0], row[1])
        pointed = _CANTILLATION_RE.sub("", lemma)
        strongs.setdefault(consonants(pointed), []).append((pointed, (pos or "").strip() == "v"))
    return bible, strongs


def vocalise(word: str, chapter: int | None, verse: int | None, bible: dict, strongs: dict,
             guess: str = "") -> str:
    """Pointed form of an unpointed word: the one in this verse, else in
    this chapter; otherwise, among all Genesis forms and Strong's lexical
    forms with these consonants, the one whose vowel points look most like
    what the Dutch OCR saw (`guess` -- its letters are poor, but it often
    catches some points: "'צךָ" has the qamats of יָצַר, not the tsere of
    the noun יֵצֶר), then a unique Strong's form, then the most frequent."""
    bare = consonants(word)
    candidates = bible.get(bare, [])
    for scope in ((lambda c, v: c == chapter and v == verse), (lambda c, v: c == chapter)):
        forms = {p for c, v, p in candidates if scope(c, v)}
        if len(forms) == 1:
            return forms.pop()
    lexical = {p for p, _ in strongs.get(bare, [])}
    verbs = {p for p, is_verb in strongs.get(bare, []) if is_verb}
    counts: dict[str, int] = {}
    for _, _, p in candidates:
        counts[p] = counts.get(p, 0) + 1
    options = set(counts) | lexical
    if not options:
        return bare
    guess_points = "".join(_POINTS_RE.findall(guess))
    if guess_points and len(options) > 1:
        # Ties (the OCR caught only a point or two) go to a verb: Calvin
        # quotes a lexical form mostly to discuss a verb ("verbum יצר").
        scored = sorted(((difflib.SequenceMatcher(a=guess_points, b="".join(_POINTS_RE.findall(o))).ratio(),
                          o in verbs, o in lexical, counts.get(o, 0), o) for o in options), reverse=True)
        if scored[0][0] > 0:
            return scored[0][-1]
    if len(lexical) == 1:
        return next(iter(lexical))
    # sorted: a tie (שׁוֹק / שׁוּק, no points to go by) goes the same way on
    # every run -- to the first in code point order, not to set order
    return max(sorted(options), key=lambda o: (counts.get(o, 0), o in lexical))


def fill_hebrew(dutch: str, latin_texts: list[str], chapter: int | None, verse: int | None,
                bible: dict, strongs: dict, stats: dict) -> str:
    """Replace the ⟦H:...⟧ markers of one Dutch comment by pointed Hebrew
    (see the block comment above). Markers and the Latin comment's Hebrew
    words are paired in order -- one to one when the counts agree, else by
    a monotonic alignment on how alike their consonants are. A marker left
    without a convincing Latin partner keeps its own reading only if that is
    a real Hebrew word; otherwise the original text goes back unchanged
    (wrong Hebrew would be worse than the junk it replaces)."""
    markers = list(_MARKER_RE.finditer(dutch))
    if not markers:
        return dutch
    latin_words = [w for t in latin_texts for w in _HEB_WORD_RE.findall(t)]
    guesses = [consonants(m.group(1)).replace(" ", "") for m in markers]
    chosen: list[str | None] = [None] * len(markers)
    if len(latin_words) == len(markers):
        chosen = list(latin_words)
    elif latin_words:
        n, k = len(markers), len(latin_words)
        sim = [[difflib.SequenceMatcher(a=guesses[i], b=latin_words[j]).ratio() for j in range(k)]
               for i in range(n)]
        score = [[0.0] * (k + 1) for _ in range(n + 1)]
        for i in range(1, n + 1):
            for j in range(1, k + 1):
                pair = score[i - 1][j - 1] + sim[i - 1][j - 1] if sim[i - 1][j - 1] >= 0.4 else -1.0
                score[i][j] = max(score[i - 1][j], score[i][j - 1], pair)
        i, j = n, k
        while i > 0 and j > 0:
            if sim[i - 1][j - 1] >= 0.4 and score[i][j] == score[i - 1][j - 1] + sim[i - 1][j - 1]:
                chosen[i - 1] = latin_words[j - 1]
                i, j = i - 1, j - 1
            elif score[i][j] == score[i - 1][j]:
                i -= 1
            else:
                j -= 1
    out, last = [], 0
    for m, word, guess in zip(markers, chosen, guesses):
        out.append(dutch[last:m.start()])
        # The Dutch pass's own reading only when it is a real word of 3+
        # letters over junk -- short ones are mostly Dutch misread as
        # Hebrew: "d. i." (dat is) came out as גת, "in" as תו / גו.
        junk = m.group(2).strip(" .,;:„”“’'\"()")
        if word is None and len(guess) >= 3 and (guess in bible or guess in strongs) \
                and not re.fullmatch(r"(?:[a-zà-ÿ]{1,3}\.?\s?)+", junk, re.IGNORECASE):
            word = guess
            stats["from_dutch_ocr"] += 1
        if word is None:
            out.append(m.group(2))
            stats["restored"] += 1
        else:
            out.append(vocalise(word, chapter, verse, bible, strongs, guess=m.group(1)))
            # the junk's end sometimes took the space with it ("יְהוָהaet")
            if dutch[m.end():m.end() + 1].isalnum() or dutch[m.end():m.end() + 1] in "„“(":
                out.append(" ")
            stats["filled"] += 1
        last = m.end()
    out.append(dutch[last:])
    return "".join(out)


def match_score(dutch: int | None, latin: int) -> float:
    if dutch is None:
        return 0.5
    if dutch == latin:
        return 3.0
    a, b = str(dutch), str(latin)
    if len(a) == len(b) and sum(x != y for x, y in zip(a, b)) == 1:
        x, y = next((x, y) for x, y in zip(a, b) if x != y)
        if frozenset((x, y)) in _CONFUSABLE:
            return 1.5
    return -3.0


def align(dutch: list[int | None], latin: list[int]) -> list[int | None]:
    """For each Dutch comment, the index of the Latin comment it matches
    (None = join to the previous one). Needleman-Wunsch on verse numbers."""
    gap = -1.0
    n, m = len(dutch), len(latin)
    score = [[0.0] * (m + 1) for _ in range(n + 1)]
    back = [[""] * (m + 1) for _ in range(n + 1)]
    for i in range(1, n + 1):
        score[i][0], back[i][0] = score[i - 1][0] + gap, "d"
    for j in range(1, m + 1):
        score[0][j], back[0][j] = score[0][j - 1] + gap, "l"
    for i in range(1, n + 1):
        for j in range(1, m + 1):
            options = [(score[i - 1][j - 1] + match_score(dutch[i - 1], latin[j - 1]), "m"),
                       (score[i - 1][j] + gap, "d"), (score[i][j - 1] + gap, "l")]
            score[i][j], back[i][j] = max(options)
    result: list[int | None] = [None] * n
    i, j = n, m
    while i > 0 or j > 0:
        step = back[i][j]
        if step == "m":
            result[i - 1] = j - 1
            i, j = i - 1, j - 1
        elif step == "d":
            i -= 1
        else:
            j -= 1
    return result


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--raw-dir", type=Path, default=RAW)
    ap.add_argument("--latin", type=Path, required=True, help="parse_calvin_genesis_la.py output")
    ap.add_argument("--hebrew-hires-dirs", type=Path, nargs=2,
                    default=[RAW / "los_ocr_hires1", RAW / "los_ocr_hires2"],
                    help="nld+heb OCR of full-resolution page images, where fetched")
    ap.add_argument("--hebrew-ocr-dirs", type=Path, nargs=2, default=[RAW / "los_ocr1", RAW / "los_ocr2"],
                    help="nld+heb OCR of Los's two volumes (ocr_pdf_pages.py); skipped if missing")
    ap.add_argument("--hebrew-lexicon", type=Path, default=None,
                    help='JSON {"bible": [[chapter, verse, word], ...], "strongs": [[lemma, pos], ...]} '
                         "instead of reading hebrew_words / strongs_entries from the database")
    ap.add_argument("--dutch-vocab", type=Path, default=None,
                    help="JSON list of Dutch words instead of reading the Statenvertaling's from the database")
    ap.add_argument("--scan-readings", type=Path, default=Path(__file__).with_name("los1900_scan_readings.json"),
                    help="words with unexpected characters as read on the scan, by volume:page:word coords")
    ap.add_argument("--ocr-changes", type=Path, default=None,
                    help="write the words corrected from our own OCR pass (page, old, new) here")
    ap.add_argument("-o", "--output", type=Path)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    latin = [json.loads(line) for line in args.latin.read_text(encoding="utf-8").splitlines() if line.strip()]
    volume_1, volume_2 = ((args.raw_dir / f"los1900_deel{n}.txt").read_text(encoding="utf-8", errors="replace")
                          for n in (1, 2))
    have_heb = all(d.exists() for d in args.hebrew_ocr_dirs)
    # Our own nld+heb pass per page: the full-resolution one where it exists
    # (fetch_los_hires_pages.py) -- the scan PDFs are too coarse for
    # Tesseract to find most Hebrew -- else the PDF pass.
    page_texts: list[dict[int, tuple[str, str | None]]] = [{}, {}]
    if have_heb:
        for n, (d, hires) in enumerate(zip(args.hebrew_ocr_dirs, args.hebrew_hires_dirs)):
            for p in sorted(d.glob("p*.txt")):
                better = hires / p.name
                pdf_pass = p.read_text(encoding="utf-8")
                page_texts[n][int(p.stem[1:])] = ((better.read_text(encoding="utf-8"), pdf_pass)
                                                  if better.exists() else (pdf_pass, None))
        # The archive.org OCR misreads some words ("dat zij zien Nen wachten"
        # for "zich zullen"); our pass often has them right.
        counts = word_counts([volume_1, volume_2])
        vocab = load_dutch_vocab(args.dutch_vocab)
        changes: list[tuple[int, str, str, str]] = []
        volume_1, volume_2 = (combine_dutch(v, (args.raw_dir / f"los1900_deel{n + 1}_djvu.xml").read_text(
                                  encoding="utf-8"), page_texts[n], counts, vocab, changes)
                              for n, v in enumerate((volume_1, volume_2)))
        print(f"[ocr]   {len(changes)} words corrected from our own OCR pass")
        if args.ocr_changes:
            args.ocr_changes.write_text("".join(f"{p}\t{a}\t{b}\t{line}\n" for p, a, b, line in changes),
                                        encoding="utf-8")
        # Characters that don't belong in the text ("«", "_", "ís", "maär",
        # accents made of specks): the scan's reading where it was checked
        # word for word, rules otherwise.
        readings = json.loads(args.scan_readings.read_text(encoding="utf-8")) if args.scan_readings.exists() else {}
        char_changes: list[tuple[str, str, str]] = []
        open_words: list[str] = []
        cleaned_volumes = []
        for n, v in enumerate((volume_1, volume_2)):
            v, left = clean_characters(v, n + 1, (args.raw_dir / f"los1900_deel{n + 1}_djvu.xml").read_text(
                encoding="utf-8"), page_texts[n], counts, vocab, readings, char_changes)
            cleaned_volumes.append(v)
            open_words += left
        volume_1, volume_2 = cleaned_volumes
        print(f"[chars] {len(char_changes)} words with unexpected characters corrected "
              f"({sum(k in readings for k, *_ in char_changes)} as read on the scan); "
              f"{len(open_words)} left unsettled")
        if args.ocr_changes:
            with args.ocr_changes.open("a", encoding="utf-8") as fh:
                fh.writelines(f"chars\t{a}\t{b}\t{k}\n" for k, a, b in char_changes)
                fh.writelines(f"open\t{w}\n" for w in open_words)
    counts = word_counts([volume_1, volume_2])
    (volume_1, j1), (volume_2, j2) = fix_initial_j(volume_1, counts), fix_initial_j(volume_2, counts)
    print(f"[ocr]   {j1 + j2} capital J's read as F restored")
    # Each volume ends with pages of scan junk (the back cover and binding,
    # hundreds of lines like "SNN", "we 4 GAD Al if)"), and volume 2 opens
    # with its title pages before its first chapter; without trimming, all
    # of it ends up in the last comment of a volume (Gen. 21:33, 50:26).
    v2_lines = volume_2.split("\n")
    first = next((i for i, line in enumerate(v2_lines) if is_heading(line)), 0)
    raw = trim_trailing_junk(volume_1) + "\n" + trim_trailing_junk("\n".join(v2_lines[first:]))
    raw = re.sub(r"(?:\s+\S{1,3})?\s+EINDE VAN HET [A-Z ]+DEEL\.?", "", raw)  # "EINDE VAN HET EERSTE DEEL."

    lines = raw.split("\n")
    cleaned = ["\x01" if is_heading(l) else ("" if is_noise(l) else l.rstrip()) for l in lines]
    text = re.sub(r"(\w)-\s*\n\s*(\w)", r"\1\2", "\n".join(cleaned))

    warnings: list[str] = []
    rows: list[dict] = []

    heb_stats = {"filled": 0, "from_dutch_ocr": 0, "restored": 0}
    if have_heb:
        pages = [page_texts[n][k][0] for n in (0, 1) for k in sorted(page_texts[n])]
        text, found, missed = mark_hebrew(text, pages)
        print(f"[heb]   {found} Hebrew quotations located in the text, {missed} not")
        bible, strongs = load_hebrew_lexicon(args.hebrew_lexicon)
    else:
        warnings.append("no Hebrew OCR pass found -- Hebrew left as the OCR read it")

    # Argumentum.
    start, end = _ARGUMENT_START_RE.search(text), _ARGUMENT_END_RE.search(text)
    latin_args = [r for r in latin if r["kind"] == "argument"]
    if start and end and latin_args:
        dutch_paras = join_broken_sentences(paragraphs(text[start.start():end.start()]), capitals=True)
        la_len = [len(r["text"]) for r in latin_args]
        la_total, nl_total = sum(la_len), sum(len(p) for p in dutch_paras)
        bounds, acc = [], 0
        for length in la_len:
            acc += length
            bounds.append(acc / la_total)
        assigned: dict[int, list[str]] = {}
        pos = 0
        for p in dutch_paras:
            middle = (pos + len(p) / 2) / nl_total
            k = next(i for i, b in enumerate(bounds) if middle <= b or i == len(bounds) - 1)
            assigned.setdefault(k, []).append(p)
            pos += len(p)
        for k, r in enumerate(latin_args):
            if k in assigned:
                dutch = "\n\n".join(assigned[k])
                if have_heb:
                    dutch = fill_hebrew(dutch, [r["text"]], None, None, bible, strongs, heb_stats)
                rows.append({"ref": r["ref"], "layer": LAYER, "text": dutch, "model": MODEL})
    else:
        warnings.append("Argumentum not found")

    # Chapters.
    chapters = text.split("\x01")[1:]
    if len(chapters) != CHAPTERS:
        warnings.append(f"{len(chapters)} chapter headings found, expected {CHAPTERS}")
    n_matched = n_latin = 0
    for ch, body in enumerate(chapters[:CHAPTERS], start=1):
        la_comments = [r for r in latin if r["kind"] == "commentary" and r["chapter"] == ch]
        n_latin += len(la_comments)
        paras = paragraphs(body)
        first_verse = la_comments[0]["section"] if la_comments else 1
        # The comments start at the first long paragraph opening with the
        # first commented verse (or, failing that, any long numbered one).
        starts = [k for k, p in enumerate(paras) if lemma_number(p)[0] and len(p) > 250]
        comment_start = next((k for k in starts if match_score(lemma_number(paras[k])[1], first_verse) > 1),
                             starts[0] if starts else None)
        if comment_start is None:
            warnings.append(f"chapter {ch}: no comments found")
            continue
        blocks: list[list] = []  # [verse as read, text]
        for p in split_inline_lemmas(join_broken_sentences(paras[comment_start:], capitals=True)):
            is_lemma, number = lemma_number(p)
            if is_lemma or not blocks:
                blocks.append([number, p])
            else:
                blocks[-1][1] += "\n\n" + p
        mapping = align([b[0] for b in blocks], [r["section"] for r in la_comments])
        texts: dict[int, list[str]] = {}
        current = None
        for block, target in zip(blocks, mapping):
            if target is not None:
                block[1] = normalise_lemma(block[1], la_comments[target]["section"])
            current = target if target is not None else current
            if current is None:
                current = 0
            texts.setdefault(current, []).append(block[1])
        for idx, r in enumerate(la_comments):
            if idx in texts:
                n_matched += 1
                dutch = "\n\n".join(texts[idx])
                if have_heb:
                    # This Dutch text may also hold the following Latin
                    # comments that got no Dutch of their own.
                    latin_texts = [r["text"]]
                    for nxt in range(idx + 1, len(la_comments)):
                        if nxt in texts:
                            break
                        latin_texts.append(la_comments[nxt]["text"])
                    dutch = fill_hebrew(dutch, latin_texts, ch, r["section"], bible, strongs, heb_stats)
                rows.append({"ref": r["ref"], "layer": LAYER, "text": dutch, "model": MODEL})
            else:
                warnings.append(f"{r['ref']}: no Dutch comment matched")

    for w in warnings:
        print(f"[warn]  {w}")
    print(f"[ok]    {len(rows)} Dutch rows; comments matched {n_matched}/{n_latin}")
    if have_heb:
        left = sum(len(_MARKER_RE.findall(r["text"])) for r in rows)
        print(f"[heb]   {heb_stats['filled']} Hebrew words filled in "
              f"({heb_stats['from_dutch_ocr']} from the Dutch OCR's own reading), "
              f"{heb_stats['restored']} left as they were; {left} markers left")
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
