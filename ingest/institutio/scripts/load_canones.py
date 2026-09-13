#!/usr/bin/env python3
"""Load parse_canones.py's segments.jsonl into PostgreSQL.

Same idempotent upsert pattern as load_segments.py (the Institutio's own
loader): existing segments (same work + ref) are updated, not duplicated.
A separate script rather than a shared/parametrized one because this work
also writes segment.kind, which load_segments.py's rows never carry.

    python scripts/load_canones.py /data/institutio/canones_segments.jsonl

Requires: psycopg[binary]
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection

WORK_SLUG = "canones-dordraceni"
WORK_TITLE = "Canones Synodi Dordrechtanae (1619)"
WORK_SOURCE = (
    "Philip Schaff, Creeds of Christendom, Vol. III (1877), pp. 550-580 -- "
    "clean transcribed text, not raw OCR (ccel.org/ccel/schaff/creeds3.iv.xvi.html). "
    "Latin is the original synodical language (not a later translation): the Canones "
    "were drafted and ratified in Latin at the Synod of Dort, 1618-1619."
)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("jsonl", type=Path, nargs="?",
                    default=Path("/data/institutio/canones_segments.jsonl"))
    args = ap.parse_args()

    rows = [json.loads(line) for line in args.jsonl.read_text(encoding="utf-8").splitlines()
            if line.strip()]
    print(f"[load] {len(rows)} segments from {args.jsonl}")

    with get_connection() as conn, conn.cursor() as cur:
        cur.execute(
            """INSERT INTO work (slug, title, language, source)
               VALUES (%s, %s, 'la', %s)
               ON CONFLICT (slug) DO UPDATE SET title = EXCLUDED.title, source = EXCLUDED.source
               RETURNING id""",
            (WORK_SLUG, WORK_TITLE, WORK_SOURCE))
        work_id = cur.fetchone()[0]

        for r in rows:
            cur.execute(
                """INSERT INTO segment (work_id, book, chapter, section, kind, ref, seq, heading, text_la)
                   VALUES (%(work_id)s, %(book)s, %(chapter)s, %(section)s, %(kind)s,
                           %(ref)s, %(seq)s, %(heading)s, %(text)s)
                   ON CONFLICT (work_id, ref) DO UPDATE
                     SET text_la = EXCLUDED.text_la,
                         heading = EXCLUDED.heading,
                         kind    = EXCLUDED.kind,
                         seq     = EXCLUDED.seq,
                         status  = CASE WHEN segment.text_la = EXCLUDED.text_la
                                        THEN segment.status ELSE 'ingested' END""",
                {**r, "work_id": work_id})

        cur.execute("SELECT count(*) FROM segment WHERE work_id = %s", (work_id,))
        print(f"[ok]   segments in database: {cur.fetchone()[0]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
