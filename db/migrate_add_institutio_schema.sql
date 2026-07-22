-- ─────────────────────────────────────────────────────────────────────────────
-- Migration: Institutio pipeline schema
--
-- Adds a self-contained set of tables for the Institutio christianae
-- religionis (Calvin, 1559) Latin -> Dutch pipeline. This is a separate
-- corpus from the Hebrew/Greek Bible tables already in this schema, so it
-- gets its own generic work/segment/token model rather than reusing
-- hebrew_words/greek_words/translation_words.
--
--   work           — one row per source work (slug 'institutio-1559')
--   segment        — one numbered section (e.g. 'Inst. 1.1.1'), the unit of
--                    work for tokenisation, translation and alignment
--   token          — LatinCy tokens per segment (surface, norm, lemma,
--                    POS, morphology, char offsets)
--   lemma_gloss    — layer A: one-time LLM-built lemma -> Dutch gloss lexicon
--   translation    — layer B: fluent Dutch translation per segment
--                    (layer column allows multiple parallel translations,
--                    e.g. later 'corsmannus1650')
--   alignment      — layer B: token -> translation span, via SimAlign
--
-- Segment status progression: ingested -> tokenized -> translated -> aligned.
-- Each pipeline stage script is resumable: it filters on the previous status.
--
-- Apply:
--   docker cp db\migrate_add_institutio_schema.sql bible_postgres:/tmp/migrate_add_institutio_schema.sql
--   docker exec bible_postgres psql -U bible -d bible_compare -f /tmp/migrate_add_institutio_schema.sql
-- ─────────────────────────────────────────────────────────────────────────────

BEGIN;

CREATE TABLE IF NOT EXISTS work (
    id          SERIAL PRIMARY KEY,
    slug        TEXT UNIQUE NOT NULL,          -- e.g. 'institutio-1559'
    title       TEXT NOT NULL,
    language    TEXT NOT NULL DEFAULT 'la',
    source      TEXT,                          -- provenance/edition (e.g. CCEL ThML)
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- One segment = one numbered section, the unit of work for LLM batches,
-- alignment and display.
CREATE TABLE IF NOT EXISTS segment (
    id          SERIAL PRIMARY KEY,
    work_id     INT NOT NULL REFERENCES work(id) ON DELETE CASCADE,
    book        INT,                           -- NULL for front matter (letter to Francis I etc.)
    chapter     INT,
    section     INT,
    ref         TEXT NOT NULL,                 -- 'Inst. 1.1.1' or 'Inst. front.3'
    seq         INT NOT NULL,                  -- absolute order within the work
    heading     TEXT,                          -- chapter title (context for LLM prompts)
    text_la     TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'ingested'
                CHECK (status IN ('ingested','tokenized','translated','aligned')),
    UNIQUE (work_id, ref),
    UNIQUE (work_id, seq)
);

CREATE INDEX IF NOT EXISTS idx_segment_ref ON segment (work_id, book, chapter, section);

-- Tokens from LatinCy: surface = as in the source, norm = normalised,
-- lemma/upos/morph from the language model.
CREATE TABLE IF NOT EXISTS token (
    id          BIGSERIAL PRIMARY KEY,
    segment_id  INT NOT NULL REFERENCES segment(id) ON DELETE CASCADE,
    position    INT NOT NULL,                  -- 0-based within segment
    surface     TEXT NOT NULL,
    norm        TEXT NOT NULL,
    lemma       TEXT,
    upos        TEXT,                          -- universal POS tag
    morph       TEXT,                          -- morphological features (Case=Nom|...)
    char_start  INT NOT NULL,
    char_end    INT NOT NULL,
    is_word     BOOLEAN NOT NULL DEFAULT TRUE, -- FALSE for punctuation
    UNIQUE (segment_id, position)
);

CREATE INDEX IF NOT EXISTS idx_token_lemma ON token (lemma) WHERE is_word;

-- ── Layer A: lexicon for interlinear glosses ───────────────────────────────────
CREATE TABLE IF NOT EXISTS lemma_gloss (
    id          SERIAL PRIMARY KEY,
    lemma       TEXT UNIQUE NOT NULL,
    gloss_nl    TEXT,                          -- primary meaning
    gloss_alt   TEXT[],                        -- alternative meanings
    note        TEXT,                          -- e.g. theological register
    source      TEXT NOT NULL DEFAULT 'llm'
                CHECK (source IN ('llm','manual')),
    reviewed    BOOLEAN NOT NULL DEFAULT FALSE
);

-- ── Layer B: fluent translation + alignment ────────────────────────────────────
CREATE TABLE IF NOT EXISTS translation (
    id          SERIAL PRIMARY KEY,
    segment_id  INT NOT NULL REFERENCES segment(id) ON DELETE CASCADE,
    layer       TEXT NOT NULL,
    text_nl     TEXT NOT NULL,
    model       TEXT,                          -- e.g. model name + prompt version
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (segment_id, layer)
);

-- One row = one Latin token -> span-in-Dutch-translation link.
-- Character offsets rather than target token indexes: robust to re-tokenisation.
CREATE TABLE IF NOT EXISTS alignment (
    id             BIGSERIAL PRIMARY KEY,
    token_id       BIGINT NOT NULL REFERENCES token(id) ON DELETE CASCADE,
    translation_id INT NOT NULL REFERENCES translation(id) ON DELETE CASCADE,
    target_start   INT NOT NULL,
    target_end     INT NOT NULL,
    target_text    TEXT NOT NULL,
    confidence     REAL,                       -- SimAlign score; NULL for manual
    source         TEXT NOT NULL DEFAULT 'simalign'
                   CHECK (source IN ('simalign','manual')),
    UNIQUE (token_id, translation_id, target_start)
);

CREATE INDEX IF NOT EXISTS idx_alignment_translation ON alignment (translation_id);

-- ── Stats views ─────────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW lemma_stats AS
SELECT lemma,
       count(*)                        AS freq,
       count(DISTINCT segment_id)      AS n_segments
FROM token
WHERE is_word AND lemma IS NOT NULL
GROUP BY lemma
ORDER BY freq DESC;

CREATE OR REPLACE VIEW corpus_stats AS
SELECT w.slug,
       count(DISTINCT s.id)                            AS n_segments,
       count(t.id) FILTER (WHERE t.is_word)             AS n_word_tokens,
       count(DISTINCT t.lemma) FILTER (WHERE t.is_word)  AS n_unique_lemmas
FROM work w
LEFT JOIN segment s ON s.work_id = w.id
LEFT JOIN token   t ON t.segment_id = s.id
GROUP BY w.slug;

COMMIT;
