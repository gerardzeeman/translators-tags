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
    """Normalised form for frequency counts and lexicon lookups.

    Lowercase + NFC + classical orthography unification v->u, j->i
    (neo-Latin editions vary here; LatinCy lemmas use u/i).
    """
    s = unicodedata.normalize("NFC", surface).lower()
    return s.replace("v", "u").replace("j", "i")


def build_char_map(original: str, normalized: str) -> list[int]:
    """Map every character position in `normalized` back to the
    corresponding position in `original`.

    LatinCy's tokenizer silently rewrites the text it tokenizes -- it
    regularizes v/u orthography and collapses the stray double space left
    behind wherever parse_calvin_reformation.py removed a footnote/citation
    marker that sat between two single spaces -- so `doc.text != text` and
    every tok.idx refers to this rewritten text, not segment.text_la
    (confirmed empirically: v<->u replaced in-place, single spaces deleted,
    affecting all 1278 segments to varying degrees). Without remapping,
    every token from the first altered point onward is positioned wrong
    relative to the stored text.

    Built via character-level sequence alignment rather than a constant
    offset, since deletions change the effective shift partway through a
    segment. Returns an array `m` of length len(normalized)+1 where
    m[j] is the original-text index corresponding to normalized-text
    index j (m[len(normalized)] is the corresponding end position).
    """
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
        # 'delete': original has content with no counterpart in normalized
        # (j1 == j2) -- nothing to map here, later positions are covered by
        # the next opcode.
        m[j2] = i2
    return m


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
    ap.add_argument("--all", action="store_true",
                    help="re-tokenize every segment regardless of status, not just "
                         "ones still at 'ingested' -- use this after text_la has "
                         "changed under already-tokenized segments (e.g. a later "
                         "parser change that alters footnote/citation handling), "
                         "which otherwise leaves stale char_start/char_end offsets "
                         "that no longer line up with the current text. Segments "
                         "already past 'ingested' keep their status (translation/"
                         "alignment progress isn't rolled back), only their tokens "
                         "are refreshed.")
    args = ap.parse_args()

    nlp = load_nlp(args.model, args.blank)

    with get_connection() as conn, conn.cursor() as cur:
        q = "SELECT id, text_la FROM segment"
        if not args.all:
            q += " WHERE status = 'ingested'"
        q += " ORDER BY seq"
        if args.limit:
            q += f" LIMIT {int(args.limit)}"
        cur.execute(q)
        todo = cur.fetchall()
        print(f"[work] {len(todo)} segments to tokenize")

        done = 0
        for i in range(0, len(todo), BATCH):
            chunk = todo[i:i + BATCH]
            docs = nlp.pipe([t for _, t in chunk])
            for (seg_id, text), doc in zip(chunk, docs):
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
                    # The true original substring, not tok.text -- LatinCy's
                    # tok.text may have been v/u-regularized (see
                    # build_char_map) and would otherwise desync `surface`
                    # from segment.text_la at these exact offsets.
                    surface = text[char_start:char_end]
                    is_word = bool(WORD_RE.search(surface))
                    lemma = tok.lemma_ if (is_word and tok.lemma_) else None
                    rows.append((
                        seg_id, tok.i, surface, normalize(surface),
                        normalize(lemma) if lemma else None,
                        tok.pos_ or None, str(tok.morph) or None,
                        char_start, char_end, is_word,
                    ))
                cur.execute("DELETE FROM token WHERE segment_id = %s", (seg_id,))
                cur.executemany(
                    """INSERT INTO token (segment_id, position, surface, norm, lemma,
                                          upos, morph, char_start, char_end, is_word)
                       VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""", rows)
                # Only advance status forward from 'ingested' -- with --all this
                # also refreshes already translated/aligned segments' tokens, and
                # those must keep their status so later phases don't think they
                # need (re-)translating.
                cur.execute(
                    "UPDATE segment SET status = 'tokenized' WHERE id = %s AND status = 'ingested'",
                    (seg_id,))
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
