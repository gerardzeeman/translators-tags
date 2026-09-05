"""
db/loaders.py
Bulk-insert helpers for all tables.
All inserts use ON CONFLICT DO NOTHING so the pipeline is idempotent.
"""
from db.connection import get_connection


def bulk_insert_hebrew_words(rows: list[dict]) -> None:
    if not rows:
        return
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.executemany(
                """
                INSERT INTO hebrew_words
                    (book_id, chapter, verse, word_position,
                     word_text, transliteration, lemma, strongs,
                     morph_code, is_ketiv, has_qere)
                VALUES
                    (%(book_id)s, %(chapter)s, %(verse)s, %(word_position)s,
                     %(word_text)s, %(transliteration)s, %(lemma)s, %(strongs)s,
                     %(morph_code)s, %(is_ketiv)s, %(has_qere)s)
                ON CONFLICT (book_id, chapter, verse, word_position) DO NOTHING
                """,
                rows,
            )


def bulk_insert_greek_words(rows: list[dict]) -> None:
    if not rows:
        return
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.executemany(
                """
                INSERT INTO greek_words
                    (book_id, chapter, verse, word_position,
                     word_text, lemma, strongs, parse_code, transliteration)
                VALUES
                    (%(book_id)s, %(chapter)s, %(verse)s, %(word_position)s,
                     %(word_text)s, %(lemma)s, %(strongs)s, %(parse_code)s, %(transliteration)s)
                ON CONFLICT (book_id, chapter, verse, word_position)
                DO UPDATE
                SET word_text = EXCLUDED.word_text,
                    transliteration = EXCLUDED.transliteration;
                """,
                rows,
            )


def upsert_translation_verse(translation_id: int, book_id: int,
                              chapter: int, verse: int,
                              verse_text: str) -> int:
    """Insert or update a verse row, return its id."""
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO translation_verses
                    (translation_id, book_id, chapter, verse, verse_text)
                VALUES (%s, %s, %s, %s, %s)
                ON CONFLICT (translation_id, book_id, chapter, verse)
                DO UPDATE SET verse_text = EXCLUDED.verse_text
                RETURNING id
                """,
                (translation_id, book_id, chapter, verse, verse_text),
            )
            return cur.fetchone()[0]


def bulk_insert_translation_words(rows: list[dict]) -> None:
    """Insert translation word rows.

    Each row must contain: verse_id, word_position, word_text,
    word_normalised, char_start, char_end.
    Optional: is_filler (bool, default False).
    """
    if not rows:
        return
    # Ensure is_filler has a default so SV rows (which don't set it) still work
    for row in rows:
        row.setdefault("is_filler", False)

    with get_connection() as conn:
        with conn.cursor() as cur:
            # Delete existing words for this verse first (re-tokenise cleanly)
            verse_ids = {r["verse_id"] for r in rows}
            for vid in verse_ids:
                cur.execute(
                    "DELETE FROM translation_words WHERE verse_id = %s", (vid,)
                )
            cur.executemany(
                """
                INSERT INTO translation_words
                    (verse_id, word_position, word_text, word_normalised,
                     char_start, char_end, is_filler)
                VALUES
                    (%(verse_id)s, %(word_position)s, %(word_text)s,
                     %(word_normalised)s, %(char_start)s, %(char_end)s,
                     %(is_filler)s)
                ON CONFLICT (verse_id, word_position) DO NOTHING
                """,
                rows,
            )


def insert_word_link(source_language: str, source_word_id: int,
                     translation_word_id: int) -> int:
    """Insert a word_links row, return its id."""
    col = "hebrew_word_id" if source_language == "HE" else "greek_word_id"
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                f"""
                INSERT INTO word_links
                    (source_language, {col}, translation_word_id)
                VALUES (%s, %s, %s)
                RETURNING id
                """,
                (source_language, source_word_id, translation_word_id),
            )
            return cur.fetchone()[0]


def insert_link_confidence(link_id: int, method: str, score: float,
                            created_by_user_id: int | None = None,
                            notes: str | None = None) -> None:
    # created_by_user_id is an FK to users(id); automated links (the only
    # kind this ingest pipeline creates) leave it NULL — matches the column
    # name added by a later Doctrine migration on the app side (the schema
    # used to call this `created_by TEXT`; see git history if that's
    # confusing).
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO link_confidence
                    (link_id, method, score, created_at, created_by_user_id, notes)
                VALUES (%s, %s, %s, NOW(), %s, %s)
                ON CONFLICT (link_id, method) DO UPDATE
                    SET score = EXCLUDED.score,
                        notes = EXCLUDED.notes
                """,
                (link_id, method, score, created_by_user_id, notes),
            )


def bulk_insert_cross_references(rows: list[dict]) -> None:
    """Insert word-position-anchored cross-reference rows.

    Each row must contain: source, book_id, chapter, verse, letter,
    word_position, ordinal, target_book_id, target_chapter, target_verse, label.
    """
    if not rows:
        return
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.executemany(
                """
                INSERT INTO cross_references
                    (source, book_id, chapter, verse, letter, word_position, ordinal,
                     target_book_id, target_chapter, target_verse, label)
                VALUES
                    (%(source)s, %(book_id)s, %(chapter)s, %(verse)s, %(letter)s,
                     %(word_position)s, %(ordinal)s,
                     %(target_book_id)s, %(target_chapter)s, %(target_verse)s, %(label)s)
                ON CONFLICT (source, book_id, chapter, verse, letter, ordinal) DO UPDATE
                    SET word_position   = EXCLUDED.word_position,
                        target_book_id  = EXCLUDED.target_book_id,
                        target_chapter  = EXCLUDED.target_chapter,
                        target_verse    = EXCLUDED.target_verse,
                        label           = EXCLUDED.label
                """,
                rows,
            )
