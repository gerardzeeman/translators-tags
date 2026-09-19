#!/usr/bin/env python3
"""Tokenize and lemmatize a Latin `translation` layer with LatinCy.

Same algorithm as tokenize_latin.py (LatinCy's tokenizer rewrites v/u and
collapses some whitespace, so token offsets are remapped back onto the
stored text via character-level sequence alignment), applied to
translation.text_nl for one layer instead of segment.text_la -- see
db/migrate_add_translation_token.sql for why this needed its own table
rather than reusing `token`.

Requires --layer to be named explicitly (no default): this must never be
run against a Dutch layer (denheijer, zwanepol-hsv) by accident, since
running a Latin NLP model on Dutch text would silently produce garbage
lemmas rather than an error.

Usage:
    python scripts/tokenize_translation_latin.py --layer editio-princeps-1563
    python scripts/tokenize_translation_latin.py --layer editio-princeps-1563 --limit 5

Requires: psycopg[binary], spacy (+ LatinCy model, installed in the Dockerfile)
"""
from __future__ import annotations

import argparse
import difflib
import os
import re
import sys
import unicodedata
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection

BATCH = 64
WORD_RE = re.compile(r"\w", re.UNICODE)


def normalize(surface: str) -> str:
    s = unicodedata.normalize("NFC", surface).lower()
    return s.replace("v", "u").replace("j", "i")


def build_char_map(original: str, normalized: str) -> list[int]:
    """See tokenize_latin.py's build_char_map -- identical algorithm."""
    m = [0] * (len(normalized) + 1)
    opcodes = difflib.SequenceMatcher(None, original, normalized, autojunk=False).get_opcodes()
    for tag, i1, i2, j1, j2 in opcodes:
        if tag == "equal":
            for k in range(j1, j2):
                m[k] = i1 + (k - j1)
        elif tag == "replace":
            span_i, span_j = i2 - i1, j2 - j1
            for k in range(j1, j2):
                frac = (k - j1) / span_j if span_j else 0
                m[k] = i1 + round(frac * span_i)
        elif tag == "insert":
            for k in range(j1, j2):
                m[k] = i1
        m[j2] = i2
    return m


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--layer", required=True,
                    help="translation.layer to tokenize -- must be a Latin layer "
                         "(e.g. editio-princeps-1563), never a Dutch one")
    ap.add_argument("--model", default=os.environ.get("LATINCY_MODEL", "la_core_web_lg"))
    ap.add_argument("--limit", type=int, default=None)
    args = ap.parse_args()

    import spacy
    nlp = spacy.load(args.model, exclude=["ner"])
    print(f"[nlp]  {args.model} loaded")

    with get_connection() as conn, conn.cursor() as cur:
        q = "SELECT id, text_nl FROM translation WHERE layer = %s ORDER BY id"
        params: tuple = (args.layer,)
        if args.limit:
            q += " LIMIT %s"
            params = (args.layer, args.limit)
        cur.execute(q, params)
        todo = cur.fetchall()
        print(f"[work] {len(todo)} '{args.layer}' rows to tokenize")

        done = 0
        for i in range(0, len(todo), BATCH):
            chunk = todo[i:i + BATCH]
            docs = nlp.pipe([t for _, t in chunk])
            for (translation_id, text), doc in zip(chunk, docs):
                char_map = build_char_map(text, doc.text) if doc.text != text else None
                rows = []
                for tok in doc:
                    if tok.is_space:
                        continue
                    norm_start, norm_end = tok.idx, tok.idx + len(tok.text)
                    if char_map is not None:
                        char_start, char_end = char_map[norm_start], char_map[norm_end]
                    else:
                        char_start, char_end = norm_start, norm_end
                    surface = text[char_start:char_end]
                    is_word = bool(WORD_RE.search(surface))
                    lemma = tok.lemma_ if (is_word and tok.lemma_) else None
                    rows.append((
                        translation_id, tok.i, surface, normalize(surface),
                        normalize(lemma) if lemma else None,
                        tok.pos_ or None, str(tok.morph) or None,
                        char_start, char_end, is_word,
                    ))
                cur.execute("DELETE FROM translation_token WHERE translation_id = %s", (translation_id,))
                cur.executemany(
                    """INSERT INTO translation_token (translation_id, position, surface, norm,
                                                       lemma, upos, morph, char_start, char_end, is_word)
                       VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""", rows)
            conn.commit()
            done += len(chunk)
            print(f"[..]   {done}/{len(todo)}", end="\r", flush=True)
        print()
        print(f"[ok]    tokenized {done} '{args.layer}' rows")
    return 0


if __name__ == "__main__":
    sys.exit(main())
