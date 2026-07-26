-- Sentence-level alignment between the Latin source and its Dutch LLM
-- translation, used to display /institutio sentence-by-sentence rather than
-- as two whole blocks. Keyed off translation_id (mirrors the existing
-- word-level `alignment` table's convention).
--
-- Naive positional pairing (Latin sentence N <-> Dutch sentence N) breaks as
-- soon as the translator splits one long Calvin period into two Dutch
-- sentences (explicitly allowed by the translation prompt) -- confirmed on
-- real data (Inst. 1.1.1 drifts from sentence 3 onward). So rows here store
-- an LLM-produced grouping instead: one row can cover multiple Latin
-- sentences mapped to one or more Dutch sentences.
--
-- la_start is a character offset into segment.text_la marking where this
-- row's Latin span begins (end = next row's la_start, or end of text for the
-- last row) -- avoids duplicating Latin sentence-splitting logic between the
-- Python ingest pipeline and the PHP renderer. nl_text is the ready-made
-- Dutch text for the row (already joined/ordered by the ingest script).

CREATE TABLE IF NOT EXISTS sentence_alignment (
    id             BIGSERIAL PRIMARY KEY,
    translation_id INT NOT NULL REFERENCES translation(id) ON DELETE CASCADE,
    row_seq        INT NOT NULL,
    la_start       INT NOT NULL,
    nl_text        TEXT NOT NULL,
    UNIQUE (translation_id, row_seq)
);
