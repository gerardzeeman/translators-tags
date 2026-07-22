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
     * translation, for the manual-edit page.
     * @return array{
     *     id: int, ref: string, book: ?int, chapter: ?int, heading: ?string,
     *     text_la: string, text_nl: ?string
     * }|null
     */
    public function getSegmentForEdit(int $segmentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT s.id, s.ref, s.book, s.chapter, s.heading, s.text_la, tr.text_nl
             FROM segment s
             LEFT JOIN translation tr ON tr.segment_id = s.id AND tr.layer = 'llm'
             WHERE s.id = :id",
            ['id' => $segmentId]
        );
        if ($row === false) {
            return null;
        }
        return [
            'id'      => (int) $row['id'],
            'ref'     => $row['ref'],
            'book'    => $row['book'] !== null ? (int) $row['book'] : null,
            'chapter' => $row['chapter'] !== null ? (int) $row['chapter'] : null,
            'heading' => $row['heading'],
            'text_la' => $row['text_la'],
            'text_nl' => $row['text_nl'],
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
     */
    public function saveSegmentTranslation(int $segmentId, string $textNl): void
    {
        $this->connection->executeStatement(
            "INSERT INTO translation (segment_id, layer, text_nl, model)
             VALUES (:segment_id, 'llm', :text_nl, 'manual')
             ON CONFLICT (segment_id, layer) DO UPDATE
                 SET text_nl = EXCLUDED.text_nl, model = 'manual'",
            ['segment_id' => $segmentId, 'text_nl' => $textNl]
        );

        $this->connection->executeStatement(
            "DELETE FROM sentence_alignment WHERE translation_id = (
                 SELECT id FROM translation WHERE segment_id = :segment_id AND layer = 'llm'
             )",
            ['segment_id' => $segmentId]
        );

        $this->connection->executeStatement(
            "UPDATE segment SET status = 'translated' WHERE id = :segment_id",
            ['segment_id' => $segmentId]
        );
    }
}
