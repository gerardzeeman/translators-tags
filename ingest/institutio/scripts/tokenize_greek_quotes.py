#!/usr/bin/env python3
"""Re-lemmatize Greek-script tokens embedded in Latin text with a real
Ancient Greek NLP model (OdyCy), instead of leaving them at whatever
LatinCy's Latin-only pipeline produced.

Calvin (and, more sparingly, the confession authors) occasionally quotes
Greek within an otherwise-Latin sentence -- e.g. Institutio 1.13.4's
ὁμοουσίου or NGB art. 10's ὑποστάσεως. tokenize_latin.py's word boundaries
for these spans are fine (LatinCy's tokenizer splits on whitespace/
punctuation same as for Latin), but its *lemma* for them is not: LatinCy's
own pipeline just runs its generic harmonizer/normalizer over whatever
text it sees, Greek included, which strips diacritics but does no real
morphological analysis -- so an inflected form like "υποστασεως" (genitive
of ὑπόστασις) ends up stored as its own unrelated "lemma", indistinguishable
from a genuinely different word, and never matches Strong's own (properly
accented, dictionary-form) lemma.

This script only touches `token.lemma` for tokens whose surface is Greek
script -- char_start/char_end and every other column are left exactly as
tokenize_latin.py wrote them, since the word boundaries themselves are
already correct.

Approach: Greek tokens are grouped into contiguous runs (a "quotation")
per segment, and each run's substring is re-parsed by OdyCy as one small
Greek text -- giving its tagger/lemmatizer sentence-level context, the
same reason tokenize_translation_latin.py parses whole translation rows
rather than isolated words. OdyCy's own tokenization of that substring is
matched back to the original LatinCy token spans by relative character
offset; a run where the two tokenizers disagree on word boundaries is
skipped and reported rather than guessed at.

OdyCy's lemma accuracy is reported at ~94% on its training treebanks, and
it silently returns a token's surface form unchanged for words outside
its vocabulary (mostly rare theological/patristic compounds like
συναΐδιον) rather than fabricating a wrong lemma -- those keep behaving
exactly as before this script existed.

Usage:
    python scripts/tokenize_greek_quotes.py --dry-run
    python scripts/tokenize_greek_quotes.py

Requires: psycopg[binary], spacy (+ OdyCy model, installed in the Dockerfile)
"""
from __future__ import annotations

import argparse
import re
import sys
import unicodedata
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection

GREEK_RE = re.compile(r"[Ͱ-Ͽἀ-῿]")
# A run breaks once the gap between two Greek tokens (Latin words, or more
# than a few punctuation/space characters) is large enough that they're
# clearly not part of the same quoted phrase.
MAX_GAP = 3


def unaccent_key(text: str) -> str:
    """Strips Greek accents/breathings/diaeresis for grouping purposes only
    (never stored) -- NFD decomposition + dropping all combining marks
    (Unicode category M*), which also catches the iota-diaeresis case that
    Postgres's own unaccent() leaves untouched.
    """
    decomposed = unicodedata.normalize("NFD", text)
    return "".join(c for c in decomposed if not unicodedata.category(c).startswith("M"))


def normalize(surface: str) -> str:
    """Same NFC-fold as tokenize_latin.py's normalize(), for consistency
    with every other lemma in the corpus -- deliberately does NOT strip
    Greek diacritics (unlike LatinCy's own output): the whole point here
    is a properly accented dictionary form that matches how Strong's
    stores its own Greek lemmas.
    """
    return unicodedata.normalize("NFC", surface).lower()


def group_into_runs(tokens: list[dict]) -> list[list[dict]]:
    runs: list[list[dict]] = []
    current: list[dict] = []
    for tok in tokens:
        if current and (
            tok["segment_id"] != current[-1]["segment_id"]
            or tok["char_start"] - current[-1]["char_end"] > MAX_GAP
        ):
            runs.append(current)
            current = []
        current.append(tok)
    if current:
        runs.append(current)
    return runs


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--model", default="grc_odycy_joint_sm")
    ap.add_argument("--dry-run", action="store_true", help="report proposed changes, write nothing")
    args = ap.parse_args()

    import spacy
    nlp = spacy.load(args.model)
    print(f"[nlp]  {args.model} loaded")

    with get_connection() as conn, conn.cursor() as cur:
        cur.execute("""
            SELECT t.id, t.segment_id, t.char_start, t.char_end, t.surface, t.lemma, s.text_la
            FROM token t
            JOIN segment s ON s.id = t.segment_id
            WHERE t.is_word AND t.surface ~ %s
            ORDER BY t.segment_id, t.char_start
        """, (GREEK_RE.pattern,))
        rows = cur.fetchall()
        tokens = [
            {
                "id": r[0], "segment_id": r[1], "char_start": r[2], "char_end": r[3],
                "surface": r[4], "old_lemma": r[5], "text_la": r[6],
            }
            for r in rows
        ]
        print(f"[work] {len(tokens)} Greek tokens found")

        runs = group_into_runs(tokens)
        print(f"[work] grouped into {len(runs)} Greek quotations")

        updates: list[tuple[str, int]] = []  # (new_lemma, token_id)
        unmatched_runs = 0
        for run in runs:
            text_la = run[0]["text_la"]
            phrase_start = run[0]["char_start"]
            phrase_end = run[-1]["char_end"]
            phrase = text_la[phrase_start:phrase_end]
            doc = nlp(phrase)
            # Our DB tokens are is_word-only (LatinCy stores punctuation --
            # commas, the Greek middle dot "·" -- as separate non-word
            # tokens, excluded from `run`); spaCy's own tokenization of the
            # same substring includes them, so filter those out here too
            # rather than let them desync the positional alignment below.
            spacy_toks = [t for t in doc if not t.is_space and any(c.isalpha() for c in t.text)]

            if len(spacy_toks) != len(run):
                unmatched_runs += 1
                print(f"[skip] tokenization mismatch ({len(run)} db tokens vs "
                      f"{len(spacy_toks)} spaCy tokens) in: {phrase!r}")
                continue

            for db_tok, sp_tok in zip(run, spacy_toks):
                if sp_tok.text != db_tok["surface"]:
                    unmatched_runs += 1
                    print(f"[skip] surface mismatch {sp_tok.text!r} != "
                          f"{db_tok['surface']!r} in: {phrase!r}")
                    break
                new_lemma = normalize(sp_tok.lemma_) if sp_tok.lemma_ else db_tok["old_lemma"]
                if new_lemma and new_lemma != db_tok["old_lemma"]:
                    updates.append((new_lemma, db_tok["id"]))

        # OdyCy's accent placement is noticeably less reliable than its stem
        # resolution -- the same underlying word can come back with the
        # accent on a different syllable (or missing) depending on the
        # sentence it's quoted in (observed empirically: "ομοουσιος" vs
        # "ομοουσίον" for what should be one lemma, ὁμοούσιος). Rather than
        # let that create several near-duplicate lemma_gloss entries for a
        # single word, collapse everything sharing an unaccented form onto
        # whichever exact spelling was proposed most often.
        from collections import Counter
        by_unaccented: dict[str, Counter] = {}
        for new_lemma, _ in updates:
            key = unaccent_key(new_lemma)
            by_unaccented.setdefault(key, Counter())[new_lemma] += 1
        canonical = {key: variants.most_common(1)[0][0] for key, variants in by_unaccented.items()}
        updates = [(canonical[unaccent_key(new_lemma)], tok_id) for new_lemma, tok_id in updates]

        print(f"[plan] {len(updates)} tokens would get a corrected lemma "
              f"({unmatched_runs} quotations skipped on tokenization mismatch)")
        for new_lemma, tok_id in updates[:20]:
            old = next(t["old_lemma"] for t in tokens if t["id"] == tok_id)
            print(f"         {old!r} -> {new_lemma!r}")
        if len(updates) > 20:
            print(f"         ... and {len(updates) - 20} more")

        if args.dry_run or not updates:
            return 0

        cur.executemany("UPDATE token SET lemma = %s WHERE id = %s", updates)
        conn.commit()
        print(f"[ok]    updated {len(updates)} token rows")
    return 0


if __name__ == "__main__":
    sys.exit(main())
