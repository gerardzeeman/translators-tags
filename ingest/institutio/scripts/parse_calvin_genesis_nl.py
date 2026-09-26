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
_LEMMA_RE = re.compile(r"^\s*(\d{1,2}[A-Za-z]?|[lILgsS]\d?|le|Le|LE|Ll|[lI][lI1]|1[lI])\s?[.,:]?\s+(?=[A-Z„\"“‘'(])")
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


def margin_lines(djvu_xml: str) -> list[bool]:
    """Per LINE of _djvu.xml: whether it lies in the page's top or bottom
    margin -- the running head with the page number ("GENEsIs 15 : 10 en
    11. 325"), the bottom's signature marks, and the specks around them.
    The OCR reads the running head in ever new ways ("GENBSISe7.",
    "GEeNEsis", "GrNesis"), and a head following a line that ends on a
    hyphen was even joined to the word it broke ("hoe-" + "GENEsIis ..."),
    so the head is recognised by where it is on the page, not by its text."""
    out = []
    for obj in re.split(r"<OBJECT\b", djvu_xml)[1:]:
        height = int(re.search(r'height="(\d+)"', obj).group(1))
        for line in re.split(r"<LINE\b", obj)[1:]:
            boxes = [tuple(map(int, c.split(","))) for c in re.findall(r'<WORD coords="([^"]+)"', line)]
            if not boxes:
                out.append(False)
                continue
            bottom, top = max(b[1] for b in boxes), min(b[3] for b in boxes)
            out.append(bottom < 0.085 * height or top > 0.93 * height)
    return out


_ABBREVIATIONS = {"enz", "vs", "vrs", "bl", "nl", "vgl", "hfdst", "hebr", "cap", "dergl", "bijv", "hoofdst",
                  "d", "i", "l", "m", "n", "t", "z", "o", "v", "e", "a", "b", "c", "vert", "blz", "ps", "ed"}


# Words a sentence never ends on: a full stop or colon after them is a speck
# whatever follows ("van: Jacob", "bij. „wijze").
_FUNCTION_WORDS = {"van", "de", "het", "een", "den", "der", "des", "te", "tot", "met", "bij", "en", "of", "dat",
                   "zijne", "hunne", "eene"}


def _speck_score(words: list[str]) -> float:
    """Share of a page's words with punctuation where the print has none:
    a full stop or colon before a word in lower case, or a hyphen or colon
    inside a word ("werd-dus", "zich:naar") -- the specks of a badly
    printed page (deel 2, blz. 367-380), read as punctuation."""
    bad = 0
    for a, b in zip(words, words[1:]):
        core = re.sub(r"[^\wÀ-ÿ]", "", a).lower()
        if re.search(r"[a-zà-ÿ]{2}[.:]$", a) and re.match(r"[a-zà-ÿ]", b) and core not in _ABBREVIATIONS:
            bad += 1
        if re.search(r"[a-zà-ÿ][:\-][a-zà-ÿ]", a):
            bad += 1
    return bad / max(1, len(words))


def clean_specks(words: list[str], counts: dict[str, int]) -> list[str]:
    """On a badly printed page (_speck_score), drop the punctuation that is
    specks: see _speck_score; also a stray ‘ before a word ("‘van")."""
    known = lambda w: counts.get(w.lower(), 0) >= 3
    out = []
    for n, w in enumerate(words):
        nxt = words[n + 1] if n + 1 < len(words) else ""
        # "‘van", "'des" -- but not "’t" / "'t"
        w = re.sub(r"^[‘'](?=[a-zà-ÿ]{2})", "", w)
        # "werd-dus", "zich:naar", "hetders--wdren" -> two words
        parts = re.split(r"(?<=[a-zà-ÿ])(?:--?|:)(?=[a-zà-ÿ])", w)
        if len(parts) > 1 and all(known(re.sub(r"[^\wÀ-ÿ]", "", p)) for p in parts) \
                and not known(re.sub(r"[^\wÀ-ÿ]", "", "".join(parts))):
            w = " ".join(parts)
        core = re.sub(r"[^\wÀ-ÿ]", "", w.split()[-1]).lower() if w.split() else ""
        if re.search(r"[a-zà-ÿ]{2}[.:]$", w) and core not in _ABBREVIATIONS and known(core) and (
                re.match(r"[a-zà-ÿ„]", nxt) or core in _FUNCTION_WORDS):
            w = w[:-1]
        out.append(w)
    return out


def _prose(line: str, counts: dict[str, int]) -> bool:
    """A line of ordinary Dutch: three or more words, most of them used in
    the book (a page whose text starts high has its first line in the top
    margin band)."""
    words = re.findall(r"[a-zà-ÿ]{3,}", line)
    return len(words) >= 3 and sum(counts.get(w, 0) >= 3 for w in words) >= 0.6 * len(words)


def clean_characters(volume: str, vol: int, djvu_xml: str, page_texts: dict[int, tuple[str, str | None]],
                     counts: dict[str, int], vocab: set[str], readings: dict[str, dict],
                     changes: list[tuple[str, str, str]], margin: list[str],
                     scan_pages: dict[str, str], specks: list[str]) -> tuple[str, list[str]]:
    """Unexpected characters in the archive.org OCR (see _JUNK, clean_token):
    each word that has any gets the reading checked on the scan
    (`readings`, by volume:page:word coords) or else clean_token()'s. Lines
    in the page margins are dropped (collected in `margin`).
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
        if not any(_odd(t[2]) or f"{vol}:{page}:{t[3]}" in readings for t in tokens):
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
            key = f"{vol}:{page}:{coords}"
            # (a reading from the scan also for garble in plain letters:
            # "DY (olam)" is עוֹלָם)
            if not _odd(tok) and key not in readings:
                continue
            if key in readings:
                scan = readings[key]["scan"]
                new, look = (tok if scan is None else scan), False
                if readings[key].get("intended"):
                    # Los's own misprint: the intended word, with what he
                    # printed and why -- ⟦C:intended‖printed‖note⟧, which
                    # the app shows marked, the note on hover
                    # (CommentaryController::dutchParagraphs)
                    new = f"⟦C:{readings[key]['intended']}‖{scan}‖{readings[key]['note']}⟧"
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
    # Badly printed pages: specks read as punctuation (see clean_specks),
    # the page's words taken as one stream (a speck can end a line).
    for page, page_lines in by_page.items():
        ids = [i for i, _ in page_lines if lines[i] is not None]
        words = [w for i in ids for w in lines[i].split()]
        if len(words) < 150 or _speck_score(words) < 0.02:
            continue
        # a speck at a line end that stands for the hyphen of a broken
        # word: "aan:" + "gezicht" is "aan-" + "gezicht"
        for a, b in zip(ids, ids[1:]):
            lines[a] = lines[a].rstrip()
            last, first = lines[a].split()[-1], lines[b].split()[0]
            if re.search(r"[a-zà-ÿ][.:]$", last) and re.match(r"[a-zà-ÿ]", first) and counts.get(
                    (re.sub(r"[^\wÀ-ÿ]", "", last) + re.sub(r"[^\wÀ-ÿ].*$", "", first)).lower(), 0) >= 3:
                lines[a] = lines[a][:-1] + "-" if not lines[a].endswith(" ") else lines[a]
        words = [w for i in ids for w in lines[i].split()]
        cleaned = clean_specks(words, counts)
        k = 0
        for i in ids:
            n = len(lines[i].split())
            new = " ".join(cleaned[k:k + n])
            if new != lines[i]:
                changes.append((f"{vol}:{page}", lines[i], new))
            lines[i] = new
            k += n
        specks.append(f"{vol}:{page}")
    # Pages too damaged for any OCR, read from the scan as a whole
    # (`scan_pages`, by volume:page): the page's text replaces its lines.
    from_scan: set[int] = set()
    for page, page_lines in by_page.items():
        if f"{vol}:{page}" in scan_pages:
            first = page_lines[0][0]
            for i, _ in page_lines:
                lines[i] = None
            lines[first] = scan_pages[f"{vol}:{page}"]
            from_scan.add(first)
            changes.append((f"{vol}:{page}", "(page)", "(read from the scan)"))
    # running heads, page numbers and signature marks go (see margin_lines)
    for i, in_margin in zip(text_lines, margin_lines(djvu_xml)):
        if in_margin and lines[i] is not None and i not in from_scan and not _prose(lines[i], counts) \
                and not is_heading(lines[i]):
            margin.append(lines[i])
            lines[i] = None
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


# Where join_broken_sentences() joined two paragraphs on a capital: kept in
# the joined text so assign_comments() can still start a comment there (a
# lemma whose number the OCR made a word of: "... weet ik niet," / "LEN De
# naam dier plaats."), removed afterwards.
SEAM = "\x02"


def join_broken_sentences(paras: list[str], capitals: bool, seam: str = "") -> list[str]:
    """Join paragraphs that a page break split mid-sentence: the first
    doesn't end a sentence, and the next starts in lower case -- or, with
    `capitals`, the first ends on a word or comma ("wijst" / "Hij niet
    alleen"), unless the next opens a comment with its verse number.
    `capitals` only in the commentary: in the Bible text before it a verse
    often ends on a comma and the next starts with "En", and joining those
    turned verses into a long numbered paragraph taken for the first comment."""
    merged: list[str] = []
    for p in paras:
        if merged and not re.search(r"[.!?:;”’\"')—-]$", merged[-1]) and re.match(r"[a-zà-ÿ(„‘]", p):
            merged[-1] += " " + p
        elif merged and capitals and not re.search(r"[.!?:;”’\"')—-]$", merged[-1]) \
                and re.search(r"[\w,]$", merged[-1]) and not lemma_number(p)[0]:
            merged[-1] += " " + seam + p
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


# A verse number read as something else altogether -- "D." for "5.", "9;"
# for "9." -- still opens a comment: a token of one or two letters or
# digits and a stop, at a paragraph start, before the (capitalised) lemma.
# A wrong hit ("N. B. Men...") is harmless: its number doesn't fit the
# verse sequence in align(), and it is joined to the comment before it.
_LEMMA_LOOSE_RE = re.compile(r"^\s*[„\"“]?\s*([0-9A-Z][0-9A-Za-z]?|[0-9][0-9A-Za-z]?)\s?[.,:;]\s+(?=[A-Z„\"“‘'(])")


def lemma_number(paragraph: str) -> tuple[bool, int | None]:
    """(starts a comment?, verse number as read -- None if unreadable)."""
    m = _LEMMA_RE.match(paragraph)
    if not m:
        loose = _LEMMA_LOOSE_RE.match(paragraph)
        if not loose:
            return False, None
        digits = re.sub(r"\D", "", "".join(_DIGITS.get(c, c) for c in loose.group(1)))
        return True, int(digits) if digits else None
    token = m.group(1)
    if token in ("le", "Le", "LE", "Ll"):  # "1." read as "le" or "Ll."
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


def mark_hebrew(text: str, page_texts: list[str], counts: dict[str, int] | None = None) -> tuple[str, int, int]:
    """Replace the junk the archive.org OCR made of each Hebrew quotation by
    a ⟦H:guess⟧ marker, located via the Dutch words around the quotation in
    the nld+heb pass. Pages are processed in order, searching forward only.
    "Junk" of lower-case words the book uses (`counts`) is no Hebrew:
    Tesseract saw Hebrew in a smudge nearby ("God in Abraham", "te ver-
    laten, en"), and filling it in put Hebrew in the middle of a sentence.
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
            junk_words = re.findall(r"[A-Za-zÀ-ÿ]+", m.group("junk"))
            if counts and junk_words and all(counts.get(w.lower(), 0) >= 3 and w[1:].islower()
                                             for w in junk_words):
                cursor = junk_end  # Dutch, not Hebrew
                continue
            if _HEB_LETTER_RE.search(m.group("junk")):
                cursor = junk_end  # already read from the scan (clean_characters)
                found += 1
                continue
            # keep the punctuation after the junk (the quotation's own ".",
            # the "(" or „ that opens the transliteration after it)
            core = m.group("junk").rstrip(" .,;:”’\"'(„“")
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
            stats.setdefault("left", []).append(f"{chapter}:{verse}\t{m.group(2)}\t{m.group(1)}")
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
    if len(a) < len(b) and a in b:
        return 1.0  # part of the number read ("5D." for "57.")
    if len(a) > len(b):
        # a digit too many ("835." for "35." -- or "36.", 5 and 6 being
        # confused too)
        tail = int(a[-len(b):])
        return 1.0 if tail == latin else (0.75 if match_score(tail, latin) > 1 else -3.0)
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


# (not after a colon: "Jesaja 65: 24" is no sentence end)
_SENTENCE_START_RE = re.compile(r"(?<=[.!?”’])\s+(?=\S)|\n\n|\x02")
# A verse number as the OCR may have read it at a sentence start ("5.",
# "D.", "o.", "9;", "„8."), before the capitalised lemma.
_NUMBER_TOKEN_RE = re.compile(r"^[„\"“|…]?\s*(?:[A-Za-z]{1,4}\s+(?=\d))?(?:([0-9A-Za-z]{1,3})\s?[.,:;]|(\d{1,2})(?=\s+[A-Z][a-z]))\s+(?=[A-Z„\"“‘'(])")


def _norm_word(w: str) -> str:
    """A word in a form the 1900 and the modernised SV spelling share:
    diacritics off, double letters single, "sch" as "s" ("zoo"/"zo",
    "oogen"/"ogen", "menschen"/"mensen")."""
    w = _plain_letters(w.lower()).replace("sch", "s").replace("y", "ij")
    return re.sub(r"(.)\1", r"\1", w)


def load_verses(path: Path | None) -> dict[tuple[int, int], set[str]]:
    """Genesis in the Statenvertaling, per (chapter, verse) as a set of
    normalised words: to recognise the lemma (the Bible words a comment
    opens with) in Los's text."""
    if path is not None:
        rows = json.loads(path.read_text(encoding="utf-8"))
    else:
        sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
        from db import get_connection
        with get_connection() as conn, conn.cursor() as cur:
            cur.execute("""SELECT tv.chapter, tv.verse, tv.verse_text FROM translation_verses tv
                           JOIN translations t ON t.id = tv.translation_id JOIN books b ON b.id = tv.book_id
                           WHERE t.code = 'SV' AND b.usfm_code = 'GEN'""")
            rows = cur.fetchall()
    return {(c, v): {_norm_word(w) for w in re.findall(r"[A-Za-zÀ-ÿ]+", t)} for c, v, t in rows}


# The end of a lemma: its full stop -- or the comma the OCR often made of
# it, before the capital that starts the comment ("aldaar, Uit andere").
_LEMMA_END = re.compile(r"[.!?]|[,:”](?=\s+[A-Z])")

_STOP = {"en", "de", "het", "een", "van", "dat", "die", "in", "hij", "zij", "tot", "te", "is", "den", "zijn"}


def lemma_likeness(piece: str, words: set[str]) -> float:
    """How much the opening words of a piece of Dutch (up to the lemma's
    closing full stop, at most ten words, a verse number skipped) look
    like the verse: the share of them found in it -- content words counting
    double, so "En hij zeide" alone does not make a lemma."""
    head = _NUMBER_TOKEN_RE.sub("", piece, count=1)
    lemma = re.split(_LEMMA_END, head, maxsplit=1)[0]
    ws = [_norm_word(w) for w in re.findall(r"[A-Za-zÀ-ÿ]+", lemma)][:10]
    if len(ws) < 2 or not words:
        return 0.0
    weight = lambda w: 1.0 if w in _STOP else 2.0
    return sum(weight(w) for w in ws if w in words) / sum(weight(w) for w in ws)


def lemma_words(piece: str) -> int:
    """Number of words in a piece's first sentence, a verse number skipped."""
    head = _NUMBER_TOKEN_RE.sub("", piece, count=1)
    return len(re.findall(r"[A-Za-zÀ-ÿ]+", re.split(_LEMMA_END, head, maxsplit=1)[0]))


def latin_lemma_verse(comment: dict, scripture: list[dict]) -> int | None:
    """The verse of Calvin's own Latin text of the chapter that his lemma
    quotes ("9. Cum omni anima vivente" is in his verse 10): where his
    numbering of the comment and the verse it is about differ, Los's lemma
    follows the verse ("11. Met alle levende ziel", SV 9:10)."""
    head = re.sub(r"^\d{1,2}[a-z]?\.\s*", "", comment["text"])
    words = [w.lower() for w in re.findall(r"[A-Za-z]{3,}", re.split(r"[.!?]", head, maxsplit=1)[0])][:8]
    if len(words) < 2:
        return None
    best = max(((sum(w in {x.lower() for x in re.findall(r"[A-Za-z]{3,}", v["text"])} for w in words) / len(words),
                 v["section"]) for v in scripture), default=(0.0, None))
    return best[1] if best[0] >= 0.6 else None


def assign_comments(text: str, la_comments: list[dict], chapter: int,
                    verses: dict[tuple[int, int], set[str]], skip: float = 4.0,
                    length_weight: float = 25.0) -> list[tuple[int, int] | None]:
    """Where each Latin comment of a chapter starts and ends in the Dutch
    comment text of the chapter: (start, end) character offsets, or None
    when it gets no Dutch of its own (then its Dutch sits in the comment
    before). A comment starts at a sentence start; which one is decided
    for the whole chapter at once (dynamic programming, starts in order)
    from three signals:

      - the verse number the sentence opens with, as read (match_score: 3
        for the verse itself, 1.5 for a digit the OCR confuses, 0.5 when
        unreadable, -3 for another number);
      - the lemma: the words after it against the verse in the SV (up to 4)
        -- this is what finds comments whose number the OCR lost ("D."
        for "5.", "o." for "3.", or none at all);
      - the length: Los translates evenly, so a comment that starts at 18%
        of Calvin's Latin of the chapter starts at about 18% of the Dutch.

    A sentence with no number costs 2 more to start at. Relying on the
    numbers alone put whole comments in the wrong place wherever one was
    misread."""
    raw = sorted({0} | {m.end() for m in _SENTENCE_START_RE.finditer(text) if m.end() < len(text)})
    # Never between a comment's lemma and what follows it: the sentence
    # after a verse number with its lemma ("15. En nu Jozefs broeders
    # zagen.") or after a bare number ("10.") is no start of its own.
    starts_at = []
    for k, p in enumerate(raw):
        if starts_at:
            previous = text[starts_at[-1]:p]
            if _NUMBER_TOKEN_RE.match(previous + " A") and len(previous) < 160 \
                    or re.fullmatch(r"\s*[„\"“]?\s*[0-9A-Za-z]{1,3}\s?[.,:;]\s*", previous):
                continue
        starts_at.append(p)
    n, m = len(starts_at), len(la_comments)
    if not m:
        return []
    total_nl = len(text) or 1
    la_pos, acc = [], 0
    for r in la_comments:
        la_pos.append(acc)
        acc += len(r["text"])
    total_la = acc or 1
    pieces = [text[p:p + 300] for p in starts_at]
    tokens = [_NUMBER_TOKEN_RE.match(p) for p in pieces]

    paragraph_starts = {0} | {m.end() for m in re.finditer(r"\n\n\s*|\x02", text)}
    chapter_verses = {v: w for (c, v), w in verses.items() if c == chapter}
    likeness: dict[tuple[int, int], float] = {}

    def like(i: int, verse: int) -> float:
        if (i, verse) not in likeness:
            likeness[i, verse] = lemma_likeness(pieces[i], chapter_verses.get(verse, set()))
        return likeness[i, verse]

    # per sentence start: (likeness, verse) of the verse its words fit best
    best_other = [max(((like(i, v), v) for v in chapter_verses), default=(0.0, 0)) if tokens[i] else (0.0, 0)
                  for i in range(n)]
    long_lemma = [lemma_words(pieces[i]) > 15 for i in range(n)]

    def cost(j: int, i: int) -> float:
        verse = la_comments[j]["section"]
        # the verse whose words Calvin's lemma quotes, where his numbering
        # and the Dutch one differ (latin_lemma_verse)
        alt = la_comments[j].get("lemma_verse") or verse
        tok = tokens[i]
        lemma = max(like(i, verse), like(i, alt))
        if tok:
            digits = re.sub(r"\D", "", "".join(_DIGITS.get(c, c) for c in (tok.group(1) or tok.group(2))))
            read = int(digits) if digits else None
            numbers = max(match_score(read, verse), match_score(read, alt))
            # the words are another verse's lemma: the number was misread
            # ("1. En dit zijn de dagen" is the lemma of 25:7)
            # (a verse further away than the next or previous: Calvin's
            # numbering and the SV's differ by one here and there)
            if lemma < 0.2 and best_other[i][0] - lemma > 0.35                     and min(abs(best_other[i][1] - v) for v in (verse, alt)) >= 2:
                numbers = min(numbers, -3.0)
        elif lemma >= 0.4 and lemma_words(pieces[i]) <= 12:
            numbers = -1.0   # a lemma without its number
        elif starts_at[i] in paragraph_starts:
            numbers = -3.0   # a paragraph: the number and lemma may be lost
        else:
            numbers = -7.0   # just a sentence in a paragraph
        # a lemma is a few words; a long first sentence is Los's Bible text
        # or ordinary prose
        penalty = 3.0 if long_lemma[i] and numbers < 3.0 else 0.0
        return (length_weight * abs(starts_at[i] / total_nl - la_pos[j] / total_la)
                - numbers - 5.0 * lemma + penalty)

    INF = float("inf")
    F = [INF] * n
    F[0] = 0.0
    choices: list[list[tuple[str, int]]] = []
    for j in range(1, m):
        G = [INF] * n
        back: list[tuple[str, int]] = [("", -1)] * n
        best, arg = INF, -1
        for i in range(n):
            if best < INF:
                c = best + cost(j, i)
                if c < G[i]:
                    G[i], back[i] = c, ("start", arg)
            if F[i] + skip < G[i]:
                G[i], back[i] = F[i] + skip, ("skip", i)
            if F[i] < best:
                best, arg = F[i], i
        F = G
        choices.append(back)
    chosen: list[int | None] = [None] * m
    i = min(range(n), key=lambda k: F[k])
    for j in range(m - 1, 0, -1):
        kind, prev = choices[j - 1][i]
        if kind == "start":
            chosen[j] = i
        i = prev
    chosen[0] = 0
    offsets = [starts_at[c] if c is not None else None for c in chosen]
    result: list[tuple[int, int] | None] = []
    for j, start in enumerate(offsets):
        if start is None:
            result.append(None)
            continue
        end = next((o for o in offsets[j + 1:] if o is not None), len(text))
        result.append((start, end))
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
    ap.add_argument("--scan-pages", type=Path, default=Path(__file__).with_name("los1900_scan_pages.json"),
                    help="whole pages read from the scan (too damaged for OCR), by volume:page")
    ap.add_argument("--verses", type=Path, default=None,
                    help="JSON [[chapter, verse, text], ...] of Genesis (SV) instead of reading it from the database")
    ap.add_argument("--ocr-changes", type=Path, default=None,
                    help="write the words corrected from our own OCR pass (page, old, new) here")
    ap.add_argument("-o", "--output", type=Path)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    latin = [json.loads(line) for line in args.latin.read_text(encoding="utf-8").splitlines() if line.strip()]
    verses = load_verses(args.verses)
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
        scan_pages = json.loads(args.scan_pages.read_text(encoding="utf-8")) if args.scan_pages.exists() else {}
        char_changes: list[tuple[str, str, str]] = []
        margin: list[str] = []
        specks: list[str] = []
        open_words: list[str] = []
        cleaned_volumes = []
        for n, v in enumerate((volume_1, volume_2)):
            v, left = clean_characters(v, n + 1, (args.raw_dir / f"los1900_deel{n + 1}_djvu.xml").read_text(
                encoding="utf-8"), page_texts[n], counts, vocab, readings, char_changes, margin,
                scan_pages, specks)
            cleaned_volumes.append(v)
            open_words += left
        volume_1, volume_2 = cleaned_volumes
        print(f"[chars] {len(char_changes)} words with unexpected characters corrected "
              f"({sum(k in readings for k, *_ in char_changes)} as read on the scan); "
              f"{len(open_words)} left unsettled")
        print(f"[chars] {len(margin)} lines of running heads / page margins dropped")
        print(f"[chars] specks read as punctuation cleaned on {len(specks)} badly printed pages: {' '.join(specks)}")
        if args.ocr_changes:
            with args.ocr_changes.open("a", encoding="utf-8") as fh:
                fh.writelines(f"chars\t{a}\t{b}\t{k}\n" for k, a, b in char_changes)
                fh.writelines(f"open\t{w}\n" for w in open_words)
                fh.writelines(f"margin\t{w}\n" for w in margin)
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
        text, found, missed = mark_hebrew(text, pages, counts)
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
        # Where the comments start, after Los's Bible text of the chapter:
        # that text is about as long, relative to the comments, as Calvin's
        # own Latin of the chapter is to his -- so near that point, at the
        # paragraph that best opens the first comment (its verse number,
        # its lemma). The first long numbered paragraph was often wrong: a
        # misread number, or a reference ("1. Sam. 15 vs. 22") taken for it.
        la_text = sum(len(r["text"]) for r in latin if r["kind"] == "scripture" and r["chapter"] == ch)
        la_comm = sum(len(r["text"]) for r in la_comments) or 1
        expected = la_text / (la_text + la_comm)
        body_len = sum(len(p) for p in paras) or 1
        # Los's Bible text ends with the chapter's last verse: the paragraph
        # after it opens the comments, also when the first comment has
        # neither number nor lemma (25:1: "Het lijkt zeer ongerijmd, ...").
        last_verse = max((v for c, v in verses if c == ch), default=0)
        last_words = verses.get((ch, last_verse), set())
        pos, best = 0, None
        for k, p in enumerate(paras):
            is_lemma, number = lemma_number(p)
            if len(p) > 150:
                after_text = lemma_likeness(paras[k - 1], last_words) if k else 0.0
                own = lemma_likeness(p, verses.get((ch, first_verse), set()))
                other = max((lemma_likeness(p, w) for (c, v), w in verses.items() if c == ch), default=0.0)
                numbers = match_score(number, first_verse) if is_lemma else -2.0
                if other - own > 0.35:
                    numbers = min(numbers, -3.0)  # another verse's lemma, the number misread
                score = numbers + 5.0 * own + 4.0 * after_text \
                    - 25.0 * abs(pos / body_len - expected) \
                    - (3.0 if lemma_words(p) > 15 and after_text < 0.5 else 0.0)
                if best is None or score > best[0]:
                    best = (score, k)
            pos += len(p)
        comment_start = best[1] if best else None
        if comment_start is None:
            warnings.append(f"chapter {ch}: no comments found")
            continue
        comment_text = "\n\n".join(join_broken_sentences(paras[comment_start:], capitals=True, seam=SEAM))
        scripture = [r for r in latin if r["kind"] == "scripture" and r["chapter"] == ch]
        for r in la_comments:
            r["lemma_verse"] = latin_lemma_verse(r, scripture)
        spans = assign_comments(comment_text, la_comments, ch, verses)
        texts: dict[int, list[str]] = {}
        for j, span in enumerate(spans):
            if span is None:
                continue
            block = comment_text[span[0]:span[1]].replace(SEAM, "").strip()
            # a garbled number without its stop ("LEN De naam dier plaats")
            garbled = re.match(r"^[A-Z]{1,3}\s+(?=[A-Z][a-z])", block)
            if garbled and j > 0 and block[:garbled.end()].strip().lower() not in _STOP | {"ik", "en"}:
                block = f"{la_comments[j]['section']}. " + block[garbled.end():]
            # the number as read ("o.", "D.", "9;", "BO.") -> the verse's
            token = _NUMBER_TOKEN_RE.match(block)
            if token and j > 0 or token and re.search(r"\d", token.group(1) or token.group(2)):
                block = f"{la_comments[j]['section']}. " + block[token.end():]
            elif not re.match(r"\d{1,2}\. ", block) and (j > 0 or lemma_likeness(
                    block, verses.get((ch, la_comments[j]["section"]), set())) >= 0.4):
                # a lemma printed (or read) without its number: the number
                # before it, like everywhere else -- a speck read as a short
                # word before the lemma dropped ("kh En het geschiedde")
                junk = re.match(r"^[A-Za-z]{1,3}\.?\s*-?\s+(?=[A-Z„])", block)
                if junk and counts.get(junk.group().strip(" .-").lower(), 0) < 20:
                    block = block[junk.end():]
                block = f"{la_comments[j]['section']}. " + block
            # (a comment that started mid-paragraph starts a paragraph now)
            texts[j] = [normalise_lemma(block, la_comments[j]["section"]) if lemma_number(block)[0] else block]
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
        if args.ocr_changes:
            with args.ocr_changes.open("a", encoding="utf-8") as fh:
                fh.writelines(f"heb-left\t{x}\n" for x in heb_stats.get("left", []))
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
