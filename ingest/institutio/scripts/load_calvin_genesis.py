#!/usr/bin/env python3
"""Load the Calvin Genesis-commentary pilot into PostgreSQL: the Latin
segments of parse_calvin_genesis_la.py and the Dutch 'los1900' layer of
parse_calvin_genesis_nl.py (see db/migrate_add_commentary_segments.sql).

Same idempotent upsert as load_hc.py / load_hc_nl_denheijer.py: re-running
updates texts in place; a segment whose Latin text changed goes back to
status 'ingested' (so tokenize_latin.py picks it up again).

    python scripts/load_calvin_genesis.py /data/institutio/calvin_genesis_la.jsonl \\
        /data/institutio/calvin_genesis_nl.jsonl

Requires: psycopg[binary]
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection

WORK_SLUG = "calvijn-genesis"
WORK_TITLE = "Commentarius in Genesin (Johannes Calvijn, 1554)"
WORK_SOURCE = (
    "Latin: Calvini Opera vol. 23 (Corpus Reformatorum 51, ed. Baum/Cunitz/Reuss, "
    "Brunswick 1882), open-access PDF of the University of Geneva "
    "(archive-ouverte.unige.ch/unige:650). OCR: the PDF's own text layer combined "
    "with a Tesseract re-OCR, plus corrections of known OCR confusions -- about 10% "
    "of words still unknown to the Institutio vocabulary (mostly names and rarer "
    "words, some OCR errors; Greek/Hebrew quotations garbled). Dutch: S.O. Los, "
    "Genesis. Uitlegging van Johannes Calvijn, Middelburg 1900 (public domain), "
    "archive.org OCR combined with a Tesseract re-OCR (full-resolution page scans "
    "where the text quotes Hebrew), OCR errors corrected against the transcription "
    "of Los's text on reformata.nl (J.K. Abbink, 2013), matched per comment."
)
LAYER = "los1900"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("latin", type=Path)
    ap.add_argument("dutch", type=Path)
    args = ap.parse_args()

    read = lambda p: [json.loads(line) for line in p.read_text(encoding="utf-8").splitlines() if line.strip()]
    latin, dutch = read(args.latin), read(args.dutch)
    print(f"[load] {len(latin)} Latin segments, {len(dutch)} Dutch rows")

    with get_connection() as conn, conn.cursor() as cur:
        cur.execute(
            """INSERT INTO work (slug, title, language, source)
               VALUES (%s, %s, 'la', %s)
               ON CONFLICT (slug) DO UPDATE SET title = EXCLUDED.title, source = EXCLUDED.source
               RETURNING id""",
            (WORK_SLUG, WORK_TITLE, WORK_SOURCE))
        work_id = cur.fetchone()[0]

        # seq is unique per work: shift existing rows out of the way first so
        # a re-run with a different segmentation can't collide mid-update.
        cur.execute("UPDATE segment SET seq = -seq WHERE work_id = %s", (work_id,))
        for r in latin:
            cur.execute(
                """INSERT INTO segment (work_id, book, chapter, section, kind, ref, seq, heading, print_ref, text_la)
                   VALUES (%(work_id)s, 1, %(chapter)s, %(section)s, %(kind)s, %(ref)s, %(seq)s, NULL,
                           %(print_ref)s, %(text)s)
                   ON CONFLICT (work_id, ref) DO UPDATE
                     SET text_la   = EXCLUDED.text_la,
                         kind      = EXCLUDED.kind,
                         chapter   = EXCLUDED.chapter,
                         section   = EXCLUDED.section,
                         seq       = EXCLUDED.seq,
                         print_ref = EXCLUDED.print_ref,
                         status    = CASE WHEN segment.text_la = EXCLUDED.text_la
                                          THEN segment.status ELSE 'ingested' END""",
                {**r, "work_id": work_id,
                 "print_ref": f"CO 23, {r['co_col']}" if r.get("co_col") else None})
        # The edition's footnotes (parse_calvin_genesis_la.py) as annotations
        # at their call's position in text_la -- replaced wholesale.
        cur.execute("""DELETE FROM segment_annotation
                       WHERE segment_id IN (SELECT id FROM segment WHERE work_id = %s)""", (work_id,))
        n_notes = 0
        for r in latin:
            for note in r.get("notes", []):
                cur.execute(
                    """INSERT INTO segment_annotation (segment_id, char_position, glyph, kind, note)
                       SELECT id, %s, %s, 'variant', %s FROM segment WHERE work_id = %s AND ref = %s""",
                    (note["pos"], note["glyph"], note["note"], work_id, r["ref"]))
                n_notes += 1
        print(f"[load] {n_notes} editorial footnotes")

        refs = [r["ref"] for r in latin]
        cur.execute("DELETE FROM segment WHERE work_id = %s AND NOT (ref = ANY(%s))", (work_id, refs))
        if cur.rowcount:
            print(f"[load] removed {cur.rowcount} segments no longer in the parse")

        n_ok = n_missing = 0
        for r in dutch:
            cur.execute("SELECT id FROM segment WHERE work_id = %s AND ref = %s", (work_id, r["ref"]))
            seg = cur.fetchone()
            if seg is None:
                print(f"[warn]  no segment for {r['ref']!r}")
                n_missing += 1
                continue
            cur.execute(
                """INSERT INTO translation (segment_id, layer, text_nl, model)
                   VALUES (%s, %s, %s, %s)
                   ON CONFLICT (segment_id, layer) DO UPDATE
                     SET text_nl = EXCLUDED.text_nl, model = EXCLUDED.model""",
                (seg[0], r["layer"], r["text"], r["model"]))
            n_ok += 1

        cur.execute("SELECT kind, count(*) FROM segment WHERE work_id = %s GROUP BY kind ORDER BY kind", (work_id,))
        print(f"[ok]   segments: {dict(cur.fetchall())}")
        print(f"[ok]   Dutch '{LAYER}' rows: {n_ok} loaded, {n_missing} without a segment")
    return 0


if __name__ == "__main__":
    sys.exit(main())
