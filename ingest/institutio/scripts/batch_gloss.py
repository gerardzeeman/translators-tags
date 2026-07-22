#!/usr/bin/env python3
"""Build the Dutch lemma glossary via the Anthropic Batch API (phase 2.2).

One prompt per unique lemma, sent as a single message batch (cheaper and
async compared to realtime calls). Poll until the batch finishes, then
write one JSON line per lemma result for load_glosses.py to load.

COSTS MONEY: at ~11,800 unique lemmas (actual corpus_stats count) this is
an estimated €3-7 total (Sonnet batch pricing). Reads
/data/institutio/lemma_stats.csv (see export_lemmas.py); review the top-100
theological terms manually before running this against the full corpus.

    export ANTHROPIC_API_KEY=...
    python scripts/batch_gloss.py --limit 100          # test on the top 100 lemmas
    python scripts/batch_gloss.py                        # full run

Requires: anthropic (in requirements.txt)
"""
from __future__ import annotations

import argparse
import csv
import json
import sys
import time
from pathlib import Path

PROMPT_TEMPLATE = """\
Geef voor het Latijnse lemma "{lemma}" (frequentie: {freq}x in Calvijns \
Institutio 1559):
1. De hoofdbetekenis in het Nederlands (1-3 woorden).
2. 1-2 alternatieve betekenissen indien van toepassing.
3. Een korte noot als het een technisch theologisch of filosofisch begrip is.

Antwoord uitsluitend als JSON:
{{"gloss_nl": "...", "gloss_alt": ["...", "..."], "note": "..."}}
"""

MODEL = "claude-sonnet-5"
MAX_TOKENS = 500
POLL_INTERVAL_S = 30


def load_lemmas(csv_path: Path, limit: int | None) -> list[dict]:
    with csv_path.open(encoding="utf-8") as f:
        rows = list(csv.DictReader(f))
    if limit:
        rows = rows[:limit]
    return rows


def submit_batch(client, lemmas: list[dict]):
    requests = [
        {
            "custom_id": f"lemma-{i:06d}",
            "params": {
                "model": MODEL,
                "max_tokens": MAX_TOKENS,
                # A one-word lookup doesn't need multi-step reasoning; disabling
                # thinking also avoids it silently eating the whole max_tokens
                # budget before any text is produced (the bug that caused the
                # empty/truncated responses in the first test run).
                "thinking": {"type": "disabled"},
                "messages": [{
                    "role": "user",
                    "content": PROMPT_TEMPLATE.format(lemma=row["lemma"], freq=row["freq"]),
                }],
            },
        }
        for i, row in enumerate(lemmas)
    ]
    return client.messages.batches.create(requests=requests)


def wait_for_batch(client, batch_id: str):
    while True:
        batch = client.messages.batches.retrieve(batch_id)
        print(f"[batch] status={batch.processing_status} "
              f"succeeded={batch.request_counts.succeeded} "
              f"errored={batch.request_counts.errored}")
        if batch.processing_status == "ended":
            return batch
        time.sleep(POLL_INTERVAL_S)


def extract_json(message) -> dict:
    """Pull the first text block out of a batch response and parse it as JSON.

    Sonnet 5 has adaptive thinking on by default, so content[0] is often a
    ThinkingBlock, not the TextBlock -- find the text block explicitly.
    Also strips a ```json ... ``` fence if the model wrapped its answer in one
    despite being asked for JSON only.
    """
    text = next(b.text for b in message.content if b.type == "text")
    text = text.strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[1] if "\n" in text else text
        if text.endswith("```"):
            text = text.rsplit("```", 1)[0]
        text = text.strip()
    return json.loads(text)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--input", type=Path, default=Path("/data/institutio/lemma_stats.csv"))
    ap.add_argument("--output", type=Path, default=Path("/data/institutio/lemma_glosses.jsonl"))
    ap.add_argument("--limit", type=int, default=None,
                    help="only process the top N lemmas (by frequency)")
    ap.add_argument("--batch-id", default=None,
                    help="skip submission, re-fetch results from an already-completed "
                         "batch (use this to recover from a parsing bug without paying twice)")
    args = ap.parse_args()

    import anthropic
    client = anthropic.Anthropic()

    lemmas = load_lemmas(args.input, args.limit)

    # custom_id -> lemma, so results can be matched back up
    index = {f"lemma-{i:06d}": row["lemma"] for i, row in enumerate(lemmas)}

    if args.batch_id:
        print(f"[reuse] fetching existing batch {args.batch_id}, no new submission")
        batch = wait_for_batch(client, args.batch_id)
    else:
        print(f"[submit] {len(lemmas)} lemmas -> Anthropic Batch API")
        batch = submit_batch(client, lemmas)
        print(f"[submit] batch id: {batch.id}")
        batch = wait_for_batch(client, batch.id)

    args.output.parent.mkdir(parents=True, exist_ok=True)
    n_ok, n_err = 0, 0
    with args.output.open("w", encoding="utf-8") as out:
        for entry in client.messages.batches.results(batch.id):
            lemma = index.get(entry.custom_id)
            if entry.result.type != "succeeded":
                n_err += 1
                print(f"[error] {lemma}: {entry.result.type}")
                continue
            try:
                parsed = extract_json(entry.result.message)
            except (StopIteration, json.JSONDecodeError) as exc:
                n_err += 1
                print(f"[error] {lemma}: could not parse JSON response: {exc}")
                continue
            out.write(json.dumps({"lemma": lemma, **parsed}, ensure_ascii=False) + "\n")
            n_ok += 1

    print(f"[done] {n_ok} glosses written to {args.output} ({n_err} errors)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
