-- ─────────────────────────────────────────────────────────────────────────────
-- Bible Compare – PostgreSQL schema
-- Run automatically by Docker on first postgres container start.
-- ─────────────────────────────────────────────────────────────────────────────

SET client_encoding = 'UTF8';

-- ─────────────────────────────────────────────────────────────────────────────
-- Reference tables
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS books (
    id            SMALLINT PRIMARY KEY,         -- 1-39 OT, 40-66 NT
    usfm_code     CHAR(3)   NOT NULL UNIQUE,    -- GEN, EXO … REV
    testament     CHAR(2)   NOT NULL CHECK (testament IN ('OT', 'NT')),
    name_nl       TEXT      NOT NULL,           -- Dutch canonical name
    name_original TEXT,                         -- Hebrew (OT) or Greek (NT)
    chapter_count SMALLINT  NOT NULL
);

CREATE TABLE IF NOT EXISTS translations (
    id        SMALLINT PRIMARY KEY,
    code      VARCHAR(20) NOT NULL UNIQUE,      -- 'SV', 'NBG51', …
    name      TEXT        NOT NULL,             -- 'Statenvertaling'
    language  CHAR(3)     NOT NULL,             -- ISO 639-3: 'nld'
    direction VARCHAR(3)  NOT NULL DEFAULT 'LTR' CHECK (direction IN ('LTR', 'RTL'))
);

-- Versification difference mapping (OT: Hebrew tradition vs Dutch)
CREATE TABLE IF NOT EXISTS versification_map (
    id           SERIAL   PRIMARY KEY,
    tradition    VARCHAR(20) NOT NULL,          -- 'Hebrew', 'Dutch', 'KJV'
    book_id      SMALLINT    NOT NULL REFERENCES books(id),
    chapter_from SMALLINT    NOT NULL,
    verse_from   SMALLINT    NOT NULL,
    chapter_to   SMALLINT    NOT NULL,
    verse_to     SMALLINT    NOT NULL
);

-- ─────────────────────────────────────────────────────────────────────────────
-- Source language word tables
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS hebrew_words (
    id              SERIAL   PRIMARY KEY,
    book_id         SMALLINT NOT NULL REFERENCES books(id),
    chapter         SMALLINT NOT NULL,
    verse           SMALLINT NOT NULL,
    word_position   SMALLINT NOT NULL,          -- 1-based within verse
    word_text       TEXT     NOT NULL,          -- pointed Hebrew Unicode
    transliteration TEXT,
    lemma           TEXT,
    strongs         VARCHAR(10),                -- e.g. H0430a
    morph_code      TEXT,                       -- ETCBC/OpenScriptures code
    is_ketiv        BOOLEAN  NOT NULL DEFAULT FALSE,
    has_qere        BOOLEAN  NOT NULL DEFAULT FALSE,
    UNIQUE (book_id, chapter, verse, word_position)
);

CREATE TABLE IF NOT EXISTS greek_words (
    id            SERIAL   PRIMARY KEY,
    book_id       SMALLINT NOT NULL REFERENCES books(id),
    chapter       SMALLINT NOT NULL,
    verse         SMALLINT NOT NULL,
    word_position SMALLINT NOT NULL,            -- 1-based within verse
    word_text     TEXT     NOT NULL,            -- Unicode polytonic Greek
    lemma         TEXT,
    strongs       VARCHAR(8),                   -- e.g. G2316
    parse_code    TEXT,                         -- Robinson morphology code
    UNIQUE (book_id, chapter, verse, word_position)
);

-- ─────────────────────────────────────────────────────────────────────────────
-- Translation word tables
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS translation_verses (
    id             SERIAL   PRIMARY KEY,
    translation_id SMALLINT NOT NULL REFERENCES translations(id),
    book_id        SMALLINT NOT NULL REFERENCES books(id),
    chapter        SMALLINT NOT NULL,
    verse          SMALLINT NOT NULL,
    verse_text     TEXT     NOT NULL,           -- full raw verse for display
    UNIQUE (translation_id, book_id, chapter, verse)
);

CREATE TABLE IF NOT EXISTS translation_words (
    id             SERIAL   PRIMARY KEY,
    verse_id       INTEGER  NOT NULL REFERENCES translation_verses(id) ON DELETE CASCADE,
    word_position  SMALLINT NOT NULL,           -- 1-based within verse
    word_text      TEXT     NOT NULL,           -- Dutch token (no punct)
    word_normalised TEXT    NOT NULL,           -- lowercase, NFD, no diacritics
    char_start     SMALLINT NOT NULL,           -- byte offset in verse_text
    char_end       SMALLINT NOT NULL,           -- exclusive end offset
    UNIQUE (verse_id, word_position)
);

-- ─────────────────────────────────────────────────────────────────────────────
-- Alignment / linking tables
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS word_links (
    id                  SERIAL  PRIMARY KEY,
    source_language     CHAR(2) NOT NULL CHECK (source_language IN ('HE', 'GR')),
    hebrew_word_id      INTEGER REFERENCES hebrew_words(id) ON DELETE CASCADE,
    greek_word_id       INTEGER REFERENCES greek_words(id)  ON DELETE CASCADE,
    translation_word_id INTEGER NOT NULL REFERENCES translation_words(id) ON DELETE CASCADE,
    CONSTRAINT exactly_one_source CHECK (
        (hebrew_word_id IS NOT NULL AND greek_word_id IS NULL AND source_language = 'HE')
        OR
        (greek_word_id  IS NOT NULL AND hebrew_word_id IS NULL AND source_language = 'GR')
    )
);

CREATE TABLE IF NOT EXISTS link_confidence (
    link_id    INTEGER     NOT NULL REFERENCES word_links(id) ON DELETE CASCADE,
    method     VARCHAR(20) NOT NULL CHECK (method IN ('pivot', 'heuristic', 'manual')),
    score      NUMERIC(4,3) CHECK (score BETWEEN 0 AND 1),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_by TEXT,                            -- NULL = automated
    notes      TEXT,
    PRIMARY KEY (link_id, method)
);

-- ─────────────────────────────────────────────────────────────────────────────
-- Indexes
-- ─────────────────────────────────────────────────────────────────────────────

CREATE INDEX IF NOT EXISTS idx_hw_ref      ON hebrew_words       (book_id, chapter, verse);
CREATE INDEX IF NOT EXISTS idx_hw_strongs  ON hebrew_words       (strongs);
CREATE INDEX IF NOT EXISTS idx_gw_ref      ON greek_words        (book_id, chapter, verse);
CREATE INDEX IF NOT EXISTS idx_gw_strongs  ON greek_words        (strongs);
CREATE INDEX IF NOT EXISTS idx_tv_ref      ON translation_verses (translation_id, book_id, chapter, verse);
CREATE INDEX IF NOT EXISTS idx_tw_verse    ON translation_words  (verse_id);
CREATE INDEX IF NOT EXISTS idx_wl_he       ON word_links         (hebrew_word_id);
CREATE INDEX IF NOT EXISTS idx_wl_gr       ON word_links         (greek_word_id);
CREATE INDEX IF NOT EXISTS idx_wl_tw       ON word_links         (translation_word_id);

-- ─────────────────────────────────────────────────────────────────────────────
-- Seed data: books table (all 66 canonical books)
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO books (id, usfm_code, testament, name_nl, chapter_count) VALUES
-- Old Testament
(1,  'GEN', 'OT', 'Genesis',            50),
(2,  'EXO', 'OT', 'Exodus',             40),
(3,  'LEV', 'OT', 'Leviticus',          27),
(4,  'NUM', 'OT', 'Numeri',             36),
(5,  'DEU', 'OT', 'Deuteronomium',      34),
(6,  'JOS', 'OT', 'Jozua',             24),
(7,  'JDG', 'OT', 'Richteren',          21),
(8,  'RUT', 'OT', 'Ruth',               4),
(9,  '1SA', 'OT', '1 Samuel',           31),
(10, '2SA', 'OT', '2 Samuel',           24),
(11, '1KI', 'OT', '1 Koningen',         22),
(12, '2KI', 'OT', '2 Koningen',         25),
(13, '1CH', 'OT', '1 Kronieken',        29),
(14, '2CH', 'OT', '2 Kronieken',        36),
(15, 'EZR', 'OT', 'Ezra',              10),
(16, 'NEH', 'OT', 'Nehemia',           13),
(17, 'EST', 'OT', 'Esther',            10),
(18, 'JOB', 'OT', 'Job',               42),
(19, 'PSA', 'OT', 'Psalmen',           150),
(20, 'PRO', 'OT', 'Spreuken',           31),
(21, 'ECC', 'OT', 'Prediker',           12),
(22, 'SNG', 'OT', 'Hooglied',            8),
(23, 'ISA', 'OT', 'Jesaja',             66),
(24, 'JER', 'OT', 'Jeremia',            52),
(25, 'LAM', 'OT', 'Klaagliederen',       5),
(26, 'EZK', 'OT', 'Ezechiël',           48),
(27, 'DAN', 'OT', 'Daniël',             12),
(28, 'HOS', 'OT', 'Hosea',             14),
(29, 'JOL', 'OT', 'Joël',               3),
(30, 'AMO', 'OT', 'Amos',               9),
(31, 'OBA', 'OT', 'Obadja',             1),
(32, 'JON', 'OT', 'Jona',               4),
(33, 'MIC', 'OT', 'Micha',              7),
(34, 'NAM', 'OT', 'Nahum',              3),
(35, 'HAB', 'OT', 'Habakuk',            3),
(36, 'ZEP', 'OT', 'Zefanja',            3),
(37, 'HAG', 'OT', 'Haggai',             2),
(38, 'ZEC', 'OT', 'Zacharia',           14),
(39, 'MAL', 'OT', 'Maleachi',           4),
-- New Testament
(40, 'MAT', 'NT', 'Mattheüs',           28),
(41, 'MRK', 'NT', 'Marcus',             16),
(42, 'LUK', 'NT', 'Lukas',             24),
(43, 'JHN', 'NT', 'Johannes',           21),
(44, 'ACT', 'NT', 'Handelingen',        28),
(45, 'ROM', 'NT', 'Romeinen',           16),
(46, '1CO', 'NT', '1 Korinthe',         16),
(47, '2CO', 'NT', '2 Korinthe',         13),
(48, 'GAL', 'NT', 'Galaten',             6),
(49, 'EPH', 'NT', 'Efeziërs',            6),
(50, 'PHP', 'NT', 'Filippenzen',         4),
(51, 'COL', 'NT', 'Kolossenzen',         4),
(52, '1TH', 'NT', '1 Thessalonicenzen',  5),
(53, '2TH', 'NT', '2 Thessalonicenzen',  3),
(54, '1TI', 'NT', '1 Timotheüs',         6),
(55, '2TI', 'NT', '2 Timotheüs',         4),
(56, 'TIT', 'NT', 'Titus',               3),
(57, 'PHM', 'NT', 'Filemon',             1),
(58, 'HEB', 'NT', 'Hebreeën',           13),
(59, 'JAS', 'NT', 'Jakobus',             5),
(60, '1PE', 'NT', '1 Petrus',            5),
(61, '2PE', 'NT', '2 Petrus',            3),
(62, '1JN', 'NT', '1 Johannes',          5),
(63, '2JN', 'NT', '2 Johannes',          1),
(64, '3JN', 'NT', '3 Johannes',          1),
(65, 'JUD', 'NT', 'Judas',               1),
(66, 'REV', 'NT', 'Openbaring',          22)
ON CONFLICT (id) DO NOTHING;

-- Seed data: Statenvertaling translation record
INSERT INTO translations (id, code, name, language, direction)
VALUES (1, 'SV', 'Statenvertaling', 'nld', 'LTR')
ON CONFLICT (id) DO NOTHING;