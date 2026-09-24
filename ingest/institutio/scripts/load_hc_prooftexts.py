#!/usr/bin/env python3
"""Load parse_hc_prooftexts_cgk.py's JSONL into segment_proof_text /
segment_proof_text_ref (see db/migrate_add_hc_proof_texts.sql), matching
each row to its segment by ref (work slug 'heidelbergse-catechismus').

Idempotent: all existing rows of the same source for this work are
replaced in one transaction. Every reference is checked against the HSV
text in translation_verses, so a mis-parsed chapter/verse shows up here
as a warning rather than as an empty verse panel in the app.

    python scripts/load_hc_prooftexts.py /data/institutio/hc_prooftexts_cgk.jsonl

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
    sources = {r["source"] for r in rows}
    print(f"[load] {len(rows)} letters from {args.jsonl} (source {', '.join(sorted(sources))})")

    n_letters = n_refs = n_missing_verse = 0
    with get_connection() as conn, conn.cursor() as cur:
        cur.execute("SELECT id FROM work WHERE slug = %s", (WORK_SLUG,))
        row = cur.fetchone()
        if row is None:
            print(f"[error] work '{WORK_SLUG}' not found -- ingest the Latin text first")
            return 1
        work_id = row[0]

        cur.execute("SELECT usfm_code, id FROM books")
        book_ids = dict(cur.fetchall())

        cur.execute(
            """DELETE FROM segment_proof_text
               WHERE source = ANY(%s)
                 AND segment_id IN (SELECT id FROM segment WHERE work_id = %s)""",
            (list(sources), work_id))
        print(f"[load] removed {cur.rowcount} existing letters")

        segment_ids: dict[str, int] = {}
        for r in rows:
            if r["ref"] not in segment_ids:
                cur.execute("SELECT id FROM segment WHERE work_id = %s AND ref = %s", (work_id, r["ref"]))
                seg = cur.fetchone()
                if seg is None:
                    print(f"[error] no segment for ref {r['ref']!r}")
                    conn.rollback()
                    return 1
                segment_ids[r["ref"]] = seg[0]

            cur.execute(
                """INSERT INTO segment_proof_text
                       (segment_id, source, glyph, ordinal, anchor, anchor_occurrence, refs_text)
                   VALUES (%s, %s, %s, %s, %s, %s, %s) RETURNING id""",
                (segment_ids[r["ref"]], r["source"], r["glyph"], r["ordinal"],
                 r["anchor"], r["anchor_occurrence"], r["refs_text"]))
            proof_text_id = cur.fetchone()[0]
            n_letters += 1

            for ordinal, ref in enumerate(r["refs"], start=1):
                book_id = book_ids.get(ref["usfm"])
                if book_id is None:
                    print(f"[error] {r['ref']} ({r['glyph']}): unknown book {ref['usfm']!r}")
                    conn.rollback()
                    return 1
                cur.execute(
                    """INSERT INTO segment_proof_text_ref
                           (proof_text_id, ordinal, book_id, chapter, verse_start, verse_end, label)
                       VALUES (%s, %s, %s, %s, %s, %s, %s)""",
                    (proof_text_id, ordinal, book_id, ref["chapter"],
                     ref["verse_start"], ref["verse_end"], ref["label"]))
                n_refs += 1

                cur.execute(
                    """SELECT count(*) FROM translation_verses tv
                       JOIN translations t ON t.id = tv.translation_id
                       WHERE t.code = 'HSV' AND tv.book_id = %s AND tv.chapter = %s
                         AND tv.verse BETWEEN %s AND %s""",
                    (book_id, ref["chapter"], ref["verse_start"], ref["verse_end"]))
                found = cur.fetchone()[0]
                expected = ref["verse_end"] - ref["verse_start"] + 1
                if found != expected:
                    print(f"[warn]  {r['ref']} ({r['glyph']}) {ref['label']}: "
                          f"{found}/{expected} verses found in the HSV")
                    n_missing_verse += 1

        conn.commit()

    print(f"[ok]    {n_letters} letters, {n_refs} references loaded; "
          f"{n_missing_verse} references with verses missing from the HSV")
    return 0


if __name__ == "__main__":
    sys.exit(main())
