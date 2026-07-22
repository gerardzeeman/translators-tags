#!/usr/bin/env python3
"""Translate each tokenized segment into fluent Dutch via the Anthropic
Batch API (phase 3.1).

Processes segments with status 'tokenized', writes the result into the
translation table (layer='llm') and advances status to 'translated'.
Resumable: segments that already have a layer='llm' translation are skipped
via ON CONFLICT, and re-running only re-submits segments still at
'tokenized'.

COSTS MONEY: ~428k Latin word tokens (actual corpus_stats count) -> an
estimated ~640k Dutch output tokens, roughly €12-20 total on Sonnet batch
pricing.

    export ANTHROPIC_API_KEY=...
    python scripts/translate_segments.py --limit 20     # test on 20 segments
    python scripts/translate_segments.py                  # full run

Requires: anthropic (in requirements.txt)
"""
from __future__ import annotations

import argparse
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection

PROMPT_TEMPLATE = """\
Je vertaalt een sectie uit Calvijns Institutio christianae religionis (1559) \
naar modern, helder Nederlands. De sectie heeft referentie {ref} en maakt \
deel uit van hoofdstuk: "{heading}" (die titel is alleen ter context -- hij \
wordt elders al apart vertaald en getoond, dus herhaal of vertaal de titel \
zelf niet in je antwoord).

Regels:
- Bewaar theologische terminologie nauwkeurig (gratia = genade, fides = \
geloof, electio = verkiezing, etc.)
- Calvijns lange Latijnse perioden mogen in het Nederlands gesplitst worden \
mits de betekenis volledig behouden blijft.
- Vertaal Schriftcitaten naar de Statenvertaling-equivalent indien herkenbaar.
- Geef alleen de vertaling van de hieronder gegeven Latijnse tekst terug, \
geen titel, geen uitleg of toelichting.

Te vertalen Latijns:
{text_la}
"""

MODEL = "claude-sonnet-5"
# Segments are almost all small (median ~2,080 Latin chars, p99 ~5,760), but
# the front-matter dedicatory letter is one unsplit ~42,000-char outlier
# (~19,000 input tokens) that still hit stop_reason="max_tokens" at 16000
# with thinking disabled (thinking_tokens=0, so all 16000 went to real
# output and it still wasn't enough). The Batch API is async (no live
# connection to time out), so a generous ceiling here costs nothing extra
# for the ~1,277 normal-sized segments unless actually needed.
MAX_TOKENS = 32000
POLL_INTERVAL_S = 30
BATCH_SIZE = 100  # segments per Anthropic batch request


def wait_for_batch(client, batch_id: str):
    while True:
        batch = client.messages.batches.retrieve(batch_id)
        print(f"[batch] status={batch.processing_status} "
              f"succeeded={batch.request_counts.succeeded} "
              f"errored={batch.request_counts.errored}")
        if batch.processing_status == "ended":
            return batch
        time.sleep(POLL_INTERVAL_S)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--limit", type=int, default=None,
                    help="only process the first N tokenized segments")
    ap.add_argument("--segment-ids", default=None,
                    help="comma-separated segment ids to (re-)translate regardless of "
                         "status -- use this to fix specific segments (e.g. ones that "
                         "were silently truncated under old MAX_TOKENS/thinking settings) "
                         "without re-submitting the whole corpus")
    args = ap.parse_args()

    import anthropic
    client = anthropic.Anthropic()

    with get_connection() as conn, conn.cursor() as cur:
        if args.segment_ids:
            ids = [int(x) for x in args.segment_ids.split(",")]
            cur.execute(
                "SELECT id, ref, heading, text_la FROM segment WHERE id = ANY(%s) ORDER BY seq",
                (ids,))
        else:
            q = """SELECT id, ref, heading, text_la FROM segment
                   WHERE status = 'tokenized' ORDER BY seq"""
            if args.limit:
                q += f" LIMIT {int(args.limit)}"
            cur.execute(q)
        todo = cur.fetchall()
        print(f"[work] {len(todo)} segments to translate")

        for i in range(0, len(todo), BATCH_SIZE):
            chunk = todo[i:i + BATCH_SIZE]
            index = {f"seg-{seg_id}": seg_id for seg_id, *_ in chunk}
            requests = [
                {
                    "custom_id": f"seg-{seg_id}",
                    "params": {
                        "model": MODEL,
                        "max_tokens": MAX_TOKENS,
                        # Adaptive thinking is on by default on Sonnet 5. On the
                        # one huge (~42k-char) front-matter segment it consumed
                        # the entire token budget reasoning before producing any
                        # translation text at all (empty response, verified).
                        # Translation doesn't need multi-step reasoning the way
                        # e.g. code does, so disable it to guarantee the budget
                        # goes to the actual output.
                        "thinking": {"type": "disabled"},
                        "messages": [{
                            "role": "user",
                            "content": PROMPT_TEMPLATE.format(
                                ref=ref, heading=heading or "", text_la=text_la),
                        }],
                    },
                }
                for seg_id, ref, heading, text_la in chunk
            ]
            batch = client.messages.batches.create(requests=requests)
            print(f"[submit] batch {batch.id} ({len(requests)} segments)")
            batch = wait_for_batch(client, batch.id)

            for entry in client.messages.batches.results(batch.id):
                seg_id = index.get(entry.custom_id)
                if entry.result.type != "succeeded":
                    print(f"[error] segment {seg_id}: {entry.result.type}")
                    continue
                try:
                    text_nl = next(
                        b.text for b in entry.result.message.content if b.type == "text"
                    ).strip()
                except StopIteration:
                    print(f"[error] segment {seg_id}: no text block in response "
                          f"(stop_reason={entry.result.message.stop_reason})")
                    continue
                cur.execute(
                    """INSERT INTO translation (segment_id, layer, text_nl, model)
                       VALUES (%s, 'llm', %s, %s)
                       ON CONFLICT (segment_id, layer) DO UPDATE
                           SET text_nl = EXCLUDED.text_nl, model = EXCLUDED.model""",
                    (seg_id, text_nl, MODEL))
                cur.execute(
                    "UPDATE segment SET status = 'translated' WHERE id = %s", (seg_id,))
            conn.commit()
            print(f"[..] {min(i + BATCH_SIZE, len(todo))}/{len(todo)} segments done")

    print("[done] translation phase complete")
    return 0


if __name__ == "__main__":
    sys.exit(main())
