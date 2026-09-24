#!/usr/bin/env python3
"""Parse the classic Dutch Heidelberg Catechism's Scripture proof texts
("bewijsteksten") from the CGK PDF into JSONL: one row per footnote
letter per question, with its references resolved to structured
book/chapter/verse ranges.

Source: "Heidelbergse Catechismus -- klassieke versie", as published by the
Christelijke Gereformeerde Kerken:
    https://cgk.nl/wp-content/uploads/2023/07/Heidelbergse-Catechismus-klassieke-versie.pdf
The traditional Dutch text with the traditional letter apparatus: markers
"(a)", "(b)", ... inline in each answer, and under it the matching
reference list "(a) Rom. 14:8. (b) 1 Kor. 6:19. ...". Both the catechism
text and this centuries-old proof-text selection are in the public domain
(the modern-language version and written-out HSV verses at
heidelbergse-catechismus.nl are not -- see PROJECTDOSSIER.md). The PDF has
a real text layer; no OCR needed. Confirmed 129/129 questions found, 123
with proof texts: Q23, 24, 68, 71, 83 and 92 traditionally have none
(they quote the Creed, the Law or Scripture itself inline).

ANCHORING
---------
The letters belong to the Dutch wording, and the app shows the Den Heijer
transcription of that same traditional text (layer 'denheijer', see
parse_hc_nl_denheijer.py) -- near-identical, but with its own spelling
differences and transcription slips. A character offset into that text
wouldn't survive later corrections through the translation-correction UI,
so each letter instead gets an `anchor`: the (normalized) Den Heijer words
immediately before where the letter goes, found here by a word-level
difflib alignment of the CGK answer against the Den Heijer answer. The app
(ConfessionRepository::placeProofTextMarkers) then just looks up the
`anchor_occurrence`-th occurrence of that phrase in whatever the current
Den Heijer text is -- so an edit elsewhere in the answer doesn't move a
letter, and a letter whose anchor no longer matches simply drops out of
the inline text (it's still listed under the answer). `anchor` is null where the alignment found no reliable
match (the letter is then only listed, never guessed).

Normalization (must match the PHP side): lowercase, words = runs of
Unicode letters/digits, joined by single spaces.

Usage:
    python scripts/parse_hc_prooftexts_cgk.py -o /data/institutio/hc_prooftexts_cgk.jsonl
    python scripts/parse_hc_prooftexts_cgk.py --dry-run
    python scripts/parse_hc_prooftexts_cgk.py --pdf local.pdf --denheijer-jsonl hc_nl_denheijer.jsonl --dry-run

Without --denheijer-jsonl the Den Heijer text is read from the database
(translation layer 'denheijer').

Requires: pymupdf, requests (download), psycopg (only without --denheijer-jsonl)
"""
from __future__ import annotations

import argparse
import difflib
import json
import re
import sys
from pathlib import Path

import pymupdf

URL = "https://cgk.nl/wp-content/uploads/2023/07/Heidelbergse-Catechismus-klassieke-versie.pdf"
RAW_PATH = Path("/data/institutio/raw/hc_cgk_klassiek.pdf")
SOURCE = "cgk-klassiek"
WORK_SLUG = "heidelbergse-catechismus"
ANCHOR_WORDS = 4

# Dutch abbreviation (as printed, without the period) => USFM code in the
# app's `books` table. Numbered books carry their number separately
# ("1 Kor." -> number 1 + "Kor"), resolved via NUMBERED below.
BOOKS = {
    "Gen": "GEN", "Ex": "EXO", "Lev": "LEV", "Num": "NUM", "Deut": "DEU",
    "Joz": "JOS", "Richt": "JDG", "Ruth": "RUT", "Ezra": "EZR", "Neh": "NEH",
    "Est": "EST", "Job": "JOB", "Ps": "PSA", "Spr": "PRO", "Pred": "ECC",
    "Hoogl": "SNG", "Jes": "ISA", "Jesaja": "ISA", "Jer": "JER",
    "Klaagl": "LAM", "Ez": "EZK", "Dan": "DAN", "Hos": "HOS", "Joël": "JOL",
    "Amos": "AMO", "Ob": "OBA", "Jona": "JON", "Micha": "MIC", "Nah": "NAM",
    "Hab": "HAB", "Zef": "ZEP", "Hag": "HAG", "Zach": "ZEC", "Mal": "MAL",
    "Matth": "MAT", "Mark": "MRK", "Luk": "LUK", "Joh": "JHN", "Hand": "ACT",
    "Rom": "ROM", "Gal": "GAL", "Ef": "EPH", "Filipp": "PHP", "Fil": "PHP",
    "Kol": "COL", "Tit": "TIT", "Filem": "PHM", "Hebr": "HEB", "Jak": "JAS",
    "Jud": "JUD", "Openb": "REV",
}
NUMBERED = {
    "Sam": "SA", "Kon": "KI", "Kron": "CH", "Kor": "CO", "Thess": "TH",
    "Tim": "TI", "Petr": "PE", "Joh": "JN",
}

# Typesetting slips in the PDF, applied to the raw question text before
# parsing: question number => [(wrong, right)]. Mostly inline markers that
# lost their brackets ("straffen b;") -- confirmed by reading each question
# against its own reference list, which does carry the letter.
MARKER_FIXES: dict[int, list[tuple[str, str]]] = {
    10: [("eeuwiglijk straffen b;", "eeuwiglijk straffen (b);")],
    32: [("deelachtig ben c;", "deelachtig ben (c);")],
    # Not a marker but a botched find-and-replace ("Jes." -> "Jesaja") that
    # prefixed every bare verse number with the book name. Restored to the
    # traditional reading, confirmed against heidelbergse-catechismus.nl
    # (Jes. 42:1-4; 43:25; 49:5-6, 22-23).
    19: [("Jesaja 53:1; 42:1, Jesaja 2, Jesaja 3, Jesaja 4, Jesaja \n43:25, Jesaja 49:5, "
          "Jesaja 6, Jesaja 22, Jesaja 23.",
          "Jes. 53:1; 42:1, 2, 3, 4; 43:25; 49:5, 6, 22, 23.")],
    79: [("ontvangen \nb;", "ontvangen (b);"), ("ontvangen b;", "ontvangen (b);")],
}

_QUESTION_SPLIT_RE = re.compile(r"\n(\d{1,3}) *\n")
_MARKER_RE = re.compile(r"\(([a-z])\)")
# A reference line starts with "(a)" followed (on that line) by at least one
# chapter:verse -- inline text markers like "sterven (a), niet" never do.
_REF_BLOCK_START_RE = re.compile(r"^\s*\(a\)\s*\S.*\d+:\d+", re.MULTILINE)
_TRAILER_RE = re.compile(r"\n\s*(?:Zondag \d+|HET (?:EERSTE|TWEEDE|DERDE) DEEL)\b.*", re.DOTALL)
_BOOK_RE = re.compile(r"(?:([123])\s*)?([A-Z][a-zë]+)\.?\s*(?=\d)")
_CHAPTER_GROUP_RE = re.compile(r"(\d+):([\d,\s\-–]+?)(?=;|\.\s|\.$|$)")
_WORD_RE = re.compile(r"[^\W_]+")


def normalize_words(text: str) -> list[str]:
    return _WORD_RE.findall(text.lower())


def fetch_pdf(path: Path) -> None:
    import requests
    path.parent.mkdir(parents=True, exist_ok=True)
    print(f"[fetch] {URL}")
    resp = requests.get(URL, headers={"User-Agent": "Mozilla/5.0"}, timeout=60)
    resp.raise_for_status()
    path.write_bytes(resp.content)
    print(f"[ok]    wrote {path} ({len(resp.content)} bytes)")


def split_questions(text: str) -> dict[int, str]:
    parts = _QUESTION_SPLIT_RE.split(text)
    questions: dict[int, str] = {}
    for i in range(1, len(parts), 2):
        questions.setdefault(int(parts[i]), parts[i + 1])
    return questions


def parse_verse_list(chapter: int, verses: str) -> list[tuple[int, int, int]]:
    """"18, 19" -> [(ch, 18, 19)]; "10, 20, 23" -> three single verses;
    "37-40" -> one range. Consecutive single verses are merged into a range."""
    out: list[tuple[int, int, int]] = []
    for piece in re.split(r"\s*,\s*", verses.strip()):
        if not piece:
            continue
        m = re.fullmatch(r"(\d+)\s*[-–]\s*(\d+)", piece)
        start, end = (int(m.group(1)), int(m.group(2))) if m else (int(piece), int(piece))
        if out and out[-1][0] == chapter and out[-1][2] + 1 == start:
            out[-1] = (chapter, out[-1][1], end)
        else:
            out.append((chapter, start, end))
    return out


def parse_refs(block: str) -> tuple[list[dict], str]:
    """Parse one letter's reference text ("1 Joh. 1:7; 2:2, 12. Hebr. 2:14.")
    into structured refs. Returns (refs, unparsed leftovers)."""
    refs: list[dict] = []
    leftovers: list[str] = []
    book_matches = list(_BOOK_RE.finditer(block))
    if not book_matches:
        return refs, block.strip()
    if block[:book_matches[0].start()].strip(" .;"):
        leftovers.append(block[:book_matches[0].start()].strip())
    for idx, bm in enumerate(book_matches):
        end = book_matches[idx + 1].start() if idx + 1 < len(book_matches) else len(block)
        number, abbr = bm.group(1), bm.group(2)
        usfm = f"{number}{NUMBERED[abbr]}" if number and abbr in NUMBERED else BOOKS.get(abbr)
        if usfm is None:
            leftovers.append(block[bm.start():end].strip())
            continue
        label_book = f"{number} {abbr}." if number else f"{abbr}."
        body = block[bm.end():end].strip()
        consumed = 0
        for cm in _CHAPTER_GROUP_RE.finditer(body):
            if body[consumed:cm.start()].strip(" .;"):
                leftovers.append(body[consumed:cm.start()].strip())
            chapter = int(cm.group(1))
            verse_text = cm.group(2).strip().rstrip(",")
            for ch, vs, ve in parse_verse_list(chapter, verse_text):
                verse_label = f"{vs}" if vs == ve else f"{vs}-{ve}"
                refs.append({"usfm": usfm, "chapter": ch, "verse_start": vs, "verse_end": ve,
                             "label": f"{label_book} {ch}:{verse_label}"})
            consumed = cm.end()
        if body[consumed:].strip(" .;"):
            leftovers.append(body[consumed:].strip())
    return refs, " | ".join(leftovers)


def parse_question(number: int, raw: str) -> tuple[str, list[dict], list[str]]:
    """Returns (answer text with markers, glyph rows, warnings)."""
    for wrong, right in MARKER_FIXES.get(number, []):
        raw = raw.replace(wrong, right)
    raw = _TRAILER_RE.sub("", raw)
    m = _REF_BLOCK_START_RE.search(raw)
    if m is None:
        return re.sub(r"\s+", " ", raw).strip(), [], []
    text = re.sub(r"\s+", " ", raw[:m.start()]).strip()
    ref_text = re.sub(r"\s+", " ", raw[m.start():]).strip()

    warnings: list[str] = []
    pieces = _MARKER_RE.split(ref_text)  # ['', 'a', ' Rom. 14:8. ', 'b', ...]
    glyphs: list[dict] = []
    for i in range(1, len(pieces), 2):
        glyph, block = pieces[i], pieces[i + 1].strip()
        refs, leftover = parse_refs(block)
        if leftover:
            warnings.append(f"Q{number} ({glyph}) unparsed: {leftover!r}")
        if not refs:
            warnings.append(f"Q{number} ({glyph}) has no parsed references")
        glyphs.append({"glyph": glyph, "refs_text": block.rstrip(), "refs": refs})

    text_markers = _MARKER_RE.findall(text)
    list_markers = [g["glyph"] for g in glyphs]
    if text_markers != list_markers:
        warnings.append(f"Q{number} marker mismatch: text {text_markers} vs list {list_markers}")
    return text, glyphs, warnings


def compute_anchors(cgk_text: str, denheijer_text: str) -> dict[str, tuple[str, int] | None]:
    """Map each "(x)" marker in the CGK text to (anchor, occurrence): the
    normalized Den Heijer words right before where it belongs, and which
    occurrence of that phrase in the whole Den Heijer text it is (1-based).
    Question and answer are aligned as one text -- a few markers sit in the
    question itself (e.g. Q32 "genaamd (a)?"), and an answer's opening words
    often repeat the question's ("beide in het leven en sterven", Q1), which
    is what the occurrence number disambiguates."""
    cgk_words: list[str] = []
    marker_after: list[tuple[str, int]] = []  # (glyph, index of last cgk word before it)
    for piece in re.split(r"(\([a-z]\))", cgk_text):
        mm = re.fullmatch(r"\(([a-z])\)", piece)
        if mm:
            marker_after.append((mm.group(1), len(cgk_words) - 1))
        else:
            cgk_words.extend(normalize_words(piece))
    dh_words = normalize_words(denheijer_text)

    # cgk word index => Den Heijer word index of the word the letter should
    # follow. Equal stretches and same-length substitutions (spelling
    # variants: "eigendom"/"eigen") map word for word. A marker after the
    # last word of a stretch Den Heijer lacks or words differently (e.g. its
    # dropped "straffen" in Q10) maps to the end of Den Heijer's own
    # version of that stretch -- the same spot in the sentence.
    mapping: dict[int, int] = {}
    matcher = difflib.SequenceMatcher(a=cgk_words, b=dh_words, autojunk=False)
    for op, a1, a2, b1, b2 in matcher.get_opcodes():
        if op == "equal" or (op == "replace" and a2 - a1 == b2 - b1):
            for k in range(a2 - a1):
                mapping[a1 + k] = b1 + k
        elif op in ("replace", "delete"):
            mapping[a2 - 1] = b2 - 1

    anchors: dict[str, tuple[str, int] | None] = {}
    for glyph, i in marker_after:
        j = mapping.get(i)
        # At least two words of anchor, so it can't match a lone "en".
        if j is None or j < 1:
            anchors[glyph] = None
            continue
        phrase = dh_words[max(0, j - ANCHOR_WORDS + 1):j + 1]
        n = len(phrase)
        occurrence = sum(1 for e in range(n - 1, j + 1) if dh_words[e - n + 1:e + 1] == phrase)
        anchors[glyph] = (" ".join(phrase), occurrence)
    return anchors


def load_denheijer(jsonl: Path | None) -> dict[int, str]:
    if jsonl is not None:
        rows = [json.loads(line) for line in jsonl.read_text(encoding="utf-8").splitlines() if line.strip()]
        return {int(r["ref"].split()[1]): r["text"] for r in rows}
    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
    from db import get_connection
    with get_connection() as conn, conn.cursor() as cur:
        cur.execute(
            """SELECT s.ref, t.text_nl FROM translation t
               JOIN segment s ON s.id = t.segment_id
               JOIN work w ON w.id = s.work_id
               WHERE w.slug = %s AND t.layer = 'denheijer'""", (WORK_SLUG,))
        return {int(ref.split()[1]): text for ref, text in cur.fetchall() if re.fullmatch(r"HC \d+", ref)}


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--pdf", type=Path, default=RAW_PATH)
    ap.add_argument("--denheijer-jsonl", type=Path, default=None)
    ap.add_argument("-o", "--output", type=Path)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    if not args.pdf.exists():
        fetch_pdf(args.pdf)
    text = "".join(page.get_text() for page in pymupdf.open(args.pdf))
    questions = split_questions(text)
    missing = [n for n in range(1, 130) if n not in questions]
    if missing:
        print(f"[error] questions not found: {missing}")
        return 1

    denheijer = load_denheijer(args.denheijer_jsonl)

    rows: list[dict] = []
    warnings: list[str] = []
    n_refs = n_anchored = n_glyphs = 0
    no_proofs: list[int] = []
    for number in range(1, 130):
        cgk_text, glyphs, qwarn = parse_question(number, questions[number])
        warnings.extend(qwarn)
        if not glyphs:
            no_proofs.append(number)
            continue
        anchors = compute_anchors(cgk_text, denheijer[number]) if number in denheijer else {}
        if number not in denheijer:
            warnings.append(f"Q{number}: no denheijer text -- letters not anchored")
        for ordinal, g in enumerate(glyphs, start=1):
            anchor, occurrence = anchors.get(g["glyph"]) or (None, None)
            rows.append({"ref": f"HC {number}", "source": SOURCE, "glyph": g["glyph"], "ordinal": ordinal,
                         "anchor": anchor, "anchor_occurrence": occurrence,
                         "refs_text": g["refs_text"], "refs": g["refs"]})
            n_glyphs += 1
            n_refs += len(g["refs"])
            n_anchored += anchor is not None

    for w in warnings:
        print(f"[warn]  {w}")
    print(f"[ok]    {n_glyphs} letters over {129 - len(no_proofs)} questions, {n_refs} verse references; "
          f"{n_anchored}/{n_glyphs} letters anchored in the Den Heijer text")
    print(f"[info]  questions without proof texts: {no_proofs}")
    unanchored = [f"{r['ref']}{r['glyph']}" for r in rows if r["anchor"] is None]
    if unanchored:
        print(f"[info]  not anchored (listed only): {', '.join(unanchored)}")

    if args.dry_run:
        return 0
    if args.output is None:
        ap.error("-o/--output is required unless --dry-run")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8") as fh:
        for r in rows:
            fh.write(json.dumps(r, ensure_ascii=False) + "\n")
    print(f"[ok]    wrote {args.output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
