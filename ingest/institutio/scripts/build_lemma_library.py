#!/usr/bin/env python3
"""Build/refresh the numbered lemma library.

`lemma_gloss` is one lexicon shared across every ingested work (keyed on
`lemma` alone) -- this script:

1. Adds a placeholder row (gloss_nl left NULL) for any lemma that occurs
   in the corpus but has no lemma_gloss row yet, so it still gets a
   library number and shows up on the library page (just marked as not
   yet glossed) instead of being invisible.
2. (Re-)assigns `number` = 1 for the most frequent lemma across the whole
   corpus (all currently-ingested works, combined -- see the `lemma_stats`
   view, which was already work-agnostic before this script existed), 2
   for the next, and so on, tie-broken alphabetically for determinism. A
   lemma_gloss row whose lemma isn't used in any currently-ingested work
   is left with number = NULL rather than guessed at.

Safe to re-run any time a work is added/removed or segments are
re-tokenized -- step 1 only inserts what's missing, and step 2
recomputes every number from scratch rather than incrementally patching,
so ranks never drift out of sync with the actual corpus.

Usage:
    python scripts/build_lemma_library.py
    python scripts/build_lemma_library.py --dry-run

Requires: psycopg[binary]
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dry-run", action="store_true", help="report counts only, write nothing")
    args = ap.parse_args()

    with get_connection() as conn, conn.cursor() as cur:
        cur.execute("""
            SELECT count(*) FROM (
                SELECT DISTINCT t.lemma
                FROM token t
                LEFT JOIN lemma_gloss lg ON lg.lemma = t.lemma
                WHERE t.is_word AND t.lemma IS NOT NULL AND lg.lemma IS NULL
            ) missing
        """)
        n_missing = cur.fetchone()[0]
        print(f"[plan] {n_missing} corpus lemmas have no lemma_gloss row yet")

        if args.dry_run:
            cur.execute("SELECT count(DISTINCT lemma) FROM token WHERE is_word AND lemma IS NOT NULL")
            print(f"[plan] {cur.fetchone()[0]} distinct lemmas in the corpus overall -- would be numbered 1..N")
            return 0

        cur.execute("""
            INSERT INTO lemma_gloss (lemma, source)
            SELECT DISTINCT t.lemma, 'llm'
            FROM token t
            LEFT JOIN lemma_gloss lg ON lg.lemma = t.lemma
            WHERE t.is_word AND t.lemma IS NOT NULL AND lg.lemma IS NULL
        """)
        print(f"[ok]    inserted {cur.rowcount} placeholder rows (no gloss yet)")

        # Two-phase: `number` is UNIQUE, and a full re-rank can reassign many
        # rows' numbers in the same pass (e.g. after a lemma string changes
        # upstream -- see tokenize_greek_quotes.py). Going straight from old
        # value to new in one UPDATE risks two rows momentarily sharing a
        # number mid-statement (Postgres checks a plain UNIQUE constraint
        # per row, not deferred to end-of-statement) and aborting with a
        # UniqueViolation. Clearing to NULL first (multiple NULLs are always
        # allowed) means the second UPDATE only ever assigns each row a
        # fresh, mutually distinct rn -- never colliding with anything.
        cur.execute("UPDATE lemma_gloss SET number = NULL")
        cur.execute("""
            WITH ranked AS (
                SELECT lemma, row_number() OVER (ORDER BY count(*) DESC, lemma ASC) AS rn
                FROM token
                WHERE is_word AND lemma IS NOT NULL
                GROUP BY lemma
            )
            UPDATE lemma_gloss lg
            SET number = ranked.rn
            FROM ranked
            WHERE lg.lemma = ranked.lemma
        """)
        print(f"[ok]    numbered {cur.rowcount} lemma_gloss rows by corpus frequency")

        cur.execute("SELECT count(*) FROM lemma_gloss WHERE number IS NULL")
        print(f"[stat]  {cur.fetchone()[0]} lemma_gloss rows left unnumbered (not used in any current work)")

        cur.execute("""
            SELECT number, lemma, gloss_nl FROM lemma_gloss
            WHERE number IS NOT NULL ORDER BY number LIMIT 10
        """)
        print("[stat]  top 10:")
        for number, lemma, gloss in cur.fetchall():
            print(f"          {number:>3}. {lemma:<15} {gloss or '(nog geen gloss)'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
