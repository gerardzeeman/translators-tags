#!/usr/bin/env python3
"""Word-align each translated segment with SimAlign (phase 4.1).

For every segment with status 'translated', lays the Latin word tokens
(from the token table) next to the Dutch translation (from the translation
table) and uses SimAlign's multilingual-embedding aligner to find the best
matching Dutch span per Latin token — independent of the LLM that produced
the translation (see TECHNISCHE_UITLEG.md, part I.3, on why an LLM cannot
be trusted to self-report its own word alignment).

Target positions are stored as character offsets into the Dutch text
(robust to re-tokenisation), not target token indexes.

Uses the 'itermax' matching method by default: R4 in the project dossier
notes itermax trades a little recall for higher precision, which matters
more here than completeness (missing alignments are safer than fabricated
ones). SimAlign's get_word_aligns() does not expose a numeric confidence
score for any matching method (it returns index pairs only), so
alignment.confidence is always left NULL here; a similarity threshold
would need to be computed separately from the embeddings if that mattered.

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
                    help="only process the first N translated segments")
    args = ap.parse_args()

    from simalign import SentenceAligner
    aligner = SentenceAligner(model="bert", token_type="bpe",
                              matching_methods=METHOD_CODES[args.method])

    with get_connection() as conn, conn.cursor() as cur:
        q = """SELECT s.id, t.id, t.text_nl
               FROM segment s
               JOIN translation t ON t.segment_id = s.id AND t.layer = 'llm'
               WHERE s.status = 'translated' ORDER BY s.seq"""
        if args.limit:
            q += f" LIMIT {int(args.limit)}"
        cur.execute(q)
        todo = cur.fetchall()
        print(f"[work] {len(todo)} segments to align")

        for seg_id, translation_id, text_nl in todo:
            cur.execute(
                """SELECT id, surface FROM token
                   WHERE segment_id = %s AND is_word ORDER BY position""",
                (seg_id,))
            src_rows = cur.fetchall()
            src_tokens = [surface for _, surface in src_rows]
            tgt_tokens = tokenize_nl(text_nl)

            if not src_tokens or not tgt_tokens:
                continue

            alignments = aligner.get_word_aligns(
                src_tokens, [w for w, _, _ in tgt_tokens])
            pairs = alignments[args.method]

            cur.execute("DELETE FROM alignment WHERE translation_id = %s", (translation_id,))
            rows = []
            for src_idx, tgt_idx in pairs:
                token_id = src_rows[src_idx][0]
                word, start, end = tgt_tokens[tgt_idx]
                rows.append((token_id, translation_id, start, end, word, None, "simalign"))
            cur.executemany(
                """INSERT INTO alignment
                       (token_id, translation_id, target_start, target_end,
                        target_text, confidence, source)
                   VALUES (%s, %s, %s, %s, %s, %s, %s)
                   ON CONFLICT (token_id, translation_id, target_start) DO NOTHING""",
                rows)
            cur.execute("UPDATE segment SET status = 'aligned' WHERE id = %s", (seg_id,))
            conn.commit()

    print("[done] alignment phase complete")
    return 0


if __name__ == "__main__":
    sys.exit(main())
