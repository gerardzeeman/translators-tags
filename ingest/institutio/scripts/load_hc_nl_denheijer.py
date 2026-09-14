#!/usr/bin/env python3
"""Load parse_hc_nl_denheijer.py's JSONL into the `translation` table,
matching each row to its Latin segment by ref (work slug
'heidelbergse-catechismus').

    python scripts/load_hc_nl_denheijer.py hc_nl_denheijer.jsonl

Requires: psycopg[binary]
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection

WORK_SLUG = "heidelbergse-catechismus"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("jsonl", type=Path)
    args = ap.parse_args()

    rows = [json.loads(line) for line in args.jsonl.read_text(encoding="utf-8").splitlines()
            if line.strip()]
    print(f"[load] {len(rows)} rows from {args.jsonl}")

    n_ok = 0
    n_missing = 0
    with get_connection() as conn, conn.cursor() as cur:
        cur.execute("SELECT id FROM work WHERE slug = %s", (WORK_SLUG,))
        row = cur.fetchone()
        if row is None:
            print(f"[error] work '{WORK_SLUG}' not found -- ingest the Latin text first")
            return 1
        work_id = row[0]

        for r in rows:
            cur.execute(
                "SELECT id FROM segment WHERE work_id = %s AND ref = %s",
                (work_id, r["ref"]))
            seg = cur.fetchone()
            if seg is None:
                print(f"[warn]  no segment for ref {r['ref']!r} -- skipped")
                n_missing += 1
                continue
            cur.execute(
                """INSERT INTO translation (segment_id, layer, text_nl, model)
                   VALUES (%s, %s, %s, %s)
                   ON CONFLICT (segment_id, layer) DO UPDATE
                       SET text_nl = EXCLUDED.text_nl, model = EXCLUDED.model""",
                (seg[0], r["layer"], r["text"], r["model"]))
            n_ok += 1

    print(f"[ok]    {n_ok} translations loaded, {n_missing} skipped (no matching segment)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
