#!/usr/bin/env python3
"""Align Latin sentences with the Dutch LLM translation, sentence by
sentence (phase 3.2), via the Anthropic Batch API.

Naive positional pairing (Latin sentence N <-> Dutch sentence N) breaks as
soon as the translator splits one long Calvin period into two Dutch
sentences -- the translation prompt explicitly allows this, and it's
confirmed to happen in practice (Inst. 1.1.1 drifts from sentence 3 onward
under positional pairing). So instead of trusting position, this script
splits both texts into independently-numbered sentence lists and asks Claude
to return the grouping between them (which Latin sentence(s) correspond to
which Dutch sentence(s)) -- a small, cheap, structural task the model is
good at, versus reproducing any text itself.

Writes one row per group into sentence_alignment: la_start (a character
offset into segment.text_la, so the PHP renderer can slice + re-splice
annotation markers without duplicating this script's sentence-splitting
logic) and nl_text (the already-joined Dutch text for that row).

Processes every translation (layer='llm') that doesn't have alignment rows
yet; resumable via NOT EXISTS.

    export ANTHROPIC_API_KEY=...
    python scripts/align_sentences.py --limit 20   # test on 20 translations
    python scripts/align_sentences.py                # full run

Requires: anthropic (in requirements.txt)
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection

PROMPT_TEMPLATE = """\
Je krijgt twee genummerde zinlijsten uit Calvijns Institutio christianae \
religionis: het Latijnse origineel en de Nederlandse vertaling van dezelfde \
passage. Groepeer ze in volgorde tot corresponderende rijen. Eén rij mag \
meerdere Latijnse zinnen bevatten die samen als één of meer Nederlandse \
zinnen zijn vertaald (de vertaler mag lange Latijnse volzinnen splitsen). \
Elke zin, zowel Latijn als Nederlands, moet in precies één rij voorkomen, \
in oplopende volgorde, zonder gaten of overlap.

Latijn:
{la_numbered}

Nederlands:
{nl_numbered}

Geef ALLEEN JSON terug, geen uitleg: een array van objecten met velden \
"la" (array van Latijnse zin-indices) en "nl" (array van Nederlandse \
zin-indices), in oplopende volgorde, bijvoorbeeld:
[{{"la": [0], "nl": [0]}}, {{"la": [1], "nl": [1, 2]}}, {{"la": [2, 3], "nl": [3]}}]
"""

# Used for windowed alignment (see align_windowed): the Dutch list here is
# deliberately padded with extra buffer sentences beyond what this Latin
# slice needs, so the model must NOT be forced to consume all of it -- unlike
# PROMPT_TEMPLATE (whole-document, both sides fully covered), this variant
# only requires every Latin sentence to be placed; the Dutch side may stop
# short once it runs out of confident correspondence.
WINDOW_PROMPT_TEMPLATE = """\
Je krijgt twee genummerde zinlijsten uit Calvijns Institutio christianae \
religionis: het Latijnse origineel en de Nederlandse vertaling van dezelfde \
passage. De Nederlandse lijst loopt met opzet door tot ruim voorbij wat \
strikt nodig is voor dit stuk Latijn (bufferzinnen aan het eind). Groepeer \
in volgorde tot corresponderende rijen. Eén rij mag meerdere Latijnse \
zinnen bevatten die samen als één of meer Nederlandse zinnen zijn vertaald \
(de vertaler mag lange Latijnse volzinnen splitsen).

Belangrijk: ELKE Latijnse zin moet in precies één rij voorkomen, in \
oplopende volgorde, zonder gaten of overlap. De Nederlandse zinnen die je \
gebruikt moeten ook oplopend zijn vanaf index 0 zonder gaten, maar je hoeft \
NIET alle Nederlandse zinnen te gebruiken -- stop zodra je niet meer zeker \
weet welke Nederlandse zin bij het (nog te behandelen) Latijn hoort, en \
forceer geen overgebleven Nederlandse bufferzinnen in de laatste rij.

Latijn:
{la_numbered}

Nederlands:
{nl_numbered}

Geef ALLEEN JSON terug, geen uitleg: een array van objecten met velden \
"la" (array van Latijnse zin-indices) en "nl" (array van Nederlandse \
zin-indices), in oplopende volgorde, bijvoorbeeld:
[{{"la": [0], "nl": [0]}}, {{"la": [1], "nl": [1, 2]}}, {{"la": [2, 3], "nl": [3]}}]
"""

MODEL = "claude-sonnet-5"
# Normal segments need only a few hundred tokens for the grouping JSON, but
# the ~42k-char front-matter outlier has ~150-200 sentences per side and hit
# stop_reason="max_tokens" at 2000 (confirmed via real usage data). The Batch
# API is async, so a generous ceiling costs nothing extra for normal segments.
MAX_TOKENS = 8000
POLL_INTERVAL_S = 30
BATCH_SIZE = 100

# Asking the model to align a full outlier segment (front matter: ~260 vs
# ~254 sentences) in one shot fails validation almost every time -- always a
# small slip (a duplicated index) somewhere in the middle, confirmed over
# several retries. Smaller windows are far more reliable in practice (every
# normal-sized segment tested so far, <=~20 sentences per side, aligned
# cleanly on the first or second try). Any translation with more than
# WINDOW_THRESHOLD sentences on either side is processed through
# align_windowed() instead of the single-shot path below.
WINDOW_THRESHOLD = 40
WINDOW_SIZE = 25          # Latin sentences requested per window
WINDOW_MARGIN = 10        # extra Dutch sentences offered as buffer (splits can drift the count)
WINDOW_TRIM_GROUPS = 2    # drop this many trailing groups (least reliable, nearest the cut) before advancing
WINDOW_RETRIES = 3        # retries per window before giving up on the whole translation

SENTENCE_BOUNDARY_RE = re.compile(r"(?<=[.!?])\s+")


def split_sentences_with_offsets(text: str) -> list[tuple[int, str]]:
    """Latin: (char_offset_in_text, sentence_text) pairs, offsets preserved
    so the PHP renderer can slice segment.text_la directly."""
    starts = [0] + [m.end() for m in SENTENCE_BOUNDARY_RE.finditer(text)]
    ends = starts[1:] + [len(text)]
    result = []
    for s, e in zip(starts, ends):
        sentence = text[s:e]
        if sentence.strip():
            result.append((s, sentence))
    return result


def split_plain_sentences(text: str) -> list[str]:
    """Dutch: no offsets needed, just the flattened sentence list."""
    flat = re.sub(r"\s+", " ", text).strip()
    if not flat:
        return []
    return [s for s in SENTENCE_BOUNDARY_RE.split(flat) if s]


def extract_json(message) -> list:
    text = next(b.text for b in message.content if b.type == "text").strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[1] if "\n" in text else text
        if text.endswith("```"):
            text = text.rsplit("```", 1)[0]
        text = text.strip()
    return json.loads(text)


def groups_are_well_formed(groups) -> bool:
    """True if `groups` has the exact expected shape: a non-empty list of
    dicts, each with non-empty "la"/"nl" lists of ints. json.loads() only
    guarantees valid JSON, not this shape -- confirmed in practice: one
    response's "la"/"nl" came back as an empty list (didn't break the
    got_la/got_nl coverage check downstream, since an empty group
    contributes nothing to it, but crashed store_alignment's
    la_sentences[g["la"][0]]), and a differently-shaped response crashed the
    (unguarded) g.get("la") call itself. Reject anything off-shape here,
    once, before either downstream path ever sees it.
    """
    if not isinstance(groups, list) or not groups:
        return False
    for g in groups:
        if not isinstance(g, dict):
            return False
        la, nl = g.get("la"), g.get("nl")
        if not isinstance(la, list) or not isinstance(nl, list) or not la or not nl:
            return False
        if not all(isinstance(x, int) for x in la) or not all(isinstance(x, int) for x in nl):
            return False
    return True


def wait_for_batch(client, batch_id: str):
    while True:
        batch = client.messages.batches.retrieve(batch_id)
        print(f"[batch] status={batch.processing_status} "
              f"succeeded={batch.request_counts.succeeded} "
              f"errored={batch.request_counts.errored}")
        if batch.processing_status == "ended":
            return batch
        time.sleep(POLL_INTERVAL_S)


def request_alignment(client, la_slice: list[tuple[int, str]], nl_slice: list[str]) -> list[dict] | None:
    """Submit one la/nl sentence slice for windowed alignment (its own
    single-request batch). The Dutch slice is padded with buffer beyond what
    this Latin slice needs (see align_windowed), so validation only requires
    Latin to be *fully* covered; Dutch just needs to be a clean, gapless
    prefix starting at 0 -- it's fine (expected) if the model stops short of
    using the whole Dutch slice. Retries a few times -- small slices succeed
    quickly in practice. Returns None if it never validates cleanly."""
    la_numbered = "\n".join(f"{j}: {s.strip()}" for j, (_, s) in enumerate(la_slice))
    nl_numbered = "\n".join(f"{j}: {s}" for j, s in enumerate(nl_slice))
    prompt = WINDOW_PROMPT_TEMPLATE.format(la_numbered=la_numbered, nl_numbered=nl_numbered)

    for attempt in range(1, WINDOW_RETRIES + 1):
        batch = client.messages.batches.create(requests=[{
            "custom_id": "w",
            "params": {
                "model": MODEL,
                "max_tokens": MAX_TOKENS,
                "thinking": {"type": "disabled"},
                "messages": [{"role": "user", "content": prompt}],
            },
        }])
        batch = wait_for_batch(client, batch.id)
        entry = next(client.messages.batches.results(batch.id))

        if entry.result.type != "succeeded":
            print(f"[window] attempt {attempt}/{WINDOW_RETRIES}: request {entry.result.type}")
            continue
        try:
            groups = extract_json(entry.result.message)
        except (StopIteration, json.JSONDecodeError) as exc:
            print(f"[window] attempt {attempt}/{WINDOW_RETRIES}: could not parse JSON response: {exc}")
            continue

        if not groups_are_well_formed(groups):
            print(f"[window] attempt {attempt}/{WINDOW_RETRIES}: response isn't the expected shape")
            continue

        got_la = [i for g in groups for i in g["la"]]
        got_nl = [i for g in groups for i in g["nl"]]
        la_ok = got_la == list(range(len(la_slice)))
        nl_ok = got_nl == list(range(len(got_nl)))  # clean gapless prefix from 0 -- need not reach len(nl_slice)
        if la_ok and nl_ok and got_nl:
            return groups
        print(f"[window] attempt {attempt}/{WINDOW_RETRIES}: invalid grouping "
              f"(la {len(got_la)}/{len(la_slice)} clean={la_ok}, nl prefix clean={nl_ok}, nl used={len(got_nl)})")

    return None


def align_windowed(client, la_sentences: list[tuple[int, str]], nl_sentences: list[str]) -> list[dict] | None:
    """Align long sentence lists (more reliable in practice than one huge
    request) by sliding a window across both lists: request alignment for
    the next WINDOW_SIZE Latin sentences plus a generously-sized slice of
    Dutch sentences, keep every group except the last WINDOW_TRIM_GROUPS
    (least reliable, right at the cut point where the model has no visibility
    into what comes next), then advance both pointers to just past the last
    *kept* group and repeat. The final window keeps everything, since there's
    no further context to defer to.
    """
    n_la, n_nl = len(la_sentences), len(nl_sentences)
    la_i = nl_i = 0
    all_groups: list[dict] = []

    while la_i < n_la:
        la_end = min(la_i + WINDOW_SIZE, n_la)
        nl_end = min(nl_i + WINDOW_SIZE + WINDOW_MARGIN, n_nl)
        is_last = la_end == n_la or nl_end == n_nl  # either side exhausted -- nothing left to defer to

        print(f"[window] la[{la_i}:{la_end}]/{n_la}  nl[{nl_i}:{nl_end}]/{n_nl}"
              + (" (final)" if is_last else ""))
        groups = request_alignment(client, la_sentences[la_i:la_end], nl_sentences[nl_i:nl_end])
        if groups is None:
            print(f"[window] giving up: la[{la_i}:{la_end}] never aligned cleanly after retries")
            return None

        if is_last:
            confirmed = groups
        elif len(groups) > WINDOW_TRIM_GROUPS:
            confirmed = groups[:-WINDOW_TRIM_GROUPS]
        else:
            print(f"[window] giving up: window only produced {len(groups)} groups, "
                  f"too few to safely trim {WINDOW_TRIM_GROUPS}")
            return None

        for g in confirmed:
            all_groups.append({
                "la": [la_i + x for x in g["la"]],
                "nl": [nl_i + x for x in g["nl"]],
            })

        la_i += confirmed[-1]["la"][-1] + 1
        nl_i += confirmed[-1]["nl"][-1] + 1

    got_la = [i for g in all_groups for i in g["la"]]
    got_nl = [i for g in all_groups for i in g["nl"]]
    if got_la != list(range(n_la)) or got_nl != list(range(n_nl)):
        print(f"[window] stitched result doesn't cleanly cover the whole translation "
              f"(la {len(got_la)}/{n_la}, nl {len(got_nl)}/{n_nl})")
        return None
    return all_groups


def store_alignment(cur, translation_id: int, groups: list[dict],
                     la_sentences: list[tuple[int, str]], nl_sentences: list[str]) -> None:
    for row_seq, g in enumerate(groups):
        la_start = la_sentences[g["la"][0]][0]
        nl_text = " ".join(nl_sentences[j] for j in g["nl"])
        cur.execute(
            """INSERT INTO sentence_alignment (translation_id, row_seq, la_start, nl_text)
               VALUES (%s, %s, %s, %s)
               ON CONFLICT (translation_id, row_seq) DO UPDATE
                   SET la_start = EXCLUDED.la_start, nl_text = EXCLUDED.nl_text""",
            (translation_id, row_seq, la_start, nl_text))


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--limit", type=int, default=None,
                    help="only process the first N un-aligned translations")
    ap.add_argument("--batch-id", default=None,
                    help="skip submission, re-fetch results from an already-completed "
                         "batch (use this to recover from a parsing bug without paying twice; "
                         "only applies to the normal-sized batched path, not windowed outliers)")
    args = ap.parse_args()

    import anthropic
    client = anthropic.Anthropic()

    with get_connection() as conn, conn.cursor() as cur:
        q = """SELECT tr.id, s.text_la, tr.text_nl
               FROM translation tr
               JOIN segment s ON s.id = tr.segment_id
               WHERE tr.layer = 'llm'
                 AND NOT EXISTS (
                     SELECT 1 FROM sentence_alignment sa WHERE sa.translation_id = tr.id
                 )
               ORDER BY s.seq"""
        if args.limit:
            q += f" LIMIT {int(args.limit)}"
        cur.execute(q)
        todo = cur.fetchall()
        print(f"[work] {len(todo)} translations to align")

        # Split sentences up front so outliers (window-based, one at a time,
        # sequential dependent calls) can be routed away from the normal
        # batched path (many translations per Anthropic batch call).
        normal, large = [], []
        for translation_id, text_la, text_nl in todo:
            la_sentences = split_sentences_with_offsets(text_la)
            nl_sentences = split_plain_sentences(text_nl)
            if not la_sentences or not nl_sentences:
                print(f"[skip] translation {translation_id}: no sentences found")
                continue
            row = (translation_id, la_sentences, nl_sentences)
            if len(la_sentences) > WINDOW_THRESHOLD or len(nl_sentences) > WINDOW_THRESHOLD:
                large.append(row)
            else:
                normal.append(row)
        if large:
            print(f"[work] {len(large)} outlier translation(s) routed to windowed alignment "
                  f"(>{WINDOW_THRESHOLD} sentences on either side)")

        for i in range(0, len(normal), BATCH_SIZE):
            chunk = normal[i:i + BATCH_SIZE]

            # translation_id -> (la_sentences, nl_sentences), so results can
            # be matched back up to the exact sentence lists we prompted with
            index: dict[str, tuple[list, list]] = {}
            requests = []
            for translation_id, la_sentences, nl_sentences in chunk:
                custom_id = f"tr-{translation_id}"
                index[custom_id] = (la_sentences, nl_sentences)
                la_numbered = "\n".join(f"{j}: {s.strip()}" for j, (_, s) in enumerate(la_sentences))
                nl_numbered = "\n".join(f"{j}: {s}" for j, s in enumerate(nl_sentences))
                requests.append({
                    "custom_id": custom_id,
                    "params": {
                        "model": MODEL,
                        "max_tokens": MAX_TOKENS,
                        "thinking": {"type": "disabled"},
                        "messages": [{
                            "role": "user",
                            "content": PROMPT_TEMPLATE.format(
                                la_numbered=la_numbered, nl_numbered=nl_numbered),
                        }],
                    },
                })

            if not requests:
                continue

            if args.batch_id:
                print(f"[reuse] fetching existing batch {args.batch_id}, no new submission")
                batch = wait_for_batch(client, args.batch_id)
            else:
                batch = client.messages.batches.create(requests=requests)
                print(f"[submit] batch {batch.id} ({len(requests)} translations)")
                batch = wait_for_batch(client, batch.id)

            for entry in client.messages.batches.results(batch.id):
                translation_id = int(entry.custom_id.split("-", 1)[1])
                if entry.result.type != "succeeded":
                    print(f"[error] translation {translation_id}: {entry.result.type}")
                    continue
                la_sentences, nl_sentences = index[entry.custom_id]
                try:
                    groups = extract_json(entry.result.message)
                except (StopIteration, json.JSONDecodeError) as exc:
                    print(f"[error] translation {translation_id}: could not parse JSON response: {exc}")
                    continue

                if not groups_are_well_formed(groups):
                    print(f"[error] translation {translation_id}: response isn't the expected "
                          f"shape, skipping")
                    continue

                # Sanity check: the grouping must cover every sentence on both
                # sides exactly once, in ascending order -- we control both
                # input lists exactly, so this is fully verifiable.
                got_la = [idx for g in groups for idx in g["la"]]
                got_nl = [idx for g in groups for idx in g["nl"]]
                if got_la != list(range(len(la_sentences))) or got_nl != list(range(len(nl_sentences))):
                    print(f"[error] translation {translation_id}: alignment doesn't cleanly "
                          f"cover all sentences (la={got_la}, nl={got_nl}), skipping")
                    continue

                store_alignment(cur, translation_id, groups, la_sentences, nl_sentences)
            conn.commit()
            print(f"[..] {min(i + BATCH_SIZE, len(normal))}/{len(normal)} normal translations done")

        for translation_id, la_sentences, nl_sentences in large:
            print(f"[large] translation {translation_id}: {len(la_sentences)} la / "
                  f"{len(nl_sentences)} nl sentences -- aligning in windows")
            groups = align_windowed(client, la_sentences, nl_sentences)
            if groups is None:
                print(f"[error] translation {translation_id}: windowed alignment failed, skipping "
                      f"(falls back to whole-block display)")
                continue
            store_alignment(cur, translation_id, groups, la_sentences, nl_sentences)
            conn.commit()
            print(f"[large] translation {translation_id}: {len(groups)} rows stored")

    print("[done] sentence alignment phase complete")
    return 0


if __name__ == "__main__":
    sys.exit(main())
