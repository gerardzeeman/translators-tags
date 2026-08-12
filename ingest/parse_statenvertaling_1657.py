"""
parse_statenvertaling_1657.py
Parses the local DBNL TEI-Lite XML transcription of the Statenvertaling
1657-editie (project root, _sta001stat02_01.xml -- NOT scraped, this is a
local file) and inserts:
  - translation_verses / translation_words  (TRANSLATION_ID = 4, code SV1657)
  - cross_references (source='SV1657')       -- lettered "Loca Parallela" notes only

Source structure:
  <div type="chapter"><div type="svvcap">
    <interpGrp><interp type="primair" value="bibgene001"/></interpGrp>
    <div type="svvbijbelvers">
      <interpGrp><interp type="primair" value="bibgene001:01"/></interpGrp>
      <head>1</head>
      <p>...verse text... <note n="1" place="margin">explanatory, numbered -- SKIPPED</note>
                           <note n="a" place="margin">Iob 38.4. Psalm 33.6. ...</note> ...</p>
    </div>
  </div></div>

Two footnote kinds (confirmed by the book's own front matter): numbered
notes ("Aenteeckeningen") are explanatory commentary and are skipped;
lettered notes ("Loca Parallela") are pure cross-references and are parsed
into cross_references rows.

Typographic conventions:
  - Words wrapped in literal [...] are supplied/added words with no direct
    source-language equivalent -- same convention as is_filler on the other
    SV parsers (HSV/SV(GBS) use <i>/asterisk; here it's square brackets).
  - `{corrected text}` immediately after a token inside <note> content is an
    editorial correction of a diplomatic-transcription typo; stripped out
    when parsing cross-reference targets (the surrounding diplomatic text is
    tolerant enough -- e.g. "24 2." -- that the correction isn't needed to
    parse correctly, and substituting it in has caused duplicated text).
  - Numbered explanatory notes and their content never leak into the verse
    text; lettered notes likewise never contribute to the verse text, only
    to cross_references.

Cross-reference target parsing (~96% of lettered notes resolve to at least
one target; the remainder are self-book relative references written in prose
-- "Boven vers 15", "Onder cap. 4" -- which would need current-book/-chapter
threading to resolve and are deliberately left unresolved for now) is a best-
effort regex + abbreviation-dictionary parser, NOT a structured lookup like
the HSV/SV(GBS) scrapers had (those had explicit href targets). Unresolved
notes are counted and reported, not silently dropped without a trace.

Verse placement (see iter_verses_with_context and its helpers below for the
full story): every one of the 37,249 <div type="svvbijbelvers"> elements in
the source is placed (0 skipped_anchor). The only remaining imperfection is a
handful of transcription-level chapter/verse duplications *in the source
itself* -- confirmed one by one, not assumed -- of which just one is left
deliberately unfixed: Psalm 100's 5 verses appear a second time under a
stray, headless "chapter 101" anchor immediately before the real Psalm 101;
since both copies number their own verses correctly, this can't be detected
as a per-verse anomaly, but it's harmless -- the real Psalm 101 is processed
second and its upsert naturally overwrites the stray copy's verses 1-5.

Usage:
  python parse_statenvertaling_1657.py                # all books, DB writes
  python parse_statenvertaling_1657.py --book GEN      # single book (usfm)
  python parse_statenvertaling_1657.py --dry-run       # print, no DB writes
  python parse_statenvertaling_1657.py --analyze-refs  # cross-ref parser diagnostics only, no XML walk
"""
import argparse
import os
import re
import sys
import unicodedata
from pathlib import Path

from lxml import etree

from db.loaders import (
    upsert_translation_verse,
    bulk_insert_translation_words,
    bulk_insert_cross_references,
)

# ─── Constants ────────────────────────────────────────────────────────────────

TRANSLATION_ID = 4
SOURCE = "SV1657"
# Local source file, not part of the ingest build context (it's a one-off
# 25MB local transcription, not baked into the image) -- bind-mounted at
# run time; override with SV1657_XML_PATH if mounted elsewhere.
XML_PATH = Path(os.environ.get(
    "SV1657_XML_PATH",
    str(Path(__file__).resolve().parent.parent / "_sta001stat02_01.xml"),
))

# ─── Book catalogue ───────────────────────────────────────────────────────────
# (book_id, usfm) in canonical order, matching db/schema.sql exactly.
# XML_BOOK_ABBREV_CANON is the interp value="bib<abbrev>..." prefix for each
# book, in the SAME order -- verified to appear in this exact canonical order
# in the source file, so zipping the two lists is safe and avoids hand-typing
# 66 pairs by hand.

_USFM_CANON = [
    "GEN", "EXO", "LEV", "NUM", "DEU", "JOS", "JDG", "RUT", "1SA", "2SA",
    "1KI", "2KI", "1CH", "2CH", "EZR", "NEH", "EST", "JOB", "PSA", "PRO",
    "ECC", "SNG", "ISA", "JER", "LAM", "EZK", "DAN", "HOS", "JOL", "AMO",
    "OBA", "JON", "MIC", "NAM", "HAB", "ZEP", "HAG", "ZEC", "MAL",
    "MAT", "MRK", "LUK", "JHN", "ACT", "ROM", "1CO", "2CO", "GAL", "EPH",
    "PHP", "COL", "1TH", "2TH", "1TI", "2TI", "TIT", "PHM", "HEB", "JAS",
    "1PE", "2PE", "1JN", "2JN", "3JN", "JUD", "REV",
]
_XML_ABBREV_CANON = [
    "gene", "exod", "levi", "nume", "deut", "jozu", "rich", "ruth", "1sam", "2sam",
    "1kon", "2kon", "1kro", "2kro", "ezra", "nehe", "esth", "job", "psal", "spre",
    "pred", "hoog", "jesa", "jere", "klaa", "ezec", "dani", "hose", "joel", "amos",
    "obad", "jona", "mich", "nahu", "haba", "sefa", "hagg", "zach", "male",
    "matt", "marc", "luca", "joha", "hand", "rome", "1kor", "2kor", "gala", "efes",
    "fili", "kolo", "1tes", "2tes", "1tim", "2tim", "titu", "file", "hebr", "jaco",
    "1pet", "2pet", "1joh", "2joh", "3joh", "juda", "open",
]
assert len(_USFM_CANON) == len(_XML_ABBREV_CANON) == 66

# Apocryphal book groups -- id 67-79, in the order they appear in the source
# (right after Revelation). See app/migrations/Version20260811130000.php.
_APOCRYPHA = [
    (67, "1ES", "3ezr"), (68, "2ES", "4ezr"), (69, "TOB", "tobi"),
    (70, "JDT", "judi"), (71, "WIS", "wijs"), (72, "SIR", "jezu"),
    (73, "BAR", "baru"), (74, "ESG", "este"), (75, "DAT", "toev"),
    (76, "MAN", "gebe"), (77, "1MA", "1mak"), (78, "2MA", "2mak"),
    (79, "3MA", "3mak"),
]

BOOKS: list[tuple[int, str]] = list(enumerate(_USFM_CANON, start=1)) + [
    (bid, usfm) for bid, usfm, _ in _APOCRYPHA
]
USFM_TO_BOOKID = {usfm: bid for bid, usfm in BOOKS}
XML_ABBREV_TO_USFM = dict(zip(_XML_ABBREV_CANON, _USFM_CANON))
XML_ABBREV_TO_USFM.update({xml_abbrev: usfm for _, usfm, xml_abbrev in _APOCRYPHA})

# ─── Text helpers (verse text + filler-word tokenisation) ─────────────────────

_PUNCT_EDGE = re.compile(r"^[^\w]+|[^\w]+$", re.UNICODE)
_LETTER_N = re.compile(r"^[a-z]{1,2}$")
_DIGIT_N = re.compile(r"^\d+$")


def normalise(text: str) -> str:
    nfd = unicodedata.normalize("NFD", text.lower())
    return "".join(c for c in nfd if unicodedata.category(c) != "Mn")


class _VerseWalker:
    """
    Walks a <p> verse element in document order, producing:
      - tokens: list of {word_position, word_text, word_normalised,
                 char_start, char_end, is_filler}
      - verse_text: reconstructed display string
      - cross_note_events: list of (letter, word_position, note_text) for
        lettered notes; numbered notes are skipped entirely (content unread).

    A word is is_filler=True if it began while inside literal [...] brackets
    (the 1657 edition's convention for supplied/added words) -- bracket depth
    is tracked across element boundaries since <hi rend="i"> children sit
    inside the brackets, not the brackets themselves.
    """

    def __init__(self, verse_num: int):
        self.verse_num = verse_num
        self._raw_words: list[tuple[str, bool]] = []  # (raw_token_with_punct, is_filler)
        self._cur_word = ""
        self._cur_filler = False
        self._bracket_depth = 0
        self.cross_note_events: list[tuple[str, int, str]] = []

    def _feed_text(self, text: str | None) -> None:
        if not text:
            return
        for ch in text:
            if ch == "[":
                self._bracket_depth += 1
                continue
            if ch == "]":
                self._bracket_depth = max(0, self._bracket_depth - 1)
                continue
            if ch.isspace():
                if self._cur_word:
                    self._raw_words.append((self._cur_word, self._cur_filler))
                    self._cur_word = ""
            else:
                if not self._cur_word:
                    self._cur_filler = self._bracket_depth > 0
                self._cur_word += ch

    def _flush_word(self) -> None:
        if self._cur_word:
            self._raw_words.append((self._cur_word, self._cur_filler))
            self._cur_word = ""

    def _real_word_count(self) -> int:
        """Count of raw words so far that will survive punctuation-stripping --
        matches the word_position convention used by translation_words."""
        return sum(1 for w, _ in self._raw_words if _PUNCT_EDGE.sub("", w))

    def walk(self, elem) -> None:
        self._feed_text(elem.text)
        for child in elem:
            tag = etree.QName(child).localname if child.tag is not etree.Comment else None
            if tag == "note":
                n = (child.get("n") or "").strip()
                if _LETTER_N.match(n):
                    note_text = "".join(child.itertext())
                    word_pos = self._real_word_count()
                    self.cross_note_events.append((n, word_pos, note_text))
                # numbered notes and malformed n= are skipped entirely --
                # never descended into, never contribute to verse text.
            elif tag == "pb" or tag == "lb":
                pass  # page/line break -- no text content, just fall through to tail
            else:
                self.walk(child)
            self._feed_text(child.tail)

    def finish(self) -> tuple[str, list[dict]]:
        self._flush_word()
        tokens: list[dict] = []
        verse_parts: list[str] = []
        position = 1
        char_offset = 0
        for raw_token, is_filler in self._raw_words:
            clean = _PUNCT_EDGE.sub("", raw_token)
            verse_parts.append(raw_token)
            if clean:
                tokens.append({
                    "word_position": position,
                    "word_text": clean,
                    "word_normalised": normalise(clean),
                    "char_start": char_offset,
                    "char_end": char_offset + len(raw_token),
                    "is_filler": is_filler,
                })
                position += 1
            char_offset += len(raw_token) + 1
        verse_text = re.sub(r"\s+", " ", " ".join(verse_parts)).strip()
        return verse_text, tokens


# ─── Cross-reference free-text parsing ─────────────────────────────────────────
# Lettered notes are free prose, not structured markup (unlike HSV/SV(GBS)
# which had explicit href targets) -- e.g. "Iob 38.4. Psalm 33.6. ende 89.12."
# This is a best-effort regex + abbreviation-dictionary parser tuned against
# the actual file (~96% of lettered notes resolve to >=1 target; see module
# docstring for what's deliberately left unresolved).

_CONNECTOR_RE = re.compile(r"\b(?:ende|en|ofte)\b", re.IGNORECASE)
_BRACE_RE = re.compile(r"\{[^}]*\}")
_SEGMENT_RE = re.compile(
    r"(?:(?P<bookpart>(?:[1234]\.?\s*)?[A-Za-zÀ-ÿ]{2,14})\.?\s*)?"
    r"(?:cap\.?\s*)?"
    r"(?P<chapter>\d{1,3})\s*[.,]?\s*"
    r"(?:vers(?:en)?\.?\s*)?"
    r"(?P<verses>\d{1,3}(?:\s*,\s*\d{1,3})*)\.?"
)
_BOOKPART_SPLIT = re.compile(r"^([1234])\.?\s*(.+)$")

_BOOK_STOPWORDS = {
    "boven", "bov", "onder", "ond", "vers", "versen", "verssen",
    "cap", "capp", "capit", "etc", "end", "onde",
}

# 17th-century Dutch abbreviations -> USFM. Single-family stems only; books
# whose Dutch name always carries an ordinal (Samuel, Koningen, ...) are in
# _ORDINAL_BOOK_STEMS below.
_BOOK_STEMS: dict[str, str] = {
    "gen": "GEN", "genes": "GEN",
    "exod": "EXO",
    "levit": "LEV", "lev": "LEV",
    "num": "NUM", "numer": "NUM",
    "deut": "DEU", "deuter": "DEU", "deurer": "DEU",
    "ios": "JOS", "iosu": "JOS", "iosa": "JOS",
    "iudic": "JDG",
    "ruth": "RUT",
    "iob": "JOB",
    "psal": "PSA", "psalm": "PSA", "psam": "PSA", "ps": "PSA",
    "prov": "PRO", "proverb": "PRO", "proberb": "PRO",
    "eccles": "ECC", "eccl": "ECC",
    "cant": "SNG", "cantic": "SNG",
    "iesa": "ISA", "iesai": "ISA", "ies": "ISA",
    "ierem": "JER", "ier": "JER", "iere": "JER",
    "thren": "LAM",
    "ezech": "EZK", "ezec": "EZK",
    "dan": "DAN",
    "hose": "HOS", "hos": "HOS",
    "ioel": "JOL", "ioël": "JOL",
    "amos": "AMO",
    "obad": "OBA",
    "ion": "JON", "ione": "JON", "ionae": "JON",
    "mich": "MIC", "micha": "MIC",
    "nahum": "NAM", "nah": "NAM",
    "habak": "HAB", "hab": "HAB",
    "zephan": "ZEP", "zeph": "ZEP",
    "hagg": "HAG",
    "zach": "ZEC", "zachar": "ZEC",
    "malach": "MAL", "mal": "MAL",
    "matt": "MAT", "matth": "MAT", "math": "MAT", "mat": "MAT",
    "marc": "MRK",
    "luc": "LUK", "luce": "LUK",
    "actor": "ACT", "act": "ACT",
    "rom": "ROM",
    "galat": "GAL", "gal": "GAL",
    "ephes": "EPH", "eph": "EPH",
    "philip": "PHP", "phil": "PHP",
    "philem": "PHM", "philemon": "PHM",
    "coloss": "COL", "col": "COL", "colos": "COL",
    "tit": "TIT", "titum": "TIT",
    "hebr": "HEB", "heb": "HEB",
    "iacob": "JAS", "iac": "JAS", "iaob": "JAS",
    "iud": "JUD",
    "apoc": "REV", "apocal": "REV",
    "ezra": "EZR",
    "nehem": "NEH", "neh": "NEH",
    "esth": "EST",
    # apocrypha, best-effort
    "syrach": "SIR", "sirach": "SIR",
    "sap": "WIS", "sapient": "WIS", "wijsh": "WIS",
    "tob": "TOB", "tobi": "TOB", "tobiae": "TOB",
    "iudith": "JDT",
    "baruch": "BAR",
    "mach": "1MA", "machab": "1MA",
}
_ORDINAL_BOOK_STEMS: dict[str, dict[str | None, str]] = {
    "sam": {"1": "1SA", "2": "2SA"},
    "reg": {"1": "1KI", "2": "2KI"},
    "kon": {"1": "1KI", "2": "2KI"},
    "chron": {"1": "1CH", "2": "2CH"}, "chro": {"1": "1CH", "2": "2CH"},
    "paral": {"1": "1CH", "2": "2CH"},
    "cor": {"1": "1CO", "2": "2CO"}, "corinth": {"1": "1CO", "2": "2CO"},
    "corint": {"1": "1CO", "2": "2CO"},
    "thess": {"1": "1TH", "2": "2TH"}, "thessal": {"1": "1TH", "2": "2TH"},
    "thes": {"1": "1TH", "2": "2TH"},
    "tim": {"1": "1TI", "2": "2TI"}, "timoth": {"1": "1TI", "2": "2TI"},
    "timot": {"1": "1TI", "2": "2TI"}, "timorh": {"1": "1TI", "2": "2TI"},
    "petr": {"1": "1PE", "2": "2PE"}, "pet": {"1": "1PE", "2": "2PE"},
    "ioan": {None: "JHN", "1": "1JN", "2": "2JN", "3": "3JN"},
    "joan": {None: "JHN", "1": "1JN", "2": "2JN", "3": "3JN"},
    "iohan": {None: "JHN"}, "ioa": {None: "JHN"},
    "mak": {"1": "1MA", "2": "2MA", "3": "3MA"},
}


def _resolve_book(ordv: str | None, stem: str) -> str | None:
    stem = stem.lower().rstrip(".")
    if stem in _BOOK_STOPWORDS:
        return None
    if stem in _ORDINAL_BOOK_STEMS:
        return _ORDINAL_BOOK_STEMS[stem].get(ordv)
    return _BOOK_STEMS.get(stem)


def parse_cross_ref_targets(note_text: str) -> list[tuple[str, int, int]]:
    """Parse a lettered note's free text into [(usfm, chapter, verse), ...].
    Best-effort -- returns [] for notes this parser can't resolve (logged by
    the caller via the unresolved-note counter, not silently swallowed)."""
    s = _BRACE_RE.sub("", note_text)
    s = _CONNECTOR_RE.sub(" ", s)

    out: list[tuple[str, int, int]] = []
    last_usfm: str | None = None
    for m in _SEGMENT_RE.finditer(s):
        bp = m.group("bookpart")
        if bp:
            bm = _BOOKPART_SPLIT.match(bp)
            ordv, stem = (bm.group(1), bm.group(2)) if bm else (None, bp)
            usfm = _resolve_book(ordv, stem)
            if usfm is None:
                continue  # unresolvable book mention -- skip this segment only
            last_usfm = usfm
        else:
            if last_usfm is None:
                continue
            usfm = last_usfm
        chapter = int(m.group("chapter"))
        for v in m.group("verses").split(","):
            v = v.strip()
            if v:
                out.append((usfm, chapter, int(v)))
    return out


# ─── XML iteration ──────────────────────────────────────────────────────────
#
# A verse div's OWN interp value (e.g. value="bibgene001:01") turns out to be
# an unreliable source for chapter/verse: across the file it appears as
# "bibjob_001:01" (underscore in the abbreviation, all 1,070 verses of Job),
# "bibezec0010:07" (extra zero-padding on the chapter, Ezek 10-11), "bibjere
# 009:7" (missing zero-padding on the verse), "bibjesa04029" (missing colon),
# and even outright typos ("bibjere002:222" where the rendered text -- and
# the div's own <head> -- both say verse 22, not 222). Rather than chase each
# format with more regex special-cases, this parser instead takes:
#   - book + chapter from the nearest ancestor <div type="svvcap"> interp
#     (format "bib<abbrev><chapter>", digit-length-tolerant so the extra
#     zero-padding above parses fine; still needs the same underscore
#     tolerance since chapter anchors have it too, e.g. "bibjob_001")
#   - the verse number from the verse div's own <head> text, which was
#     confirmed numeric and correct for every single one of the ~1,200
#     verses that failed the old per-verse interp-value parse (including the
#     "222" typo above, whose <head> correctly says "22")
# This recovered all but 18 of the ~1,218 verses the old anchor-only parser
# was silently dropping (see git history / PR description for the full
# investigation). The remaining 18 are Sirach's translator's prologue
# ("bibjezu00A:01".."00B:10" -- a lettered, unnumbered preface with no
# chapter number at all in the source), handled as a one-off below.

_CHAPTER_ANCHOR_RE = re.compile(r"^bib([a-z0-9_]+?)(\d{1,4})$")
_INTERP_VERSE_SUFFIX_RE = re.compile(r":(\d{1,3})$")

# Sirach's prologue: two lettered paragraphs ("00A", "00B") with no chapter
# number of their own in the source. Mapped to chapter 0, continuing the
# verse count across both (00A: 1-8, 00B: 9-18) rather than restarting at 1,
# since restarting would collide (both would claim verses 1-8 in chapter 0).
_JEZU_PROLOGUE_RE = re.compile(r"^bibjezu00([AB]):(\d{2})$")
_JEZU_PROLOGUE_OFFSET = {"A": 0, "B": 8}

# One-off: 1 Chronicles' own chapter-anchor sequence goes ...011, 013, 013,
# 014... -- chapter 12 is entirely missing and 013 appears twice. The FIRST
# "013" div's own <head> reads "Het xij. Capittel." (xij = 12 in Roman
# numerals), confirming the interp value itself is the one that's wrong for
# that occurrence, not the displayed chapter title. Tracked by occurrence
# count since both anchors are otherwise identical.
_1KRO_013_BUG_USFM_CHAPTER = ("1CH", 13)
_1KRO_013_BUG_FIRST_OCCURRENCE_CHAPTER = 12

# Three known one-off cases where a verse's number is duplicated in BOTH
# <head> and the div's own interp value, so the cross-validation above can't
# tell them apart -- confirmed by checking that the verse immediately after
# the duplicate skips a number (e.g. 2 Kings 15 goes ...7, 8, 6, 6, 10... --
# the second "6" is really 9). Applied only when the computed verse doesn't
# advance past the last one used (i.e. the second/duplicate occurrence).
_KNOWN_DUPLICATE_VERSE_FIXES = {
    ("2KI", 15, 6): 9,
    ("EZK", 40, 39): 40,
    ("EZK", 46, 8): 9,
}

# One remaining, deliberately-unfixed case: Psalm 100's 5 verses appear a
# second time under a stray "bibpsal101" chapter anchor (no <head> at all on
# that anchor, unlike every real chapter), immediately followed by the real
# Psalm 101 (8 verses, correctly headed "Psalm Cj." -- Cj = 101 in Roman
# numerals). Both the false chapter-101 divs and the real ones independently
# number their own verses correctly (1..5 and 1..8), so nothing here flags it
# as a per-verse anomaly -- it's a genuine content duplication at the chapter
# level. Left alone: the real Psalm 101 is processed second and its upsert
# naturally overwrites the false one's verses 1-5, so the final data is
# correct without any special-casing; the only trace is that verses 1-5 of
# chapter 101 get written twice during ingest.


def _parse_chapter_anchor(value: str) -> tuple[str, int] | None:
    m = _CHAPTER_ANCHOR_RE.match(value)
    if not m:
        return None
    usfm = XML_ABBREV_TO_USFM.get(m.group(1).rstrip("_"))
    if usfm is None:
        return None
    return usfm, int(m.group(2))


def iter_verses_with_context(root, stats: dict | None = None):
    """Yield (div, usfm, chapter, verse) for every <div type="svvbijbelvers">
    in document order. See module comment above for why chapter comes from
    the nearest preceding <div type="svvcap"> anchor and verse from the verse
    div's own <head> text rather than the verse div's own interp value --
    except <head> turns out to have a handful of its own typos (usually
    repeating the previous verse's number), so within each chapter the verse
    number is only accepted from <head> if it's greater than the last one
    used; otherwise the verse suffix of the div's own (otherwise-unreliable)
    interp value is tried as a fallback, since in every observed case that
    value was the one that was actually correct there. Divs that still can't
    be placed (stats['skipped_anchor'], if a stats dict is passed) are
    skipped, not raised -- reported, not silently dropped."""
    current: tuple[str, int] | None = None
    last_verse = 0
    kro13_occurrences = 0

    for div in root.iter("div"):
        dtype = div.get("type")

        if dtype == "svvcap":
            interp = div.find("./interpGrp/interp")
            value = interp.get("value") if interp is not None else None
            current = _parse_chapter_anchor(value) if value else None
            last_verse = 0
            if current == _1KRO_013_BUG_USFM_CHAPTER:
                kro13_occurrences += 1
                if kro13_occurrences == 1:
                    current = (current[0], _1KRO_013_BUG_FIRST_OCCURRENCE_CHAPTER)
            continue

        if dtype != "svvbijbelvers":
            continue

        interp = div.find("./interpGrp/interp")
        own_value = interp.get("value") if interp is not None else None
        jezu_m = _JEZU_PROLOGUE_RE.match(own_value) if own_value else None
        if jezu_m:
            yield div, "SIR", 0, _JEZU_PROLOGUE_OFFSET[jezu_m.group(1)] + int(jezu_m.group(2))
            continue

        head = div.find("./head")
        head_text = head.text.strip() if head is not None and head.text else None
        head_verse = int(head_text) if head_text and head_text.isdigit() else None
        interp_verse = None
        if own_value:
            suffix_m = _INTERP_VERSE_SUFFIX_RE.search(own_value)
            if suffix_m:
                interp_verse = int(suffix_m.group(1))

        # Prefer whichever source exactly matches the expected next verse
        # number; this catches typos in EITHER source (head repeating the
        # previous verse's number, or -- at the very start of a chapter,
        # where "greater than the last verse" trivially passes for any
        # head typo -- interp being the one that's off instead). Falls back
        # to "greater than the last verse" when neither is an exact match
        # (e.g. a genuine gap in the source), and accepts even a
        # non-advancing value as a last resort rather than drop the verse --
        # a rare wrong/duplicate verse number is preferable to losing the
        # verse's text entirely.
        expected_next = last_verse + 1
        if head_verse == expected_next:
            verse = head_verse
        elif interp_verse == expected_next:
            verse = interp_verse
        elif head_verse is not None and head_verse > last_verse:
            verse = head_verse
        elif interp_verse is not None and interp_verse > last_verse:
            verse = interp_verse
        else:
            verse = head_verse if head_verse is not None else interp_verse

        if current is not None and verse is not None and verse <= last_verse:
            fix = _KNOWN_DUPLICATE_VERSE_FIXES.get((current[0], current[1], verse))
            if fix is not None:
                verse = fix

        if current is None or verse is None:
            if stats is not None:
                stats["skipped_anchor"] += 1
            continue
        last_verse = verse

        usfm, chapter = current
        yield div, usfm, chapter, verse


# ─── Main ingest loop ───────────────────────────────────────────────────────

def load_xml_root():
    parser = etree.XMLParser(huge_tree=True, recover=True, load_dtd=False,
                              no_network=True, resolve_entities=True)
    tree = etree.parse(str(XML_PATH), parser)
    return tree.getroot()


def run(only_book: str | None = None, dry_run: bool = False) -> None:
    if not XML_PATH.exists():
        print(f"ERROR: source file not found: {XML_PATH}", file=sys.stderr)
        sys.exit(1)

    print(f"Loading {XML_PATH.name} ({XML_PATH.stat().st_size:,} bytes) …", flush=True)
    root = load_xml_root()

    if not dry_run:
        ensure_translation_row()

    word_batch: list[dict] = []
    ref_batch: list[dict] = []
    stats = {
        "verses": 0, "words": 0, "filler_words": 0,
        "skipped_anchor": 0, "unknown_book_anchor": 0,
        "cross_notes": 0, "cross_notes_unresolved": 0, "cross_refs": 0,
    }

    for verse_div, usfm, chapter, verse in iter_verses_with_context(root, stats):
        book_id = USFM_TO_BOOKID.get(usfm)
        if book_id is None:
            stats["unknown_book_anchor"] += 1
            continue
        if only_book and usfm != only_book:
            continue

        p = verse_div.find("./p")
        if p is None:
            continue

        walker = _VerseWalker(verse)
        walker.walk(p)
        verse_text, tokens = walker.finish()
        if not tokens:
            continue

        if dry_run:
            filler_n = sum(1 for t in tokens if t["is_filler"])
            suffix = f"  [{filler_n} filler]" if filler_n else ""
            print(f"  {usfm} {chapter}:{verse}  {verse_text[:120]}{suffix}")
        else:
            verse_id = upsert_translation_verse(TRANSLATION_ID, book_id, chapter, verse, verse_text)
            stats["verses"] += 1
            for token in tokens:
                word_batch.append({"verse_id": verse_id, **token})
                stats["words"] += 1
                if token["is_filler"]:
                    stats["filler_words"] += 1
            if len(word_batch) >= 2000:
                bulk_insert_translation_words(word_batch)
                word_batch.clear()

        for letter, word_pos, note_text in walker.cross_note_events:
            stats["cross_notes"] += 1
            targets = parse_cross_ref_targets(note_text)
            if not targets:
                stats["cross_notes_unresolved"] += 1
                continue
            for ordinal, (tgt_usfm, tgt_chapter, tgt_verse) in enumerate(targets, start=1):
                tgt_book_id = USFM_TO_BOOKID.get(tgt_usfm)
                if tgt_book_id is None:
                    continue
                stats["cross_refs"] += 1
                if not dry_run:
                    ref_batch.append({
                        "source": SOURCE, "book_id": book_id, "chapter": chapter, "verse": verse,
                        "letter": letter, "word_position": word_pos, "ordinal": ordinal,
                        "target_book_id": tgt_book_id, "target_chapter": tgt_chapter,
                        "target_verse": tgt_verse, "label": note_text.strip(),
                    })
            if len(ref_batch) >= 2000:
                bulk_insert_cross_references(ref_batch)
                ref_batch.clear()

    if not dry_run:
        if word_batch:
            bulk_insert_translation_words(word_batch)
        if ref_batch:
            bulk_insert_cross_references(ref_batch)

    print("\n─── Done ───")
    for k, v in stats.items():
        print(f"  {k:26s} {v:,}")


def ensure_translation_row() -> None:
    from db.connection import get_connection
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO translations (id, code, name, abbreviation, language, direction, family, source_lang_authority)
                VALUES (%s, 'SV1657', 'Statenvertaling (1657)', 'SV(1657)', 'nld', 'LTR', 'SV', FALSE)
                ON CONFLICT (id) DO NOTHING
                """,
                (TRANSLATION_ID,),
            )


def analyze_refs() -> None:
    """Diagnostics-only: report cross-ref parser coverage without touching the DB or walking the full XML."""
    with open(XML_PATH, encoding="utf-8") as f:
        text = f.read()
    note_re = re.compile(r'<note n="([a-z]{1,2})" place="margin">(.*?)</note>', re.DOTALL)
    total = 0
    zero = 0
    total_refs = 0
    for m in note_re.finditer(text):
        body = re.sub(r"<[^>]+>", "", m.group(2))
        body = re.sub(r"\s+", " ", body).strip()
        total += 1
        targets = parse_cross_ref_targets(body)
        if not targets:
            zero += 1
        total_refs += len(targets)
    print(f"lettered notes: {total:,}")
    print(f"resolved to >=1 target: {total - zero:,} ({(total - zero) / total * 100:.1f}%)")
    print(f"unresolved (0 targets): {zero:,} ({zero / total * 100:.1f}%)")
    print(f"total target rows: {total_refs:,}")


# ─── CLI entry point ──────────────────────────────────────────────────────────

if __name__ == "__main__":
    parser = argparse.ArgumentParser(
        description="Parse the local Statenvertaling 1657-editie XML into translation_verses/words + cross_references (source='SV1657')."
    )
    parser.add_argument("--book", metavar="USFM", default=None, help="Process a single book only (e.g. GEN, 1MA).")
    parser.add_argument("--dry-run", action="store_true", help="Print extracted verses; do not write to the database.")
    parser.add_argument("--analyze-refs", action="store_true", help="Print cross-reference parser coverage stats only.")
    args = parser.parse_args()

    if args.analyze_refs:
        analyze_refs()
    else:
        run(only_book=(args.book.upper() if args.book else None), dry_run=args.dry_run)
