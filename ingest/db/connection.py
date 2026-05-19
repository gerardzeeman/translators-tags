"""
db/connection.py
Central database connection for the ingest pipeline.
"""
import os
import psycopg
from contextlib import contextmanager


def get_dsn() -> str:
    return (
        f"host={os.environ['DB_HOST']} "
        f"port={os.environ.get('DB_PORT', '5432')} "
        f"dbname={os.environ['DB_NAME']} "
        f"user={os.environ['DB_USER']} "
        f"password={os.environ['DB_PASSWORD']}"
    )


@contextmanager
def get_connection():
    """Yield an auto-committing psycopg connection."""
    conn = psycopg.connect(get_dsn())
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()
