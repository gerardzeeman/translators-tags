#!/usr/bin/env python3
"""Word-align each translated segment with SimAlign (phase 4.1).

For every segment with status 'translated', lays the Latin word tokens
(from the token table) next to the Dutch translation (from the translation
table) and uses SimAlign's multilingual-embedding aligner to find the best
matching Dutch span per Latin token — independent of the LLM that produced
the translation (see TECHNISCHE_UITLEG.md, part I.3, on why an LLM cannot
be trusted to self-report its own word alignment).

Aligns per sentence_alignment ROW rather than per whole segment: each row's
already-established (Latin sentence-group, Dutch text) pairing is a much
smaller, natural chunk to align within, which sidesteps BERT's 512-subword-
token limit -- confirmed on the ~40k-char front-matter outlier, whole-segment
alignment silently truncated after the first 289 of 5,660 word tokens, with
zero coverage beyond that (BERT truncates rather than erroring). The largest
row anywhere in the corpus is 176 words, safely within that limit. Every
segment has at least one sentence_alignment row (a single all-encompassing
row if never explicitly split -- see InstitutioRepository::getSegmentForEdit),
so this works uniformly whether or not phase 3.2 alignment ran, though a
segment stuck at a single giant row would hit the same truncation this
change is meant to avoid; split such rows via the alignment editor first.

Target positions are stored as character offsets into the whole segment's
translation.text_nl (robust to re-tokenisation), not target token indexes,
matching the `alignment` table's existing schema. Computed via a running
offset as rows are processed in order -- text_nl is always the space-joined
concatenation of each row's nl_text (see InstitutioRepository::
saveSegmentRowTranslations / saveSegmentAlignment), so this offset is
derivable without re-fetching text_nl.

Uses the 'itermax' matching method by default: R4 in the project dossier
notes itermax trades a little recall for higher precision, which matters
more here than completeness (missing alignments are safer than fabricated
ones) -- so well under 100% per-row coverage is expected and fine; it's
coverage dropping to *zero* partway through a row that signals a problem.
SimAlign's get_word_aligns() does not expose a numeric confidence score for
any matching method (it returns index pairs only), so alignment.confidence
is always left NULL here; a similarity threshold would need to be computed
separately from the embeddings if that mattered.

    pip install simalign
    python scripts/align_segments.py --limit 20   # test on 20 segments
    python scripts/align_segments.py                # full run
"""
from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from db import get_connection

TOKEN_RE = re.compile(r"\S+")

# SimAlign matching_methods letter codes -> result dict keys.
METHOD_CODES = {"itermax": "i", "mwmf": "m", "argmax": "a"}


def tokenize_nl(text: str) -> list[tuple[str, int, int]]:
    """Rudimentary Dutch tokenisation: split on whitespace, keep char offsets."""
    return [(m.group(0), m.start(), m.end()) for m in TOKEN_RE.finditer(text)]


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--method", default="itermax", choices=list(METHOD_CODES))
    ap.add_argument("--limit", type=int, default=None,
                    help="only process the first N segments")
    ap.add_argument("--all", action="store_true",
                    help="re-align every translated segment regardless of status, not "
                         "just ones still at 'translated' -- use this to re-run after a "
                         "fix to this script itself (e.g. the per-row chunking rework, "
                         "which fixed near-total alignment loss on long segments) without "
                         "rolling status back first.")
    args = ap.parse_args()

    from simalign import SentenceAligner
    aligner = SentenceAligner(model="bert", token_type="bpe",
                              matching_methods=METHOD_CODES[args.method])

    with get_connection() as conn, conn.cursor() as cur:
        q = """SELECT s.id, t.id
               FROM segment s
               JOIN translation t ON t.segment_id = s.id AND t.layer = 'llm'"""
        q += " WHERE s.status = 'translated'" if not args.all else " WHERE s.status IN ('translated', 'aligned')"
        q += " ORDER BY s.seq"
        if args.limit:
            q += f" LIMIT {int(args.limit)}"
        cur.execute(q)
        todo = cur.fetchall()
        print(f"[work] {len(todo)} segments to align")

        for seg_id, translation_id in todo:
            cur.execute(
                """SELECT la_start, nl_text FROM sentence_alignment
                   WHERE translation_id = %s ORDER BY row_seq""",
                (translation_id,))
            sa_rows = cur.fetchall()
            if not sa_rows:
                continue  # shouldn't happen -- every translation has >=1 row

            cur.execute("DELETE FROM alignment WHERE translation_id = %s", (translation_id,))

            nl_offset = 0
            insert_rows = []
            for i, (la_start, row_nl_text) in enumerate(sa_rows):
                la_end = sa_rows[i + 1][0] if i + 1 < len(sa_rows) else 2**31

                cur.execute(
                    """SELECT id, surface FROM token
                       WHERE segment_id = %s AND is_word
                         AND char_start >= %s AND char_start < %s
                       ORDER BY position""",
                    (seg_id, la_start, la_end))
                src_rows = cur.fetchall()
                src_tokens = [surface for _, surface in src_rows]
                tgt_tokens = tokenize_nl(row_nl_text)

                if src_tokens and tgt_tokens:
                    alignments = aligner.get_word_aligns(
                        src_tokens, [w for w, _, _ in tgt_tokens])
                    for src_idx, tgt_idx in alignments[args.method]:
                        token_id = src_rows[src_idx][0]
                        word, start, end = tgt_tokens[tgt_idx]
                        insert_rows.append((
                            token_id, translation_id,
                            nl_offset + start, nl_offset + end, word, None, "simalign"))

                # Next row's words start after this row's text plus the
                # joining space, matching how text_nl is reconstructed
                # (implode(' ', ...)) on the PHP side.
                nl_offset += len(row_nl_text) + 1

            cur.executemany(
                """INSERT INTO alignment
                       (token_id, translation_id, target_start, target_end,
                        target_text, confidence, source)
                   VALUES (%s, %s, %s, %s, %s, %s, %s)
                   ON CONFLICT (token_id, translation_id, target_start) DO NOTHING""",
                insert_rows)
            cur.execute("UPDATE segment SET status = 'aligned' WHERE id = %s", (seg_id,))
            conn.commit()

    print("[done] alignment phase complete")
    return 0


if __name__ == "__main__":
    sys.exit(main())
