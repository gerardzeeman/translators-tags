#!/usr/bin/env python3
"""Tokenize and lemmatize all segments with LatinCy (spaCy).

Reads segments with status 'ingested', writes tokens (surface, norm, lemma,
POS, morphology, char offsets) and sets status to 'tokenized'.
Resumable: interrupting and restarting is always safe.

Usage:

    python scripts/tokenize_latin.py                    # with the LatinCy model
    python scripts/tokenize_latin.py --model la_core_web_sm
    python scripts/tokenize_latin.py --blank --limit 5   # smoke test, no model

Requires: psycopg[binary], spacy (+ LatinCy model, installed in the Dockerfile)
"""
from __future__ import annotations

import argparse
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
    """Normalised form for frequency counts and lexicon lookups.

    Lowercase + NFC + classical orthography unification v->u, j->i
    (neo-Latin editions vary here; LatinCy lemmas use u/i).
    """
    s = unicodedata.normalize("NFC", surface).lower()
    return s.replace("v", "u").replace("j", "i")


def load_nlp(model: str, blank: bool):
    import spacy
    if blank:
        nlp = spacy.blank("la")
        print("[nlp]  blank Latin pipeline (tokenisation only, no lemmas) — smoke test")
        return nlp
    nlp = spacy.load(model, exclude=["ner"])
    print(f"[nlp]  {model} loaded")
    return nlp


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--model", default=os.environ.get("LATINCY_MODEL", "la_core_web_lg"))
    ap.add_argument("--blank", action="store_true",
                    help="run without a language model (smoke test; no lemmas)")
    ap.add_argument("--limit", type=int, default=None,
                    help="max number of segments (for testing)")
    args = ap.parse_args()

    nlp = load_nlp(args.model, args.blank)

    with get_connection() as conn, conn.cursor() as cur:
        q = """SELECT id, text_la FROM segment
               WHERE status = 'ingested' ORDER BY seq"""
        if args.limit:
            q += f" LIMIT {int(args.limit)}"
        cur.execute(q)
        todo = cur.fetchall()
        print(f"[work] {len(todo)} segments to tokenize")

        done = 0
        for i in range(0, len(todo), BATCH):
            chunk = todo[i:i + BATCH]
            docs = nlp.pipe([t for _, t in chunk])
            for (seg_id, _), doc in zip(chunk, docs):
                rows = []
                for tok in doc:
                    if tok.is_space:
                        continue
                    is_word = bool(WORD_RE.search(tok.text))
                    lemma = tok.lemma_ if (is_word and tok.lemma_) else None
                    rows.append((
                        seg_id, tok.i, tok.text, normalize(tok.text),
                        normalize(lemma) if lemma else None,
                        tok.pos_ or None, str(tok.morph) or None,
                        tok.idx, tok.idx + len(tok.text), is_word,
                    ))
                cur.execute("DELETE FROM token WHERE segment_id = %s", (seg_id,))
                cur.executemany(
                    """INSERT INTO token (segment_id, position, surface, norm, lemma,
                                          upos, morph, char_start, char_end, is_word)
                       VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""", rows)
                cur.execute("UPDATE segment SET status = 'tokenized' WHERE id = %s", (seg_id,))
            conn.commit()
            done += len(chunk)
            print(f"[..]   {done}/{len(todo)}", end="\r", flush=True)

        print()
        cur.execute("SELECT * FROM corpus_stats")
        for slug, n_seg, n_tok, n_lem in cur.fetchall():
            print(f"[stat] {slug}: {n_seg} segments, "
                  f"{(n_tok or 0):,} word tokens, {(n_lem or 0):,} unique lemmas")
        cur.execute("SELECT lemma, freq FROM lemma_stats LIMIT 15")
        top = cur.fetchall()
        if top:
            print("[stat] top-15 lemmas:", ", ".join(f"{l} ({f})" for l, f in top))
    return 0


if __name__ == "__main__":
    sys.exit(main())
