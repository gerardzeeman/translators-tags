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


def paragraphs(text: str) -> list[str]:
    out = [re.sub(r"\s+", " ", p).strip() for p in re.split(r"\n\s*\n", text)]
    return [p for p in out if len(p) > 3]


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
_CANTILLATION_RE = re.compile("[\u0591-\u05AF\u05BD\u05C0\u05C3]")
_MARKER_RE = re.compile(r"⟦H:([^⟧]*)⟧")
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
            if len(before) < 2 or len(after) < 2:
                missed += 1
                continue
            sep = r"[\W\d_]*"
            pattern = (sep.join(re.escape(w) for w in before) + r"(?P<junk>[^\n]{0,30}?)"
                       + sep.join(re.escape(w) for w in after[:2]))
            m = re.compile(pattern, re.IGNORECASE).search(text, cursor, cursor + 60000)
            if not m or _DUTCH_WORD_RE.search(m.group("junk").strip(" .,;:„”’\"'()")) and \
                    len(m.group("junk").strip()) > 12:
                missed += 1
                continue
            junk_start, junk_end = m.span("junk")
            # keep the punctuation after the junk (the quotation's own ".")
            core = m.group("junk").rstrip(" .,;:”’\"'")
            text = (text[:junk_start] + f" ⟦H:{guess}⟧" + text[junk_start + len(core):])
            cursor = junk_start + len(guess) + 6
            found += 1
    return text, found, missed


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
    return max(options, key=lambda o: (counts.get(o, 0), o in lexical))


def fill_hebrew(dutch: str, latin_texts: list[str], chapter: int | None, verse: int | None,
                bible: dict, strongs: dict, stats: dict) -> str:
    """Replace the ⟦H:guess⟧ markers of one Dutch comment by pointed Hebrew
    (see the block comment above)."""
    markers = list(_MARKER_RE.finditer(dutch))
    if not markers:
        return dutch
    latin_words = [w for t in latin_texts for w in _HEB_WORD_RE.findall(t)]
    chosen: list[str] = []
    if len(latin_words) == len(markers):
        chosen = latin_words
    else:
        # Different counts: match each marker to the Latin word most like
        # its own (poor) reading, keeping the order.
        pos = 0
        for m in markers:
            guess = consonants(m.group(1).replace(" ", ""))
            best, best_k = None, None
            for k in range(pos, len(latin_words)):
                ratio = difflib.SequenceMatcher(a=guess, b=latin_words[k]).ratio()
                if ratio >= 0.5 and (best is None or ratio > best):
                    best, best_k = ratio, k
            if best_k is not None:
                chosen.append(latin_words[best_k])
                pos = best_k + 1
            else:
                chosen.append(consonants(m.group(1)).replace(" ", ""))
                stats["from_dutch_ocr"] += 1
    out, last = [], 0
    for m, word in zip(markers, chosen):
        out.append(dutch[last:m.start()])
        out.append(vocalise(word, chapter, verse, bible, strongs, guess=m.group(1)))
        last = m.end()
        stats["filled"] += 1
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
    ap.add_argument("--hebrew-ocr-dirs", type=Path, nargs=2, default=[RAW / "los_ocr1", RAW / "los_ocr2"],
                    help="nld+heb OCR of Los's two volumes (ocr_pdf_pages.py); skipped if missing")
    ap.add_argument("--hebrew-lexicon", type=Path, default=None,
                    help='JSON {"bible": [[chapter, verse, word], ...], "strongs": [[lemma, pos], ...]} '
                         "instead of reading hebrew_words / strongs_entries from the database")
    ap.add_argument("-o", "--output", type=Path)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    latin = [json.loads(line) for line in args.latin.read_text(encoding="utf-8").splitlines() if line.strip()]
    raw = "\n".join((args.raw_dir / f"los1900_deel{n}.txt").read_text(encoding="utf-8", errors="replace")
                    for n in (1, 2))

    lines = raw.split("\n")
    cleaned = ["\x01" if is_heading(l) else ("" if is_noise(l) else l.rstrip()) for l in lines]
    text = re.sub(r"(\w)-\s*\n\s*(\w)", r"\1\2", "\n".join(cleaned))

    warnings: list[str] = []
    rows: list[dict] = []

    heb_stats = {"filled": 0, "from_dutch_ocr": 0}
    have_heb = all(d.exists() for d in args.hebrew_ocr_dirs)
    if have_heb:
        pages = [p.read_text(encoding="utf-8") for d in args.hebrew_ocr_dirs for p in sorted(d.glob("p*.txt"))]
        text, found, missed = mark_hebrew(text, pages)
        print(f"[heb]   {found} Hebrew quotations located in the text, {missed} not")
        bible, strongs = load_hebrew_lexicon(args.hebrew_lexicon)
    else:
        warnings.append("no Hebrew OCR pass found -- Hebrew left as the OCR read it")

    # Argumentum.
    start, end = _ARGUMENT_START_RE.search(text), _ARGUMENT_END_RE.search(text)
    latin_args = [r for r in latin if r["kind"] == "argument"]
    if start and end and latin_args:
        dutch_paras = paragraphs(text[start.start():end.start()])
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
        for p in split_inline_lemmas(paras[comment_start:]):
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
              f"({heb_stats['from_dutch_ocr']} from the Dutch OCR's own reading); {left} markers left")
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
