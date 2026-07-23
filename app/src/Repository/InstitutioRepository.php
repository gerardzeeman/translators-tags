<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * InstitutioRepository
 * Raw DBAL queries against the segment/token tables added by
 * db/migrate_add_institutio_schema.sql — a separate corpus (Calvin's
 * Institutio, 1559 Latin) from the Hebrew/Greek Bible tables, so this
 * repository doesn't touch Book/PassageRepository at all.
 */
class InstitutioRepository
{
    private const WORK_SLUG = 'institutio-1559';

    public function __construct(
        private readonly Connection     $connection,
        private readonly CacheInterface $cache,
    ) {}

    private function getWorkId(): ?int
    {
        return $this->cache->get('institutio_work_id', function (ItemInterface $item): ?int {
            $item->expiresAfter(86400 * 365);
            $id = $this->connection->fetchOne(
                'SELECT id FROM work WHERE slug = :slug',
                ['slug' => self::WORK_SLUG]
            );
            return $id === false ? null : (int) $id;
        });
    }

    /**
     * Book -> chapter count, for books 1-4 (front matter is handled separately).
     * @return array<int, int>
     */
    public function getBookChapterCounts(): array
    {
        $workId = $this->getWorkId();
        if ($workId === null) {
            return [];
        }

        return $this->cache->get("institutio_book_chapters_{$workId}", function (ItemInterface $item) use ($workId): array {
            $item->expiresAfter(86400 * 365);
            $rows = $this->connection->fetchAllAssociative(
                'SELECT book, MAX(chapter) AS chapter_count
                 FROM segment
                 WHERE work_id = :work_id AND book IS NOT NULL
                 GROUP BY book
                 ORDER BY book',
                ['work_id' => $workId]
            );

            $result = [];
            foreach ($rows as $row) {
                $result[(int) $row['book']] = (int) $row['chapter_count'];
            }
            return $result;
        });
    }

    public function hasFrontMatter(): bool
    {
        $workId = $this->getWorkId();
        if ($workId === null) {
            return false;
        }
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM segment WHERE work_id = :work_id AND book IS NULL LIMIT 1',
            ['work_id' => $workId]
        );
    }

    /**
     * Full table of contents: front matter (if present) plus every book's
     * chapters with their (Latin, and Dutch once translated) titles, in
     * reading order. Powers the /institutio home page.
     * @return array{
     *     front_matter: ?array{heading: ?string, heading_nl: ?string},
     *     books: array<int, array<int, array{chapter: int, heading: ?string, heading_nl: ?string}>>
     * }
     */
    public function getTableOfContents(): array
    {
        $workId = $this->getWorkId();
        if ($workId === null) {
            return ['front_matter' => null, 'books' => []];
        }

        return $this->cache->get("institutio_toc_{$workId}", function (ItemInterface $item) use ($workId): array {
            $item->expiresAfter(86400 * 365);

            $rows = $this->connection->fetchAllAssociative(
                'SELECT DISTINCT book, chapter, heading, heading_nl
                 FROM segment
                 WHERE work_id = :work_id
                 ORDER BY book NULLS FIRST, chapter NULLS FIRST',
                ['work_id' => $workId]
            );

            $frontMatter = null;
            $books = [];
            foreach ($rows as $r) {
                if ($r['book'] === null) {
                    $frontMatter = ['heading' => $r['heading'], 'heading_nl' => $r['heading_nl']];
                    continue;
                }
                $books[(int) $r['book']][] = [
                    'chapter'    => (int) $r['chapter'],
                    'heading'    => $r['heading'],
                    'heading_nl' => $r['heading_nl'],
                ];
            }
            return ['front_matter' => $frontMatter, 'books' => $books];
        });
    }

    /**
     * One chapter's numbered sections, in order, each with its annotations
     * (textual variants / citations) anchored to a character offset in its text,
     * and its word tokens (lemma + Dutch gloss, for the hover) similarly anchored.
     * @return array{heading: ?string, heading_nl: ?string, sections: array<int, array{
     *     id: int, section: int, text: string, text_nl: ?string,
     *     annotations: array<int, array{char_position: int, glyph: string, kind: string, note: string}>,
     *     tokens: array<int, array{char_start: int, char_end: int, lemma: ?string, gloss: ?string}>,
     *     sentence_alignment: array<int, array{la_start: int, nl_text: string}>
     * }>}
     */
    public function getChapter(int $book, int $chapter): array
    {
        $workId = $this->getWorkId();
        if ($workId === null) {
            return ['heading' => null, 'heading_nl' => null, 'sections' => []];
        }

        return $this->fetchSegmentsWithAnnotations(
            'work_id = :work_id AND book = :book AND chapter = :chapter',
            ['work_id' => $workId, 'book' => $book, 'chapter' => $chapter]
        );
    }

    /**
     * Front matter (the dedicatory letter to Francis I) — book/chapter are NULL.
     * @return array{heading: ?string, heading_nl: ?string, sections: array<int, array{
     *     id: int, section: int, text: string, text_nl: ?string,
     *     annotations: array<int, array{char_position: int, glyph: string, kind: string, note: string}>,
     *     tokens: array<int, array{char_start: int, char_end: int, lemma: ?string, gloss: ?string}>,
     *     sentence_alignment: array<int, array{la_start: int, nl_text: string}>
     * }>}
     */
    public function getFrontMatter(): array
    {
        $workId = $this->getWorkId();
        if ($workId === null) {
            return ['heading' => null, 'heading_nl' => null, 'sections' => []];
        }

        return $this->fetchSegmentsWithAnnotations(
            'work_id = :work_id AND book IS NULL',
            ['work_id' => $workId]
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fetchSegmentsWithAnnotations(string $whereSql, array $params): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT s.id, s.section, s.heading, s.heading_nl, s.text_la, tr.id AS translation_id, tr.text_nl
             FROM segment s
             LEFT JOIN translation tr ON tr.segment_id = s.id AND tr.layer = 'llm'
             WHERE {$whereSql}
             ORDER BY s.section",
            $params
        );

        if (!$rows) {
            return ['heading' => null, 'heading_nl' => null, 'sections' => []];
        }

        $segmentIds = array_map(fn($r) => (int) $r['id'], $rows);
        $annotationRows = $this->connection->fetchAllAssociative(
            'SELECT segment_id, char_position, glyph, kind, note
             FROM segment_annotation
             WHERE segment_id IN (' . implode(',', array_fill(0, count($segmentIds), '?')) . ')
             ORDER BY segment_id, char_position, ord',
            $segmentIds
        );

        $annotationsBySegment = [];
        foreach ($annotationRows as $a) {
            $annotationsBySegment[(int) $a['segment_id']][] = [
                'char_position' => (int) $a['char_position'],
                'glyph'         => $a['glyph'],
                'kind'          => $a['kind'],
                'note'          => $a['note'],
            ];
        }

        $tokenRows = $this->connection->fetchAllAssociative(
            'SELECT t.segment_id, t.char_start, t.char_end, t.lemma, lg.gloss_nl
             FROM token t
             LEFT JOIN lemma_gloss lg ON lg.lemma = t.lemma
             WHERE t.segment_id IN (' . implode(',', array_fill(0, count($segmentIds), '?')) . ')
               AND t.is_word
             ORDER BY t.segment_id, t.char_start',
            $segmentIds
        );

        $tokensBySegment = [];
        foreach ($tokenRows as $t) {
            $tokensBySegment[(int) $t['segment_id']][] = [
                'char_start' => (int) $t['char_start'],
                'char_end'   => (int) $t['char_end'],
                'lemma'      => $t['lemma'],
                'gloss'      => $t['gloss_nl'],
            ];
        }

        $translationIds = array_values(array_filter(
            array_map(fn($r) => $r['translation_id'] !== null ? (int) $r['translation_id'] : null, $rows)
        ));
        $alignmentByTranslation = [];
        if ($translationIds) {
            $alignmentRows = $this->connection->fetchAllAssociative(
                'SELECT translation_id, row_seq, la_start, nl_text
                 FROM sentence_alignment
                 WHERE translation_id IN (' . implode(',', array_fill(0, count($translationIds), '?')) . ')
                 ORDER BY translation_id, row_seq',
                $translationIds
            );
            foreach ($alignmentRows as $a) {
                $alignmentByTranslation[(int) $a['translation_id']][] = [
                    'la_start' => (int) $a['la_start'],
                    'nl_text'  => $a['nl_text'],
                ];
            }
        }

        return [
            'heading'    => $rows[0]['heading'] ?? null,
            'heading_nl' => $rows[0]['heading_nl'] ?? null,
            'sections'   => array_map(
                fn($r) => [
                    'id'                 => (int) $r['id'],
                    'section'            => (int) $r['section'],
                    'text'               => $r['text_la'],
                    'text_nl'            => $r['text_nl'],
                    'annotations'        => $annotationsBySegment[(int) $r['id']] ?? [],
                    'tokens'             => $tokensBySegment[(int) $r['id']] ?? [],
                    'sentence_alignment' => $r['translation_id'] !== null
                        ? ($alignmentByTranslation[(int) $r['translation_id']] ?? [])
                        : [],
                ],
                $rows
            ),
        ];
    }

    /**
     * Ordered list of every (book, chapter) pair, front matter first (book/chapter
     * both null), used to compute prev/next navigation across book boundaries.
     * @return array<int, array{book: ?int, chapter: ?int}>
     */
    public function getChapterSequence(): array
    {
        $workId = $this->getWorkId();
        if ($workId === null) {
            return [];
        }

        return $this->cache->get("institutio_chapter_sequence_{$workId}", function (ItemInterface $item) use ($workId): array {
            $item->expiresAfter(86400 * 365);
            $rows = $this->connection->fetchAllAssociative(
                'SELECT DISTINCT book, chapter
                 FROM segment
                 WHERE work_id = :work_id
                 ORDER BY book NULLS FIRST, chapter NULLS FIRST',
                ['work_id' => $workId]
            );

            return array_map(
                fn($r) => ['book' => $r['book'] !== null ? (int) $r['book'] : null,
                           'chapter' => $r['chapter'] !== null ? (int) $r['chapter'] : null],
                $rows
            );
        });
    }

    /** Total number of unique lemmas, for pagination. */
    public function getLemmaCount(): int
    {
        $workId = $this->getWorkId();
        if ($workId === null) {
            return 0;
        }

        return $this->cache->get("institutio_lemma_count_{$workId}", function (ItemInterface $item) use ($workId): int {
            $item->expiresAfter(86400 * 365);
            return (int) $this->connection->fetchOne(
                'SELECT count(DISTINCT t.lemma)
                 FROM token t
                 JOIN segment s ON s.id = t.segment_id
                 WHERE s.work_id = :work_id AND t.is_word AND t.lemma IS NOT NULL',
                ['work_id' => $workId]
            );
        });
    }

    /**
     * One page of lemmas, most frequent first (ties broken alphabetically
     * for a stable, deterministic page boundary).
     * @return array<int, array{lemma: string, freq: int}>
     */
    public function getLemmaPage(int $limit, int $offset): array
    {
        $workId = $this->getWorkId();
        if ($workId === null) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.lemma, count(*) AS freq
             FROM token t
             JOIN segment s ON s.id = t.segment_id
             WHERE s.work_id = :work_id AND t.is_word AND t.lemma IS NOT NULL
             GROUP BY t.lemma
             ORDER BY freq DESC, t.lemma ASC
             LIMIT :limit OFFSET :offset',
            ['work_id' => $workId, 'limit' => $limit, 'offset' => $offset],
            ['work_id' => ParameterType::INTEGER,
             'limit'   => ParameterType::INTEGER,
             'offset'  => ParameterType::INTEGER]
        );

        return array_map(
            fn($r) => ['lemma' => $r['lemma'], 'freq' => (int) $r['freq']],
            $rows
        );
    }

    /**
     * Distinct word-form variants (norm + morphology) for a set of lemmas,
     * most frequent form first within each lemma.
     * @param array<int, string> $lemmas
     * @return array<string, array<int, array{norm: string, morph: ?string, freq: int}>> keyed by lemma
     */
    public function getLemmaVariants(array $lemmas): array
    {
        $workId = $this->getWorkId();
        if ($workId === null || !$lemmas) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.lemma, t.norm, t.morph, count(*) AS freq
             FROM token t
             JOIN segment s ON s.id = t.segment_id
             WHERE s.work_id = :work_id AND t.is_word AND t.lemma IN (:lemmas)
             GROUP BY t.lemma, t.norm, t.morph
             ORDER BY t.lemma, freq DESC, t.norm ASC',
            ['work_id' => $workId, 'lemmas' => $lemmas],
            ['lemmas' => ArrayParameterType::STRING]
        );

        $result = [];
        foreach ($rows as $r) {
            $result[$r['lemma']][] = [
                'norm'  => $r['norm'],
                'morph' => $r['morph'],
                'freq'  => (int) $r['freq'],
            ];
        }
        return $result;
    }

    /**
     * Herziene Statenvertaling verse text, for the "click a Scripture
     * citation" popup -- reuses the main Bible corpus's translations/
     * translation_verses/books tables (not institutio-specific), so this
     * one query doesn't touch work_id at all.
     */
    public function getHsvVerseText(string $usfmCode, int $chapter, int $verse): ?string
    {
        $text = $this->connection->fetchOne(
            'SELECT tv.verse_text
             FROM translation_verses tv
             JOIN translations t ON t.id = tv.translation_id
             JOIN books b ON b.id = tv.book_id
             WHERE t.code = :code AND b.usfm_code = :usfm
               AND tv.chapter = :chapter AND tv.verse = :verse',
            ['code' => 'HSV', 'usfm' => $usfmCode, 'chapter' => $chapter, 'verse' => $verse]
        );
        return $text === false ? null : $text;
    }

    /**
     * One segment plus its current (LLM or manually-edited) Dutch
     * translation, for the manual-edit page. If the segment already has a
     * sentence-level alignment, `rows` holds one entry per aligned row (its
     * fixed Latin excerpt for reference, plus its editable Dutch text) so the
     * editor can offer per-row editing that leaves the alignment intact
     * (see saveSegmentRowTranslations) instead of the whole-block editor
     * (saveSegmentTranslation) that has to drop it.
     * @return array{
     *     id: int, ref: string, book: ?int, chapter: ?int, heading: ?string,
     *     text_la: string, text_nl: ?string,
     *     rows: array<int, array{id: int, la_text: string, nl_text: string}>
     * }|null
     */
    public function getSegmentForEdit(int $segmentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT s.id, s.ref, s.book, s.chapter, s.heading, s.text_la,
                    tr.id AS translation_id, tr.text_nl
             FROM segment s
             LEFT JOIN translation tr ON tr.segment_id = s.id AND tr.layer = 'llm'
             WHERE s.id = :id",
            ['id' => $segmentId]
        );
        if ($row === false) {
            return null;
        }

        $rows = [];
        if ($row['translation_id'] !== null) {
            $alignmentRows = $this->connection->fetchAllAssociative(
                'SELECT id, la_start, nl_text FROM sentence_alignment
                 WHERE translation_id = :translation_id ORDER BY row_seq',
                ['translation_id' => $row['translation_id']]
            );
            $count = count($alignmentRows);
            if ($count > 0) {
                $textLength = mb_strlen($row['text_la']);
                foreach ($alignmentRows as $i => $a) {
                    $start = $a['la_start'];
                    $end = $i + 1 < $count ? $alignmentRows[$i + 1]['la_start'] : $textLength;
                    $rows[] = [
                        'id'      => (int) $a['id'],
                        'la_text' => trim(mb_substr($row['text_la'], $start, $end - $start)),
                        'nl_text' => $a['nl_text'],
                    ];
                }
            }
        }

        return [
            'id'      => (int) $row['id'],
            'ref'     => $row['ref'],
            'book'    => $row['book'] !== null ? (int) $row['book'] : null,
            'chapter' => $row['chapter'] !== null ? (int) $row['chapter'] : null,
            'heading' => $row['heading'],
            'text_la' => $row['text_la'],
            'text_nl' => $row['text_nl'],
            'rows'    => $rows,
        ];
    }

    /**
     * Manually overwrite a segment's Dutch translation (layer='llm', same
     * row the LLM output lives in -- 'model' becomes 'manual' so it's clear
     * in the data which translations are human-edited). The sentence-by-
     * sentence alignment for this segment is now stale (it was derived from
     * whichever text this edit replaced), so it's dropped -- the page falls
     * back to whole-block Latin/Dutch display until align_sentences.py is
     * re-run for this segment. `segment.status` is set to 'translated' (not
     * 'aligned') so a future translate_segments.py run -- which only picks
     * up segments still at 'tokenized' -- won't overwrite this manual edit.
     *
     * Any word-level alignment (phase 4, SimAlign) is stale for the same
     * reason -- its target_start/target_end offsets point into the text_nl
     * this edit just replaced -- and is dropped too, even though nothing in
     * the current UI renders it yet.
     *
     * @return bool true if word-level alignment rows existed and were
     *   dropped, so the caller can warn the editor to re-run align_segments.py.
     */
    public function saveSegmentTranslation(int $segmentId, string $textNl): bool
    {
        $translationId = $this->connection->fetchOne(
            "SELECT id FROM translation WHERE segment_id = :segment_id AND layer = 'llm'",
            ['segment_id' => $segmentId]
        );

        $this->connection->executeStatement(
            "INSERT INTO translation (segment_id, layer, text_nl, model)
             VALUES (:segment_id, 'llm', :text_nl, 'manual')
             ON CONFLICT (segment_id, layer) DO UPDATE
                 SET text_nl = EXCLUDED.text_nl, model = 'manual'",
            ['segment_id' => $segmentId, 'text_nl' => $textNl]
        );

        $alignmentDropped = false;
        if ($translationId !== false) {
            $translationId = (int) $translationId;

            $this->connection->executeStatement(
                'DELETE FROM sentence_alignment WHERE translation_id = :translation_id',
                ['translation_id' => $translationId]
            );

            $alignmentDropped = $this->connection->executeStatement(
                'DELETE FROM alignment WHERE translation_id = :translation_id',
                ['translation_id' => $translationId]
            ) > 0;
        }

        $this->connection->executeStatement(
            "UPDATE segment SET status = 'translated' WHERE id = :segment_id",
            ['segment_id' => $segmentId]
        );

        return $alignmentDropped;
    }

    /**
     * Per-row counterpart to saveSegmentTranslation: updates only the given
     * sentence_alignment rows' Dutch text, leaving la_start (and therefore
     * the alignment itself) untouched, then rebuilds translation.text_nl by
     * re-joining every row in order so the whole-block text stays in sync
     * for the fallback display and for anything else that reads it directly.
     * segment.status is deliberately left as-is -- unlike
     * saveSegmentTranslation, nothing here needs align_sentences.py to
     * re-run, so there's no status to roll back to.
     *
     * Each row id is scoped to this segment's translation_id in the UPDATE,
     * so a row id for a different segment is silently a no-op rather than
     * cross-segment writes.
     *
     * Any word-level alignment (phase 4, SimAlign) is dropped too: its
     * target_start/target_end offsets point into the pre-edit text_nl and
     * would otherwise silently go stale (see saveSegmentTranslation).
     *
     * @param array<int|string, string> $rowTexts row id => new Dutch text
     * @return bool true if word-level alignment rows existed and were
     *   dropped, so the caller can warn the editor to re-run align_segments.py.
     */
    public function saveSegmentRowTranslations(int $segmentId, array $rowTexts): bool
    {
        $translationId = $this->connection->fetchOne(
            "SELECT id FROM translation WHERE segment_id = :segment_id AND layer = 'llm'",
            ['segment_id' => $segmentId]
        );
        if ($translationId === false) {
            return false;
        }
        $translationId = (int) $translationId;

        foreach ($rowTexts as $rowId => $text) {
            $this->connection->executeStatement(
                'UPDATE sentence_alignment SET nl_text = :text
                 WHERE id = :id AND translation_id = :translation_id',
                ['text' => (string) $text, 'id' => (int) $rowId, 'translation_id' => $translationId]
            );
        }

        $joined = $this->connection->fetchOne(
            "SELECT string_agg(nl_text, ' ' ORDER BY row_seq) FROM sentence_alignment
             WHERE translation_id = :translation_id",
            ['translation_id' => $translationId]
        );

        $this->connection->executeStatement(
            "UPDATE translation SET text_nl = :text_nl, model = 'manual' WHERE id = :id",
            ['text_nl' => $joined !== false ? $joined : '', 'id' => $translationId]
        );

        return $this->connection->executeStatement(
            'DELETE FROM alignment WHERE translation_id = :translation_id',
            ['translation_id' => $translationId]
        ) > 0;
    }

    /**
     * Splits Latin text into sentences, mirroring
     * align_sentences.py's SENTENCE_BOUNDARY_RE = r"(?<=[.!?])\s+" exactly,
     * so a sentence boundary here is always a valid la_start for a
     * sentence_alignment row. preg_split's OFFSET_CAPTURE reports byte
     * offsets even with the /u modifier, so each is converted to a
     * character offset (consistent with how char_start/la_start are
     * stored everywhere else) via mb_strlen on the preceding substring.
     *
     * @return array<int, array{offset: int, text: string}>
     */
    private function splitLatinSentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
        $sentences = [];
        foreach ($parts as [$partText, $byteOffset]) {
            if ($partText === '') {
                continue;
            }
            $sentences[] = [
                'offset' => mb_strlen(substr($text, 0, $byteOffset), 'UTF-8'),
                'text'   => $partText,
            ];
        }
        return $sentences;
    }

    /**
     * Segment + word-level-editable alignment structure for the drag-based
     * alignment editor (InstitutioAlignmentController) -- a separate page
     * from the plain per-row text editor (InstitutioTranslateController).
     *
     * `la_sentences` is the segment's full, fixed Latin sentence list
     * (deterministic, never re-split by the editor). `boundary_offsets` is
     * the subset of those sentences' offsets (excluding the very first,
     * which is the segment start and not a togglable gap) that currently
     * start a sentence_alignment row -- i.e. which inter-sentence gaps are
     * "active" row boundaries versus plain splittable gaps. `rows` holds
     * just the Dutch word lists, in the same order; row count and
     * boundary_offsets count always match by construction.
     *
     * If the segment has no sentence_alignment yet, the whole translation
     * is offered as a single unsplit starting row (no boundaries), which
     * the editor can then split.
     *
     * @return array{
     *   id: int, ref: string,
     *   la_sentences: array<int, array{offset: int, text: string}>,
     *   boundary_offsets: array<int, int>,
     *   rows: array<int, array{words: array<int, string>}>
     * }|null
     */
    public function getSegmentAlignmentForEdit(int $segmentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT s.id, s.ref, s.text_la, tr.id AS translation_id, tr.text_nl
             FROM segment s
             LEFT JOIN translation tr ON tr.segment_id = s.id AND tr.layer = 'llm'
             WHERE s.id = :id",
            ['id' => $segmentId]
        );
        if ($row === false || $row['translation_id'] === null) {
            return null;
        }

        $allSentences = $this->splitLatinSentences($row['text_la']);

        $alignmentRows = $this->connection->fetchAllAssociative(
            'SELECT la_start, nl_text FROM sentence_alignment
             WHERE translation_id = :translation_id ORDER BY row_seq',
            ['translation_id' => $row['translation_id']]
        );
        if (!$alignmentRows) {
            $alignmentRows = [['la_start' => 0, 'nl_text' => $row['text_nl'] ?? '']];
        }

        $laStarts = array_map(static fn($r) => (int) $r['la_start'], $alignmentRows);
        $boundaryOffsets = array_slice($laStarts, 1);

        $rows = array_map(
            static fn($r) => ['words' => preg_split('/\s+/u', trim((string) $r['nl_text']), -1, PREG_SPLIT_NO_EMPTY) ?: []],
            $alignmentRows
        );

        return [
            'id'               => (int) $row['id'],
            'ref'              => $row['ref'],
            'la_sentences'     => $allSentences,
            'boundary_offsets' => $boundaryOffsets,
            'rows'             => $rows,
        ];
    }

    /**
     * Replaces this segment's entire sentence-level alignment with the
     * given rows, as produced by the drag-based alignment editor. Unlike
     * saveSegmentRowTranslations (fixed row count, wording-only edits), row
     * count can change here (merge/split), so existing rows are wholesale
     * replaced rather than patched in place.
     *
     * Each row's la_start is validated against the segment's own actual
     * Latin sentence boundaries (recomputed here from text_la, never
     * trusted from the client) -- every value must be exactly one of those
     * boundary offsets (or 0), strictly increasing with no duplicates, so a
     * row can never straddle a partial sentence or corrupt the ordering.
     *
     * @param array<int, array{la_start: int, words: array<int, string>}> $rows
     * @return bool true if word-level alignment (phase 4) existed and was
     *   dropped as a result, so the caller can warn the editor.
     * @throws \InvalidArgumentException on invalid row data
     * @throws \RuntimeException if the segment has no translation yet
     */
    public function saveSegmentAlignment(int $segmentId, array $rows): bool
    {
        $segment = $this->connection->fetchAssociative(
            "SELECT s.text_la, tr.id AS translation_id
             FROM segment s
             LEFT JOIN translation tr ON tr.segment_id = s.id AND tr.layer = 'llm'
             WHERE s.id = :id",
            ['id' => $segmentId]
        );
        if ($segment === false || $segment['translation_id'] === null) {
            throw new \RuntimeException("Segment {$segmentId} heeft nog geen vertaling om uit te lijnen.");
        }
        $translationId = (int) $segment['translation_id'];

        if (!$rows) {
            throw new \InvalidArgumentException('Ten minste één rij is vereist.');
        }

        $validOffsets = array_column($this->splitLatinSentences($segment['text_la']), 'offset');
        $validOffsets[] = 0;
        $validOffsets = array_flip($validOffsets);

        $starts = array_column($rows, 'la_start');
        foreach ($rows as $r) {
            if (!isset($validOffsets[$r['la_start']])) {
                throw new \InvalidArgumentException("Ongeldige la_start: {$r['la_start']} is geen zinsgrens.");
            }
            if (!$r['words']) {
                throw new \InvalidArgumentException('Elke rij moet minstens één woord bevatten.');
            }
        }
        $sortedStarts = $starts;
        sort($sortedStarts);
        if ($starts !== $sortedStarts || count(array_unique($starts)) !== count($starts)) {
            throw new \InvalidArgumentException('Rijen moeten in oplopende Latijnse volgorde staan, zonder duplicaten.');
        }

        $this->connection->executeStatement(
            'DELETE FROM sentence_alignment WHERE translation_id = :translation_id',
            ['translation_id' => $translationId]
        );
        foreach ($rows as $seq => $r) {
            $this->connection->executeStatement(
                'INSERT INTO sentence_alignment (translation_id, row_seq, la_start, nl_text)
                 VALUES (:translation_id, :row_seq, :la_start, :nl_text)',
                [
                    'translation_id' => $translationId,
                    'row_seq'        => $seq,
                    'la_start'       => $r['la_start'],
                    'nl_text'        => implode(' ', $r['words']),
                ]
            );
        }

        $joined = implode(' ', array_map(static fn($r) => implode(' ', $r['words']), $rows));
        $this->connection->executeStatement(
            "UPDATE translation SET text_nl = :text_nl, model = 'manual' WHERE id = :id",
            ['text_nl' => $joined, 'id' => $translationId]
        );

        return $this->connection->executeStatement(
            'DELETE FROM alignment WHERE translation_id = :translation_id',
            ['translation_id' => $translationId]
        ) > 0;
    }
}
