#!/usr/bin/env python3
"""Report alignment coverage (phase 4.3).

Prints word tokens that ended up without any alignment, and the
per-book alignment coverage ratio, so segments needing manual review
(phase 5, annotation UI) can be prioritised.

    python scripts/validate_alignment.py
    python scripts/validate_alignment.py --missing-limit 50
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--missing-limit", type=int, default=25,
                    help="max number of unaligned tokens to list")
    args = ap.parse_args()

    with get_connection() as conn, conn.cursor() as cur:
        cur.execute(
            """SELECT s.ref, t.surface, t.position
               FROM token t
               JOIN segment s ON s.id = t.segment_id
               LEFT JOIN alignment a ON a.token_id = t.id
               WHERE t.is_word AND a.id IS NULL
                 AND s.status = 'aligned'
               ORDER BY s.seq, t.position
               LIMIT %s""",
            (args.missing_limit,))
        missing = cur.fetchall()

        cur.execute(
            """SELECT s.book,
                      count(t.id) FILTER (WHERE t.is_word) AS tokens,
                      count(a.id) AS aligned
               FROM segment s
               JOIN token t ON t.segment_id = s.id
               LEFT JOIN alignment a ON a.token_id = t.id
               WHERE s.status = 'aligned'
               GROUP BY s.book
               ORDER BY s.book""")
        coverage = cur.fetchall()

    print("[coverage per book]")
    for book, tokens, aligned in coverage:
        pct = (aligned / tokens * 100) if tokens else 0.0
        print(f"  book {book!s:>6}: {aligned:>7,}/{tokens:<7,} tokens aligned ({pct:5.1f}%)")

    if missing:
        print(f"\n[unaligned tokens] (showing up to {args.missing_limit})")
        for ref, surface, position in missing:
            print(f"  {ref}  #{position}  {surface!r}")
    else:
        print("\n[unaligned tokens] none found among aligned segments")
    return 0


if __name__ == "__main__":
    sys.exit(main())
