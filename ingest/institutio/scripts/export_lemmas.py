#!/usr/bin/env python3
"""Export the unique lemma list for the LLM glossary batch run (phase 2.1).

Reads the lemma_stats view (frequency-sorted) and writes a CSV so the
highest-frequency, most cost-relevant lemmas can be reviewed/prioritised
before the batch run in batch_gloss.py.

    python scripts/export_lemmas.py -o /data/institutio/lemma_stats.csv

For a newly added work, only the lemmas it adds -- those without a gloss
yet -- and only the ones frequent enough to be worth an LLM call (the rare
ones are mostly OCR noise and names):

    python scripts/export_lemmas.py --work calvijn-genesis --missing-only --min-freq 3 \
        -o /data/institutio/genesis_missing_lemmas.csv
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
    ap.add_argument("--work", default=None, help="only this work's lemmas (work slug)")
    ap.add_argument("--missing-only", action="store_true", help="only lemmas without a gloss yet")
    ap.add_argument("--min-freq", type=int, default=1)
    args = ap.parse_args()

    with get_connection() as conn, conn.cursor() as cur:
        if args.work is None and not args.missing_only and args.min_freq <= 1:
            cur.execute(
                """SELECT lemma, freq, n_segments
                   FROM lemma_stats
                   WHERE lemma IS NOT NULL
                   ORDER BY freq DESC"""
            )
        else:
            cur.execute(
                """SELECT t.lemma, count(*) AS freq, count(DISTINCT t.segment_id) AS n_segments
                   FROM token t
                   JOIN segment s ON s.id = t.segment_id
                   JOIN work w ON w.id = s.work_id
                   LEFT JOIN lemma_gloss lg ON lg.lemma = t.lemma
                   WHERE t.is_word AND t.lemma IS NOT NULL
                     AND (%(work)s::text IS NULL OR w.slug = %(work)s)
                     AND (NOT %(missing)s OR lg.lemma IS NULL)
                     -- real words only: no numbers, single letters, OCR
                     -- fragments ("beer-") or Roman numerals ("xxiii")
                     AND t.lemma ~ '^[[:alpha:]]{3,}$'
                     AND t.lemma !~* '^[ivxlcdm]+$'
                   GROUP BY t.lemma
                   HAVING count(*) >= %(min_freq)s
                   ORDER BY freq DESC, t.lemma""",
                {"work": args.work, "missing": args.missing_only, "min_freq": args.min_freq},
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
