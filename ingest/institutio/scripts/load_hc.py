#!/usr/bin/env python3
"""Load parse_hc.py's segments.jsonl into PostgreSQL.

Same idempotent upsert pattern as load_segments.py/load_canones.py.

    python scripts/load_hc.py /data/institutio/hc_segments.jsonl

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
WORK_TITLE = "Catechesis Palatina / Heidelbergse Catechismus (1563)"
WORK_SOURCE = (
    "heidelblog.net -- editorially reviewed transcribed text, not OCR "
    "(https://heidelblog.net/catechesis/, fetched via a Wayback Machine snapshot "
    "since the live site blocks non-browser requests). Latin translation by "
    "Josua Lagus and Lambertus Pithopoeus, 1563 -- not the original drafting "
    "language (German). Known source-quality caveat: the printed question numbers "
    "in this transcription are unreliable and were ignored in favour of positional "
    "counting; 127 of the 129 official questions were found as distinct 'Quaestio' "
    "paragraphs, two are marked with a visible [Antwoord/Quaestio ontbreekt in bron] "
    "gap, and up to two more official questions' content may be merged into a "
    "neighbouring segment rather than split out -- see parse_hc.py's docstring."
)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("jsonl", type=Path, nargs="?",
                    default=Path("/data/institutio/hc_segments.jsonl"))
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
                         book    = EXCLUDED.book,
                         chapter = EXCLUDED.chapter,
                         section = EXCLUDED.section,
                         seq     = EXCLUDED.seq,
                         status  = CASE WHEN segment.text_la = EXCLUDED.text_la
                                        THEN segment.status ELSE 'ingested' END""",
                {**r, "work_id": work_id})

        cur.execute("SELECT count(*) FROM segment WHERE work_id = %s", (work_id,))
        print(f"[ok]   segments in database: {cur.fetchone()[0]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
