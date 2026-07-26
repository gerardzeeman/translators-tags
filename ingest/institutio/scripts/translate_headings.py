#!/usr/bin/env python3
"""Translate chapter headings into Dutch via the Anthropic Batch API.

segment.heading (e.g. "Dei notitiam et nostri res esse coniunctas, et quomodo
inter se cohaereant.") was never translated -- translate_segments.py only
ever sends the numbered body sections to the LLM, using heading purely as
context in the prompt (never asked to reproduce it). Each *distinct* heading
is translated once here and applied to every segment row that shares it
(heading is already denormalized per segment row -- one row per section, same
heading repeated -- so heading_nl follows the same convention).

Resumable: only headings where at least one segment still has heading_nl IS
NULL are processed; already-translated headings are skipped.

    export ANTHROPIC_API_KEY=...
    python scripts/translate_headings.py --limit 5   # test on 5 headings
    python scripts/translate_headings.py               # full run

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
Vertaal deze titel uit Calvijns Institutio christianae religionis (1559) \
naar modern, helder Nederlands. Het kan een hoofdstuktitel zijn, of (voor \
het voorwoord) de aanhef van de opdracht aan de koning. Geef alleen de \
vertaling terug, zonder aanhalingstekens en zonder uitleg of toelichting.

Titel: {heading}
"""

MODEL = "claude-sonnet-5"
MAX_TOKENS = 500
POLL_INTERVAL_S = 30
BATCH_SIZE = 100


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
                    help="only process the first N un-translated distinct headings")
    ap.add_argument("--batch-id", default=None,
                    help="skip submission, re-fetch results from an already-completed "
                         "batch (use this to recover from a parsing bug without paying twice)")
    args = ap.parse_args()

    import anthropic
    client = anthropic.Anthropic()

    with get_connection() as conn, conn.cursor() as cur:
        q = """SELECT heading FROM segment
               WHERE heading IS NOT NULL AND heading_nl IS NULL
               GROUP BY heading
               ORDER BY min(seq)"""
        if args.limit:
            q += f" LIMIT {int(args.limit)}"
        cur.execute(q)
        headings = [row[0] for row in cur.fetchall()]
        print(f"[work] {len(headings)} distinct headings to translate")

        for i in range(0, len(headings), BATCH_SIZE):
            chunk = headings[i:i + BATCH_SIZE]
            index = {f"h-{j:04d}": heading for j, heading in enumerate(chunk)}

            if args.batch_id:
                print(f"[reuse] fetching existing batch {args.batch_id}, no new submission")
                batch = wait_for_batch(client, args.batch_id)
            else:
                requests = [
                    {
                        "custom_id": custom_id,
                        "params": {
                            "model": MODEL,
                            "max_tokens": MAX_TOKENS,
                            "thinking": {"type": "disabled"},
                            "messages": [{
                                "role": "user",
                                "content": PROMPT_TEMPLATE.format(heading=heading),
                            }],
                        },
                    }
                    for custom_id, heading in index.items()
                ]
                batch = client.messages.batches.create(requests=requests)
                print(f"[submit] batch {batch.id} ({len(requests)} headings)")
                batch = wait_for_batch(client, batch.id)

            n_ok = 0
            for entry in client.messages.batches.results(batch.id):
                heading = index.get(entry.custom_id)
                if entry.result.type != "succeeded":
                    print(f"[error] {heading!r}: {entry.result.type}")
                    continue
                try:
                    heading_nl = next(
                        b.text for b in entry.result.message.content if b.type == "text"
                    ).strip()
                except StopIteration:
                    print(f"[error] {heading!r}: no text block in response "
                          f"(stop_reason={entry.result.message.stop_reason})")
                    continue
                cur.execute(
                    "UPDATE segment SET heading_nl = %s WHERE heading = %s",
                    (heading_nl, heading))
                n_ok += 1
            conn.commit()
            print(f"[..] {min(i + BATCH_SIZE, len(headings))}/{len(headings)} headings done "
                  f"({n_ok} succeeded this batch)")

    print("[done] heading translation complete")
    return 0


if __name__ == "__main__":
    sys.exit(main())
