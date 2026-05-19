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
                     word_text, lemma, strongs, parse_code)
                VALUES
                    (%(book_id)s, %(chapter)s, %(verse)s, %(word_position)s,
                     %(word_text)s, %(lemma)s, %(strongs)s, %(parse_code)s)
                ON CONFLICT (book_id, chapter, verse, word_position) DO NOTHING
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
    if not rows:
        return
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
                     char_start, char_end)
                VALUES
                    (%(verse_id)s, %(word_position)s, %(word_text)s,
                     %(word_normalised)s, %(char_start)s, %(char_end)s)
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
                            created_by: str | None = None,
                            notes: str | None = None) -> None:
    with get_connection() as conn:
        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO link_confidence
                    (link_id, method, score, created_by, notes)
                VALUES (%s, %s, %s, %s, %s)
                ON CONFLICT (link_id, method) DO UPDATE
                    SET score = EXCLUDED.score,
                        notes = EXCLUDED.notes
                """,
                (link_id, method, score, created_by, notes),
            )
