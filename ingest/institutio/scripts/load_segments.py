#!/usr/bin/env python3
"""Load segments.jsonl into PostgreSQL.

Idempotent: existing segments (same work + ref) are updated, not duplicated.
Each segment's annotations (textual variants / citations) are replaced
wholesale on every load (delete then re-insert), since there's no natural
per-annotation key to upsert against other than char_position, which can
shift between parser runs if the parsing logic changes.

Connects via the shared DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD
environment variables (see db.py).

    python scripts/load_segments.py /data/institutio/segments.jsonl

Requires: psycopg[binary]
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection

WORK_SLUG = "institutio-1559"
WORK_TITLE = "Institutio christianae religionis (1559)"
WORK_SOURCE = "calvin.reformation.nl (Barth/Niesel critical edition)"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("jsonl", type=Path, nargs="?",
                    default=Path("/data/institutio/segments.jsonl"))
    args = ap.parse_args()

    rows = [json.loads(line) for line in args.jsonl.read_text(encoding="utf-8").splitlines()
            if line.strip()]
    print(f"[load] {len(rows)} segments from {args.jsonl}")

    n_annotations = 0
    with get_connection() as conn, conn.cursor() as cur:
        cur.execute(
            """INSERT INTO work (slug, title, language, source)
               VALUES (%s, %s, 'la', %s)
               ON CONFLICT (slug) DO UPDATE SET title = EXCLUDED.title
               RETURNING id""",
            (WORK_SLUG, WORK_TITLE, WORK_SOURCE))
        work_id = cur.fetchone()[0]

        for r in rows:
            cur.execute(
                """INSERT INTO segment (work_id, book, chapter, section, ref, seq, heading, text_la)
                   VALUES (%(work_id)s, %(book)s, %(chapter)s, %(section)s,
                           %(ref)s, %(seq)s, %(heading)s, %(text)s)
                   ON CONFLICT (work_id, ref) DO UPDATE
                     SET text_la = EXCLUDED.text_la,
                         heading = EXCLUDED.heading,
                         seq     = EXCLUDED.seq,
                         status  = CASE WHEN segment.text_la = EXCLUDED.text_la
                                        THEN segment.status ELSE 'ingested' END
                   RETURNING id""",
                {**r, "work_id": work_id})
            segment_id = cur.fetchone()[0]

            cur.execute("DELETE FROM segment_annotation WHERE segment_id = %s", (segment_id,))
            annotations = r.get("annotations") or []
            if annotations:
                cur.executemany(
                    """INSERT INTO segment_annotation
                           (segment_id, char_position, ord, glyph, kind, note)
                       VALUES (%(segment_id)s, %(char_position)s, %(ord)s, %(glyph)s, %(kind)s, %(note)s)
                       ON CONFLICT (segment_id, char_position, ord) DO UPDATE
                           SET glyph = EXCLUDED.glyph, kind = EXCLUDED.kind, note = EXCLUDED.note""",
                    [{**a, "segment_id": segment_id, "ord": a.get("ord", 0)} for a in annotations])
                n_annotations += len(annotations)

        cur.execute("SELECT count(*) FROM segment WHERE work_id = %s", (work_id,))
        print(f"[ok]   segments in database: {cur.fetchone()[0]}")
        print(f"[ok]   annotations loaded: {n_annotations}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
