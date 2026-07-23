#!/usr/bin/env python3
"""Load parse_weijenberg.py's output into the translation table as a new
layer (layer='weijenberg1865'), matched to already-loaded Latin segments
by `ref` alone -- Weijenberg's book/chapter/section numbering follows the
same canonical structure as the existing institutio-1559 segments, so no
alignment step is needed, just a straight ref lookup.

Segments with no matching ref (Calvin's dedicatory letter to Francis I --
this edition doesn't include it, see parse_weijenberg.py's docstring) are
reported and skipped, not treated as errors.

    python scripts/load_weijenberg.py /data/institutio/weijenberg.jsonl

Requires: psycopg[binary]
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection

WORK_SLUG = 'institutio-1559'
LAYER = 'weijenberg1865'


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument('jsonl', type=Path, nargs='?',
                    default=Path('/data/institutio/weijenberg.jsonl'))
    args = ap.parse_args()

    rows = [json.loads(line) for line in args.jsonl.read_text(encoding='utf-8').splitlines()
            if line.strip()]
    print(f"[load] {len(rows)} sections from {args.jsonl}")

    n_ok = n_missing = 0
    missing_refs = []
    with get_connection() as conn, conn.cursor() as cur:
        cur.execute("SELECT id FROM work WHERE slug = %s", (WORK_SLUG,))
        work_row = cur.fetchone()
        if work_row is None:
            print(f"[error] work '{WORK_SLUG}' not found -- run phase 1 first")
            return 1
        work_id = work_row[0]

        for r in rows:
            cur.execute(
                "SELECT id FROM segment WHERE work_id = %s AND ref = %s",
                (work_id, r['ref']))
            seg_row = cur.fetchone()
            if seg_row is None:
                n_missing += 1
                missing_refs.append(r['ref'])
                continue
            segment_id = seg_row[0]
            cur.execute(
                """INSERT INTO translation (segment_id, layer, text_nl, model)
                   VALUES (%s, %s, %s, 'manual-transcription')
                   ON CONFLICT (segment_id, layer) DO UPDATE
                       SET text_nl = EXCLUDED.text_nl""",
                (segment_id, LAYER, r['text']))
            n_ok += 1

    print(f"[ok]   {n_ok} sections loaded as layer='{LAYER}'")
    if n_missing:
        print(f"[skip] {n_missing} sections had no matching segment ref (e.g. front matter):")
        for ref in missing_refs[:15]:
            print(f"       {ref}")
        if len(missing_refs) > 15:
            print(f"       ... and {len(missing_refs) - 15} more")
    return 0


if __name__ == '__main__':
    sys.exit(main())
