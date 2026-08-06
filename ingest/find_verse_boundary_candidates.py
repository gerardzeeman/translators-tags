"""
find_verse_boundary_candidates.py

Scans the NT for verses whose Dutch translation is likely to carry one or
more words that the Greek (Elzevir) text actually assigns to the *next*
verse — the class of bug documented in verse_boundary_corrections.py.

Why this can't be a pure word-count check
------------------------------------------
The natural thing to try is comparing dutch_word_count / greek_word_count
per verse against the chapter average and flagging outliers. That was tried
first and rejected: Dutch verbosity relative to Greek varies hugely by
content (narrative vs. enumerated lists) on its own, so a 1-3 word shift is
statistically invisible against that noise, while genuinely verbose-but-
correct verses (e.g. a long OT quotation) show up as false positives. See
git history / PR description for the numbers.

What this script does instead
------------------------------
It narrows on the *shape* of the actual bug: a verse ending in a bare
comma-separated list item ("..., word.") immediately followed by a verse
that is short in the Greek. That combination is rare (single digits of
hits across the whole NT per translation) and was confirmed by hand to
contain the known Galatians 5:22 case while excluding ordinary sentences
that merely end in a trailing clause.

This is a CANDIDATE finder, not a verdict. Each hit still needs a human (or
an LLM with real Greek/Dutch vocabulary) to check whether the tail words of
the flagged verse actually gloss the opening Greek words of the next verse.
The script prints both sides plus Strong's KJV (English) glosses for the
next verse's opening words as a quick manual/semantic sanity check, and
flags whether the same pair is also flagged in the other translation
(independent corroboration by SV and HSV — two independently produced
translations — is strong evidence it's a real, longstanding split rather
than a one-off digitisation slip).

Once confirmed, which SIDE gets corrected is a judgement call, not something
this script decides: if independent translations agree with each other but
disagree with the Elzevir tagging (as in Galatians 5:22), the source side is
probably the outlier — see verse_boundary_corrections.py, which corrects
greek_words to match the Dutch tradition rather than the reverse.

Usage:
  python find_verse_boundary_candidates.py                 # both SV + HSV
  python find_verse_boundary_candidates.py --translation SV
  python find_verse_boundary_candidates.py --next-max-words 12
"""
from __future__ import annotations

import argparse
from collections import defaultdict

from db.connection import get_connection

LIST_ENDING_SQL = r"""
    SELECT tv.translation_id, tv.book_id, b.usfm_code, tv.chapter, tv.verse, tv.verse_text
    FROM translation_verses tv
    JOIN books b ON b.id = tv.book_id
    WHERE b.testament = 'NT'
      AND tv.verse_text ~ %(pattern)s
    ORDER BY tv.translation_id, b.usfm_code, tv.chapter, tv.verse
"""

# Ends in ", <one word>." — a bare trailing list item before the full stop.
LIST_ENDING_PATTERN = r',\s*[[:alpha:]]+\.$'


def find_candidates(next_max_words: int, translation_id: int | None) -> list[dict]:
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(LIST_ENDING_SQL, {"pattern": LIST_ENDING_PATTERN})
            cols = [d[0] for d in cur.description]
            rows = [dict(zip(cols, r)) for r in cur.fetchall()]

            cur.execute(
                "SELECT book_id, chapter, verse, COUNT(*) FROM greek_words "
                "GROUP BY book_id, chapter, verse"
            )
            greek_counts = {(b, c, v): n for b, c, v, n in cur.fetchall()}

            candidates = []
            for row in rows:
                if translation_id is not None and row["translation_id"] != translation_id:
                    continue
                key_next = (row["book_id"], row["chapter"], row["verse"] + 1)
                next_count = greek_counts.get(key_next)
                if next_count is None or next_count > next_max_words:
                    continue
                row["next_greek_count"] = next_count
                candidates.append(row)
            return candidates


def fetch_next_verse_context(cur, book_id: int, chapter: int, verse: int, n: int = 4) -> list[dict]:
    cur.execute(
        """
        SELECT gw.word_text, gw.strongs, se.kjv_renderings
        FROM greek_words gw
        LEFT JOIN strongs_entries se ON se.strongs_id = gw.strongs
        WHERE gw.book_id = %s AND gw.chapter = %s AND gw.verse = %s
        ORDER BY gw.word_position
        LIMIT %s
        """,
        (book_id, chapter, verse, n),
    )
    cols = [d[0] for d in cur.description]
    return [dict(zip(cols, r)) for r in cur.fetchall()]


def fetch_translation_codes(cur) -> dict[int, str]:
    cur.execute("SELECT id, code FROM translations")
    return dict(cur.fetchall())


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--translation", default=None,
                        help="Restrict to one translation code (e.g. SV, HSV).")
    parser.add_argument("--next-max-words", type=int, default=10,
                        help="Only flag if the following Greek verse has at most this "
                             "many words (default: 10).")
    args = parser.parse_args()

    translation_id = None
    with get_connection() as conn:
        with conn.cursor() as cur:
            codes = fetch_translation_codes(cur)
            if args.translation:
                by_code = {v.upper(): k for k, v in codes.items()}
                translation_id = by_code.get(args.translation.upper())
                if translation_id is None:
                    parser.error(f"Unknown translation code: {args.translation}")

            candidates = find_candidates(args.next_max_words, translation_id)

            # Group by (book, chapter, verse) to spot cross-translation corroboration
            by_ref: dict[tuple, list[dict]] = defaultdict(list)
            for c in candidates:
                by_ref[(c["usfm_code"], c["chapter"], c["verse"])].append(c)

            if not candidates:
                print("No candidates found.")
                return

            print(f"{len(candidates)} candidate(s) across "
                  f"{len(by_ref)} verse reference(s):\n")

            for (usfm, chapter, verse), rows in sorted(by_ref.items()):
                corroborated = len(rows) > 1
                marker = "★ corroborated by multiple translations" if corroborated else ""
                print(f"── {usfm} {chapter}:{verse} → {chapter}:{verse + 1}  {marker}")
                for r in rows:
                    code = codes.get(r["translation_id"], r["translation_id"])
                    print(f"   [{code}] {r['verse_text']}")

                next_words = fetch_next_verse_context(cur, rows[0]["book_id"], chapter, verse + 1)
                gloss = ", ".join(
                    f"{w['word_text']}"
                    f"{' (' + w['strongs'] + (': ' + w['kjv_renderings'] if w['kjv_renderings'] else '') + ')' if w['strongs'] else ''}"
                    for w in next_words
                )
                print(f"   next verse opens: {gloss}")
                print()

    print(
        "Review each pair above: do the trailing word(s) of the flagged verse\n"
        "plausibly translate the opening Greek word(s) of the next verse shown?\n"
        "If so — and especially if corroborated by both SV and HSV — add an\n"
        "entry (with the Strong's numbers involved) to\n"
        "verse_boundary_corrections.CORRECTIONS."
    )


if __name__ == "__main__":
    main()
