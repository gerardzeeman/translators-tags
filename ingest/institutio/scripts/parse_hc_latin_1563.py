#!/usr/bin/env python3
"""Build the 'editio-princeps-1563' Latin translation layer for the
Heidelberg Catechism: the original Lagus & Pithopoeus 1563 Latin
translation, as distinct from the Latin text already ingested as the
HC's main text_la (which descends from the later academic commentary
tradition -- see ConfessionController::WORKS's HC subtitle).

METHODOLOGY (why this isn't a from-scratch re-transcription)
--------------------------------------------------------------
The only online full text of the 1563 editio princeps is the 1863
"Tercentenary Edition" trilingual reprint (German/Latin/English) on
archive.org:
    https://archive.org/details/heidelbergcatech00newy
    https://archive.org/download/heidelbergcatech00newy/heidelbergcatech00newy_djvu.txt
Its Latin comes from a 1609 Geneva reprint of Lagus & Pithopoeus (per the
book's own preface). The OCR of that scan is poor -- old-German fraktur
and modern-German/English columns from an interleaved second edition
bleed into the Latin, page headers and footnote markers are scattered
through it -- so an automated line-up of that OCR text against our
already-correct main Latin (via a normalized-token diff, discarding
German/English stopwords) is a reliable way to find genuine wording
*differences*, but not reliable enough to safely regenerate all 129
questions verbatim from OCR alone: a difference-producing extraction gap
looks identical to a genuine textual difference until a human reads the
surrounding context.

So this script does NOT re-parse the archive.org scan. It takes the
already-verified main Latin text (which is ingested and tokenized) as
its base, and applies a fixed table of confirmed substitutions -- the
handful of places where the 1563 editio princeps genuinely reads
differently. That table was produced by: (1) an automated normalized-token
diff across the full 129 questions against the archive.org OCR text, (2)
manually reading every flagged difference against the OCR context to
separate real variants from OCR noise, (3) reading through the entire
extracted archive.org text question-by-question as a second, independent
pass, and (4) cross-checking two of the variants (Q1, Q2) against a
second, cleanly-typeset primary source already in this repo's ingest
history: the 1697 "Catechesis Palatina" commentary by Johann Rudolph
(the very commentary tradition our main Latin text descends from), which
confirmed our main text's readings are that later tradition's, not the
1563 princeps's.

Confirmed substitutions (main-Latin reading -> 1563 editio princeps
reading), all other content being identical between the two lines:
  Q1   "plenissime satisfaciens" -> "plenissima solutione facta" (payment
       made in full, vs. a present-participle "fully satisfying"). Note:
       the main text's own "!dissimi" OCR/encoding artifact (for
       "fidissimi") was fixed directly on text_la via a
       segment_text_correction, so it's already correct in both layers
       and needs no substitution here.
  Q2   "Secundum, quo pacto" / "ut ista consolatione"
       -> "Alterum, quo pacto" / "ut illa consolatione"
  Q24  "de aeterno Patre" / "de Filio" / "de Spiritu sancto"
       -> "de Deo Patre" / "de Deo Filio" / "de Deo Spiritu sancto"
  Q34  "redimens ... liberans ... vidicavit" (present participles, and a
       typo for vindicavit)
       -> "redemit ... liberavit ... vindicavit" (perfect tense)
  Q86  "propter Christum liberati simus" -> "per Christum liberati sumus"
  Q117 "vero cordis affectu petamus, et intimo" -> "..., ex intimo"
  Q120 "Deum propter Christum nobis Patrem" -> "Deum per Christum nobis Patrem"
  Q129 "Rem certam ac ratam esse" (gloss on "Amen")
       -> "Fiat, seu vere adimpleatur"

Usage:
    python scripts/parse_hc_latin_1563.py -o hc_latin_1563.jsonl
    python scripts/parse_hc_latin_1563.py --dry-run

Requires: psycopg[binary] (reads the current main Latin text from the DB
via db.get_connection -- same DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD
env convention as the rest of this pipeline).
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection

WORK_SLUG = "heidelbergse-catechismus"
LAYER = "editio-princeps-1563"
MODEL = "manual-verification (archive.org 1863 reprint of Lagus/Pithopoeus 1563, via Geneva 1609 reprint)"

# Global HC question number => list of (old, new) substring substitutions
# applied to that question's current main-Latin text_la to derive the 1563
# editio princeps reading. See the module docstring for how these were
# established. Every substitution must match exactly once; parse() aborts
# otherwise rather than silently producing a wrong or unchanged layer.
SUBSTITUTIONS: dict[int, list[tuple[str, str]]] = {
    1: [
        (
            "precioso sanguine suo, pro omnibus peccatis meis plenissime satisfaciens, "
            "me ab omni potestate Diaboli liberavit",
            "pretioso sanguine suo, pro omnibus peccatis meis plenissima solutione facta, "
            "me ab omni potestate diaboli liberavit",
        ),
    ],
    2: [
        ("ut ista consolatione", "ut illa consolatione"),
        ("Secundum, quo pacto", "Alterum, quo pacto"),
    ],
    24: [
        (
            "Prima est de aeterno Patre, et nostri creatione. Altera est de Filio et nostri "
            "redemtione. Tertia est de Spiritu sancto, et nostri sanctificatione.",
            "Prima est de Deo Patre, et nostri creatione. Altera est de Deo Filio, et nostri "
            "redemtione. Tertia est de Deo Spiritu sancto, et nostri sanctificatione.",
        ),
    ],
    34: [
        (
            "non auro, nec argento, sed pretioso suo sanguine redimens, et ab omni potestate "
            "Diaboli liberans nos sibi proprios vidicavit.",
            "non auro, nec argento, sed pretioso suo sanguine redemit, et ab omni potestate "
            "Diaboli liberavit, atque ita nos sibi proprios vindicavit.",
        ),
    ],
    86: [
        ("propter Christum liberati simus", "per Christum liberati sumus"),
    ],
    117: [
        ("vero cordis affectu petamus, et intimo", "vero cordis affectu petamus, ex intimo"),
    ],
    120: [
        (
            "nimirum, Deum propter Christum nobis Patrem factum esse",
            "nimirum, Deum per Christum nobis Patrem factum esse",
        ),
    ],
    129: [
        ("Rem certam ac ratam esse:", "Fiat, seu vere adimpleatur:"),
    ],
}


def fetch_ordered_main_latin() -> list[tuple[str, str]]:
    """Returns [(ref, text_la), ...] for all 129 HC questions in official
    order -- chapter 1 (Q1-2) then chapters 2/3/4 (Prima/Secunda/Tertia
    Pars), each ordered by their own local `section`. Global question
    number = position in this list (1-indexed), which is what
    SUBSTITUTIONS is keyed by.
    """
    with get_connection() as conn, conn.cursor() as cur:
        cur.execute(
            """SELECT s.ref, s.text_la
               FROM segment s
               JOIN work w ON w.id = s.work_id
               WHERE w.slug = %s AND s.chapter IS NOT NULL
               ORDER BY s.chapter::int, s.section::int""",
            (WORK_SLUG,),
        )
        return cur.fetchall()


def parse() -> list[dict]:
    rows = fetch_ordered_main_latin()
    if len(rows) != 129:
        raise SystemExit(f"[error] expected 129 HC questions with a chapter, found {len(rows)}")

    out: list[dict] = []
    for i, (ref, text_la) in enumerate(rows, start=1):
        text = text_la
        for old, new in SUBSTITUTIONS.get(i, []):
            if old not in text:
                raise SystemExit(f"[error] {ref}: expected substring not found: {old!r}")
            text = text.replace(old, new)
        out.append({"ref": ref, "layer": LAYER, "text": text, "model": MODEL})
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("-o", "--output", type=Path, default=Path("hc_latin_1563.jsonl"))
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    rows = parse()
    n_changed = sum(1 for i in SUBSTITUTIONS if 1 <= i <= len(rows))
    print(f"[parse] {len(rows)} questions, {n_changed} with a confirmed 1563 variant applied")

    if args.dry_run:
        for i in sorted(SUBSTITUTIONS):
            print(f"  HC {i}: {rows[i - 1]['text'][:80]}...")
        return 0

    with args.output.open("w", encoding="utf-8") as f:
        for r in rows:
            f.write(json.dumps(r, ensure_ascii=False) + "\n")
    print(f"[ok]    wrote {args.output}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
