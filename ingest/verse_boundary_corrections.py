"""
verse_boundary_corrections.py

Known cases where the Greek (Elzevir) source text's verse tagging splits a
verse at a different word boundary than the Dutch translation tradition.
word_links / align_heuristic only ever match words within the same
(book, chapter, verse) triple, so a shifted boundary silently produces wrong
or missing links for every word on both sides of the seam.

Example: Galatians 5:22 (fruit of the Spirit). Both the SV (Zefania XML) and
HSV (herzienestatenvertaling.nl) sources — independently digitised — carry
πραοτης and εγκρατεια ("zachtmoedigheid"/meekness, "matigheid"/
"zelfbeheersing"/temperance) at the tail of verse 22, while the Elzevir
digitisation tags those same two Greek words as the start of verse 23. Two
independently produced Dutch translations agreeing on the same split is
treated as strong evidence that this is the actual, longstanding Dutch
verse-numbering tradition, not a translation bug — so here the Elzevir
word-tagging is corrected to match the translations, not the other way
round. (An earlier version of this module did the reverse; see git history
if a future case genuinely needs correcting the *translation* side instead
of the source.)

How a correction is applied
----------------------------
For each entry, the leading `words` tokens of Greek verse `verse_from + 1`
are moved onto the tail of Greek verse `verse_from`, staying within the same
book/chapter. greek_words rows are identified and moved by primary key
(`id`), not by their (verse, word_position) triple, so any existing
word_links pointing at those rows automatically keep pointing at the same
(now correctly re-numbered) word — no re-linking needed for links that
already existed.

`check` records the expected Strong's numbers of the moved words, in order.
Strong's numbers are a stable, single-source identifier here (there is only
one Greek text, unlike the Dutch side where wording differs per
translation), so a single list suffices. It is used purely as a safety
guard: if a verse's head/tail doesn't match, the correction is skipped with
a warning rather than blindly applied (source data may have changed, or the
correction may already have been applied — both are detected as no-ops).

This module is applied automatically by ingest/main.py after parse_elzevir
runs, so the fix survives every re-ingest without touching the upstream
source files.

CAVEAT: because parse_elzevir.py's bulk insert upserts on the *original*
(book, chapter, verse, word_position) key, re-running parse_elzevir.py in
isolation *after* a correction has already moved rows away from that key
would re-insert the original words at their old position (and, worse, could
overwrite an unrelated word's text on conflict if the freed slot was reused
by a renumbered neighbour — see the git history / PR description for the
full argument). Always let this module run immediately after parse_elzevir
as part of the normal ingest pipeline; don't run parse_elzevir.py standalone
against a database that already has corrections applied without
immediately re-running this module afterward too.
"""
from __future__ import annotations

from db.connection import get_connection

# ─── Corrections registry ──────────────────────────────────────────────────

CORRECTIONS: list[dict] = [
    {
        "book": "GAL",
        "chapter": 5,
        "verse_from": 22,
        "words": 2,
        # Expected Strong's numbers of the moved words, in order.
        "check": ["G4240", "G1466"],
        "note": (
            "Fruit of the Spirit list: both SV and HSV independently carry "
            "πραοτης, εγκρατεια (zachtmoedigheid, matigheid/zelfbeheersing) "
            "at the tail of verse 22. Elzevir's own tagging puts them at the "
            "start of verse 23 instead — corrected here to match the "
            "(cross-confirmed) Dutch tradition."
        ),
    },
]


# ─── DB helpers ─────────────────────────────────────────────────────────────

def _resolve_book_id(usfm: str) -> int:
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute("SELECT id FROM books WHERE usfm_code = %s", (usfm,))
            row = cur.fetchone()
            if not row:
                raise ValueError(f"Unknown book usfm code: {usfm}")
            return row[0]


def _fetch_words(cur, book_id: int, chapter: int, verse: int) -> list[dict]:
    cur.execute(
        """
        SELECT id, word_position, word_text, strongs
        FROM greek_words
        WHERE book_id = %s AND chapter = %s AND verse = %s
        ORDER BY word_position
        """,
        (book_id, chapter, verse),
    )
    cols = [d[0] for d in cur.description]
    return [dict(zip(cols, row)) for row in cur.fetchall()]


# ─── Core correction logic ─────────────────────────────────────────────────

def _apply_one(cur, correction: dict) -> str:
    """Apply a single correction. Returns a status string."""
    book_id = _resolve_book_id(correction["book"])
    chapter = correction["chapter"]
    verse_from = correction["verse_from"]
    verse_to = verse_from + 1
    n = correction["words"]
    expected = correction["check"]

    ref = f"{correction['book']} {chapter}:{verse_to}->{verse_from}"

    from_words = _fetch_words(cur, book_id, chapter, verse_from)
    to_words = _fetch_words(cur, book_id, chapter, verse_to)
    if not from_words or not to_words:
        return f"  ↷ {ref}: no Greek word data for one of the two verses, skipped"

    # Already applied? (verse_from already ends with the expected words)
    from_tail = [w["strongs"] for w in from_words[-n:]] if len(from_words) >= n else []
    if from_tail == expected:
        return f"  = {ref}: already applied"

    # Ready to apply? (verse_to starts with the expected words)
    to_head = [w["strongs"] for w in to_words[:n]] if len(to_words) >= n else []
    if to_head != expected:
        return (
            f"  ⚠ {ref}: head of verse {verse_to} is {to_head!r}, expected "
            f"{expected!r} — skipped (source data may have changed; needs "
            f"manual review)"
        )

    moved = to_words[:n]
    remaining_to = to_words[n:]
    base_position = len(from_words)

    # Step 1: move the leading words of verse_to onto fresh positions at the
    # tail of verse_from — never collides, those slots didn't exist before.
    for i, word in enumerate(moved):
        cur.execute(
            "UPDATE greek_words SET verse = %s, word_position = %s WHERE id = %s",
            (verse_from, base_position + i + 1, word["id"]),
        )

    # Step 2: close the gap in verse_to, shifting the remaining words down by
    # n. Ascending original-position order guarantees each target slot was
    # already vacated (by step 1, or by the previous iteration here).
    for word in remaining_to:
        cur.execute(
            "UPDATE greek_words SET word_position = %s WHERE id = %s",
            (word["word_position"] - n, word["id"]),
        )

    return f"  ✓ {ref}: moved {n} word(s)"


def apply_corrections(dry_run: bool = False) -> None:
    """Apply every registered correction."""
    if not CORRECTIONS:
        print("  (no verse boundary corrections registered)")
        return

    with get_connection() as conn:
        with conn.cursor() as cur:
            for correction in CORRECTIONS:
                if dry_run:
                    book_id = _resolve_book_id(correction["book"])
                    chapter = correction["chapter"]
                    verse_from = correction["verse_from"]
                    n = correction["words"]
                    to_words = _fetch_words(cur, book_id, chapter, verse_from + 1)
                    to_head = [w["strongs"] for w in to_words[:n]]
                    ref = f"{correction['book']} {chapter}:{verse_from + 1}->{verse_from}"
                    if to_head == correction["check"]:
                        print(f"  → {ref}: would move {n} word(s)")
                    else:
                        print(f"  = {ref}: already applied or not matching (head={to_head!r})")
                    continue
                print(_apply_one(cur, correction))


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(
        description="Apply known verse-boundary corrections to greek_words."
    )
    parser.add_argument("--dry-run", action="store_true",
                        help="Report what would change without writing.")
    args = parser.parse_args()

    apply_corrections(dry_run=args.dry_run)
