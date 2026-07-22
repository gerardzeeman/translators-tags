#!/usr/bin/env python3
"""Export the unique lemma list for the LLM glossary batch run (phase 2.1).

Reads the lemma_stats view (frequency-sorted) and writes a CSV so the
highest-frequency, most cost-relevant lemmas can be reviewed/prioritised
before the batch run in batch_gloss.py.

    python scripts/export_lemmas.py -o /data/institutio/lemma_stats.csv
"""
from __future__ import annotations

import argparse
import csv
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("-o", "--output", type=Path,
                    default=Path("/data/institutio/lemma_stats.csv"))
    args = ap.parse_args()

    with get_connection() as conn, conn.cursor() as cur:
        cur.execute(
            """SELECT lemma, freq, n_segments
               FROM lemma_stats
               WHERE lemma IS NOT NULL
               ORDER BY freq DESC"""
        )
        rows = cur.fetchall()

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8", newline="") as f:
        writer = csv.writer(f)
        writer.writerow(["lemma", "freq", "n_segments"])
        writer.writerows(rows)

    print(f"[ok] {len(rows):,} unique lemmas written to {args.output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
