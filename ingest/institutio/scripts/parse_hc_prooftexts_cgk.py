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
The letters belong to the Dutch wording. The app shows them in two Dutch
columns (LAYERS below): the Den Heijer transcription of this same
traditional text (layer 'denheijer', see parse_hc_nl_denheijer.py) --
near-identical, with its own spelling differences and transcription
slips -- and Zwanepol's modern Dutch (layer 'zwanepol-hsv'), which
rewords and reorders. A character offset into either text wouldn't survive
later corrections through the translation-correction UI, so each letter
instead gets, per layer, an anchor: the (normalized) words of that layer's
text immediately before where the letter goes, plus which occurrence of
that phrase it is. Found by a word-level difflib alignment of the CGK text
against the layer's text; for Zwanepol additionally snapped to clause ends
and, where that still misses, fixed by hand (ZWANEPOL_ANCHOR_OVERRIDES).
The app (ConfessionRepository::placeProofTextMarkers) then just looks up
that occurrence of the phrase in whatever the layer's current text is --
so an edit elsewhere doesn't move a letter, and a letter whose anchor no
longer matches simply drops out of the inline text (it's still listed
under the answer). A letter without an anchor is only listed, never
guessed.

Normalization (must match the PHP side): lowercase, words = runs of
Unicode letters/digits, joined by single spaces.

Usage:
    python scripts/parse_hc_prooftexts_cgk.py -o /data/institutio/hc_prooftexts_cgk.jsonl
    python scripts/parse_hc_prooftexts_cgk.py --dry-run
    python scripts/parse_hc_prooftexts_cgk.py --pdf local.pdf --denheijer-jsonl dh.jsonl --zwanepol-jsonl zw.jsonl --dry-run

Without --denheijer-jsonl / --zwanepol-jsonl ({"ref", "text"} per line)
the layer's text is read from the database.

Requires: pymupdf, requests (download), psycopg (unless both layers come from JSONL)
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
# How far (in words) a Zwanepol letter may be moved to reach a clause end.
SNAP_WORDS = 6

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
_TRAILING_ZONDAG_RE = re.compile(r"(?<=[.?!\]])\s+[^.?!]*ZONDAG \d+\s*$")


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


def _words_with_boundaries(text: str) -> tuple[list[str], list[bool]]:
    """Normalized words, and per word whether it ends a clause: followed
    (after optional spaces) by , ; : . ? ! or by a bracketed citation --
    or is the last word. Words inside Zwanepol's own bracketed citations
    ("[Gal. 3:10]") never count, so a letter can't land in the middle of one."""
    matches = list(_WORD_RE.finditer(text))
    words = [m.group().lower() for m in matches]
    in_brackets = [text.count("[", 0, m.start()) > text.count("]", 0, m.start()) for m in matches]
    boundaries = [not inside and bool(re.match(r"\s*(?:[,;:.?!\[]|$)", text[m.end():]))
                  for m, inside in zip(matches, in_brackets)]
    return words, boundaries


def compute_anchors(cgk_text: str, target_text: str, snap_to_clause: bool = False
                    ) -> dict[str, tuple[str, int] | None]:
    """Map each "(x)" marker in the CGK text to (anchor, occurrence): the
    normalized words of `target_text` right before where it belongs, and
    which occurrence of that phrase in the whole target text it is
    (1-based). Question and answer are aligned as one text -- a few markers
    sit in the question itself (e.g. Q32 "genaamd (a)?"), and an answer's
    opening words often repeat the question's ("beide in het leven en
    sterven", Q1), which is what the occurrence number disambiguates.

    snap_to_clause is for a paraphrasing target (Zwanepol-HSV): a letter
    that closes a clause in the CGK text ("toebehoor (b), maar") is moved
    to the nearest clause end in the target when word alignment lands it
    mid-clause -- in a modern rewording the words around a letter often
    change or move, but the clause it belongs to usually survives. Not used
    for Den Heijer: same wording, but its commas are unreliable (several
    were lost where the letters were stripped out)."""
    cgk_words: list[str] = []
    marker_after: list[tuple[str, int, bool]] = []  # (glyph, last cgk word before it, closes a clause)
    pieces = re.split(r"(\([a-z]\))", cgk_text)
    for idx, piece in enumerate(pieces):
        mm = re.fullmatch(r"\(([a-z])\)", piece)
        if mm:
            following = pieces[idx + 1] if idx + 1 < len(pieces) else ""
            closes = bool(re.match(r"\s*(?:[,;:.?!]|$)", following))
            marker_after.append((mm.group(1), len(cgk_words) - 1, closes))
        else:
            cgk_words.extend(normalize_words(piece))
    # The Zwanepol-HSV layer still carries the section heading that follows
    # a question in the source ("... zal heersen. ZONDAG 13", "... bewegen.
    # God de Zoon en onze verlossing ZONDAG 11") -- cut off before aligning,
    # or the question's last letter gets pulled behind it. The anchor phrase
    # and occurrence still hold in the full text: only words before the
    # letter are involved.
    target_text = _TRAILING_ZONDAG_RE.sub("", target_text)
    target_words, target_boundaries = _words_with_boundaries(target_text)
    answer_start = len(normalize_words(target_text[:target_text.find("?") + 1]))

    # cgk word index => target word index of the word the letter should
    # follow. Equal stretches and same-length substitutions (spelling
    # variants: "eigendom"/"eigen") map word for word. A marker after the
    # last word of a stretch the target lacks or words differently (e.g.
    # Den Heijer's dropped "straffen" in Q10, before it was corrected) maps
    # to the end of the target's own version of that stretch -- the same
    # spot in the sentence.
    mapping: dict[int, int] = {}
    matcher = difflib.SequenceMatcher(a=cgk_words, b=target_words, autojunk=False)
    for op, a1, a2, b1, b2 in matcher.get_opcodes():
        if op == "equal" or (op == "replace" and a2 - a1 == b2 - b1):
            for k in range(a2 - a1):
                mapping[a1 + k] = b1 + k
        elif op in ("replace", "delete"):
            mapping[a2 - 1] = b2 - 1

    anchors: dict[str, tuple[str, int] | None] = {}
    for glyph, i, closes in marker_after:
        j = mapping.get(i)
        if j is not None and snap_to_clause and closes and not target_boundaries[j]:
            # Never across the question/answer split: the question's "?" is
            # a clause end too, but a letter in the answer doesn't belong there.
            lo = answer_start if j >= answer_start else 0
            hi = len(target_words) if j >= answer_start else answer_start
            candidates = [k for k in range(max(lo, j - SNAP_WORDS), min(hi, j + SNAP_WORDS + 1))
                          if target_boundaries[k]]
            # Nearest clause end; on a tie the earlier one (the letter's
            # own clause rather than the next).
            j = min(candidates, key=lambda k: (abs(k - j), k)) if candidates else j
        # At least two words of anchor, so it can't match a lone "en".
        if j is None or j < 1:
            anchors[glyph] = None
            continue
        phrase = target_words[max(0, j - ANCHOR_WORDS + 1):j + 1]
        n = len(phrase)
        occurrence = sum(1 for e in range(n - 1, j + 1) if target_words[e - n + 1:e + 1] == phrase)
        anchors[glyph] = (" ".join(phrase), occurrence)
    return anchors


# Where Zwanepol-HSV rewords or reorders a clause, word alignment (even with
# clause snapping) can't find the letter's spot, or finds the wrong one.
# These were settled by reading every question side by side with the CGK
# text: (question, letter) => the normalized Zwanepol words right before
# the letter (first occurrence in the text), or None where the letter has
# no sensible spot in the modern wording (it's then only listed).
ZWANEPOL_ANCHOR_OVERRIDES: dict[tuple[int, str], str | None] = {
    (1, "c"): "getrouwe zaligmaker jezus christus",
    (1, "e"): "van de duivel verlost",
    (1, "f"): "hij bewaart mij zo",
    (2, "c"): "en ellende verlost word",
    (10, "a"): "hij is hevig vertoornd",
    (17, "a"): "krachtens zijn goddelijke natuur",
    (17, "b"): "last van gods toorn",
    (19, "a"): "het paradijs geopenbaard heeft",
    (26, "a"): "uit niets geschapen heeft",
    (28, "b"): "voorspoed dankbaar mogen zijn",
    (32, "d"): "ik zijn naam belijd",
    (32, "e"): "dankoffer aan hem overgeef",
    (35, "d"): "maagd maria heeft aangenomen",
    (46, "a"): "de hemel is opgenomen",
    (52, "a"): "van mij weggenomen heeft",
    (54, "a"): "de zoon van god",
    (54, "b"): "het gehele menselijke geslacht",
    (54, "c"): "eeuwige leven is uitverkoren",
    (54, "d"): "zijn geest en woord",
    (54, "e"): "van het ware geloof",
    (54, "f"): "tot aan het einde",
    (60, "h"): "rekent mij die toe",
    (67, "a"): "grond van onze zaligheid",
    (74, "c"): "het geloof werkt beloofd",
    (74, "e"): "door de besnijdenis gebeurde",
    (77, "a"): "avondmaal die aldus luidt",
    (78, "a"): None,  # "Neen (a);" -- Zwanepol drops the "Nee"
    (86, "a"): "bewijzen voor zijn weldaden",
    (86, "b"): "door ons geprezen wordt",
    (94, "e"): "alleen op hem vertrouw",
    (94, "f"): "in alle ootmoed",
    (94, "i"): "mijn gehele hart liefheb",
    (94, "l"): "vrees en eer",
    (99, "c"): "ook door onnodig zweren",
    (99, "d"): "verschrikkelijke zonden deel krijgen",
    (99, "g"): "wordt beleden aangeroepen",
    (103, "b"): "naar gods gemeente kom",
    (105, "c"): "moedwillig in gevaar begeef",
    (107, "a"): "hebben als onszelf",
    (107, "b"): "vriendelijkheid te bejegenen",
    (108, "a"): "door god vervloekt is",
    (108, "b"): "afkeer van te hebben",
    (109, "b"): "gedachten begeerten",
    (110, "d"): "lengte maat waar",
    (110, "e"): "munt met woeker",
    (112, "a"): "een vals getuigenis afleg",
    (112, "e"): "van de duivel zelf",
    (112, "f"): "god op mij wil laden",
    (117, "b"): "hem te vragen",
    (117, "c"): "van harte aanroepen",
    (118, "a"): "en lichaam nodig hebben",
    (122, "a"): "juiste wijze kennen",
    (122, "b"): "waarheid helder stralen",
    (123, "b"): "breid haar uit",
    (123, "d"): "van uw rijk aanbreekt",
    (124, "a"): "eigen wil prijsgeven",
    (128, "a"): "ons alle goeds te geven",
    (129, "a"): "dit van hem verlang",
}

# Target layer => (whether letters snap to clause ends, manual overrides).
LAYERS: dict[str, tuple[bool, dict[tuple[int, str], str | None]]] = {
    "denheijer": (False, {}),
    "zwanepol-hsv": (True, ZWANEPOL_ANCHOR_OVERRIDES),
}


def load_layer(layer: str, jsonl: Path | None) -> dict[int, str]:
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
               WHERE w.slug = %s AND t.layer = %s""", (WORK_SLUG, layer))
        return {int(ref.split()[1]): text for ref, text in cur.fetchall() if re.fullmatch(r"HC \d+", ref)}


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--pdf", type=Path, default=RAW_PATH)
    ap.add_argument("--denheijer-jsonl", type=Path, default=None)
    ap.add_argument("--zwanepol-jsonl", type=Path, default=None)
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

    layer_jsonl = {"denheijer": args.denheijer_jsonl, "zwanepol-hsv": args.zwanepol_jsonl}
    texts = {layer: load_layer(layer, layer_jsonl[layer]) for layer in LAYERS}

    rows: list[dict] = []
    warnings: list[str] = []
    n_refs = n_glyphs = 0
    no_proofs: list[int] = []
    for number in range(1, 130):
        cgk_text, glyphs, qwarn = parse_question(number, questions[number])
        warnings.extend(qwarn)
        if not glyphs:
            no_proofs.append(number)
            continue
        layer_anchors: dict[str, dict[str, tuple[str, int] | None]] = {}
        for layer, (snap, overrides) in LAYERS.items():
            target = texts[layer].get(number)
            if target is None:
                warnings.append(f"Q{number}: no {layer} text -- letters not anchored there")
                layer_anchors[layer] = {}
                continue
            anchors = compute_anchors(cgk_text, target, snap_to_clause=snap)
            target_words = normalize_words(target)
            for (q, glyph), phrase in overrides.items():
                if q != number:
                    continue
                if phrase is None:
                    anchors[glyph] = None
                    continue
                words = phrase.split()
                if not any(target_words[k:k + len(words)] == words for k in range(len(target_words))):
                    warnings.append(f"Q{number} ({glyph}): {layer} override {phrase!r} not found in the text")
                    anchors[glyph] = None
                    continue
                anchors[glyph] = (phrase, 1)
            layer_anchors[layer] = anchors
        for ordinal, g in enumerate(glyphs, start=1):
            rows.append({"ref": f"HC {number}", "source": SOURCE, "glyph": g["glyph"], "ordinal": ordinal,
                         "anchors": {layer: layer_anchors[layer].get(g["glyph"]) for layer in LAYERS},
                         "refs_text": g["refs_text"], "refs": g["refs"]})
            n_glyphs += 1
            n_refs += len(g["refs"])

    for w in warnings:
        print(f"[warn]  {w}")
    print(f"[ok]    {n_glyphs} letters over {129 - len(no_proofs)} questions, {n_refs} verse references")
    print(f"[info]  questions without proof texts: {no_proofs}")
    for layer in LAYERS:
        unanchored = [f"{r['ref'].split()[1]}{r['glyph']}" for r in rows if r["anchors"][layer] is None]
        print(f"[ok]    {layer}: {n_glyphs - len(unanchored)}/{n_glyphs} letters anchored"
              + (f"; listed only: {', '.join(unanchored)}" if unanchored else ""))

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
