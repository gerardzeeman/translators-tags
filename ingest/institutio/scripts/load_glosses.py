#!/usr/bin/env python3
"""Load batch_gloss.py results into the lemma_gloss table (phase 2.3).

    python scripts/load_glosses.py /data/institutio/lemma_glosses.jsonl
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("jsonl", type=Path, nargs="?",
                    default=Path("/data/institutio/lemma_glosses.jsonl"))
    args = ap.parse_args()

    rows = [json.loads(line) for line in args.jsonl.read_text(encoding="utf-8").splitlines()
            if line.strip()]
    print(f"[load] {len(rows)} glosses from {args.jsonl}")

    with get_connection() as conn, conn.cursor() as cur:
        cur.executemany(
            """INSERT INTO lemma_gloss (lemma, gloss_nl, gloss_alt, note, source)
               VALUES (%(lemma)s, %(gloss_nl)s, %(gloss_alt)s, %(note)s, 'llm')
               ON CONFLICT (lemma) DO UPDATE SET
                   gloss_nl = EXCLUDED.gloss_nl,
                   gloss_alt = EXCLUDED.gloss_alt,
                   note = EXCLUDED.note""",
            rows,
        )
        cur.execute("SELECT count(*) FROM lemma_gloss")
        print(f"[ok]   lemma_gloss rows in database: {cur.fetchone()[0]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
