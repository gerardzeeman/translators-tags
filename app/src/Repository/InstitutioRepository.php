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
     * Looks up a single segment by its human-readable reference (e.g.
     * "Inst. 1.1.1" or "Inst. front.3"), for the blog Institutio-embed
     * module -- a lighter-weight lookup than getChapter()/getSegmentForEdit(),
     * since the embed only needs one section's text + sentence alignment,
     * not the full editing payload.
     *
     * @return array{book: ?int, chapter: ?int, section: int, text: string, text_nl: ?string,
     *     sentence_alignment: array<int, array{la_start: int, nl_text: string}>}|null
     */
    public function findSegmentByRef(string $ref): ?array
    {
        $ref = trim($ref);

        if (preg_match('/^Inst\.?\s*front\.(\d+)$/i', $ref, $m)) {
            $book = null;
            $chapter = null;
            $section = (int) $m[1];
            $data = $this->getFrontMatter();
        } elseif (preg_match('/^Inst\.?\s*(\d+)\.(\d+)\.(\d+)$/i', $ref, $m)) {
            $book = (int) $m[1];
            $chapter = (int) $m[2];
            $section = (int) $m[3];
            $data = $this->getChapter($book, $chapter);
        } else {
            return null;
        }

        foreach ($data['sections'] as $s) {
            if ($s['section'] === $section) {
                return [
                    'book'               => $book,
                    'chapter'            => $chapter,
                    'section'            => $section,
                    'text'               => $s['text'],
                    'text_nl'            => $s['text_nl'],
                    'sentence_alignment' => $s['sentence_alignment'],
                ];
            }
        }

        return null;
    }

    /**
     * The section immediately following $book/$chapter/$section, rolling over
     * into the next chapter (same book) when the current chapter is exhausted.
     * Returns null once past the end of the book. Used by the blog Institutio-
     * embed to render N consecutive sections.
     *
     * @return array{book: ?int, chapter: ?int, section: int, text: string, text_nl: ?string,
     *     sentence_alignment: array<int, array{la_start: int, nl_text: string}>}|null
     */
    public function findNextSegment(?int $book, ?int $chapter, int $section): ?array
    {
        if ($book === null || $chapter === null) {
            $data = $this->getFrontMatter();
            $sections = array_column($data['sections'], 'section');
            sort($sections);
            foreach ($sections as $s) {
                if ($s > $section) {
                    return $this->findSegmentByRef("Inst. front.{$s}");
                }
            }
            return null;
        }

        $chapterData = $this->getChapter($book, $chapter);
        $sections = array_column($chapterData['sections'], 'section');
        sort($sections);
        foreach ($sections as $s) {
            if ($s > $section) {
                return $this->findSegmentByRef("Inst. {$book}.{$chapter}.{$s}");
            }
        }

        // Chapter exhausted -- try the next chapter in the same book.
        $bookChapterCounts = $this->getBookChapterCounts();
        $chapterCount = $bookChapterCounts[$book] ?? 0;
        if ($chapter < $chapterCount) {
            $nextChapter = $chapter + 1;
            $nextChapterData = $this->getChapter($book, $nextChapter);
            if ($nextChapterData['sections']) {
                $firstSection = min(array_column($nextChapterData['sections'], 'section'));
                return $this->findSegmentByRef("Inst. {$book}.{$nextChapter}.{$firstSection}");
            }
        }

        return null;
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
            // Excludes the heading row (la_start = HEADING_LA_START, -1):
            // heading/heading_nl are already returned separately above and
            // shown once at the top of the page -- including it here too
            // would duplicate it as if it were the section's first body row.
            $alignmentRows = $this->connection->fetchAllAssociative(
                'SELECT translation_id, row_seq, la_start, nl_text
                 FROM sentence_alignment
                 WHERE translation_id IN (' . implode(',', array_fill(0, count($translationIds), '?')) . ')
                   AND la_start >= 0
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
     * The LLM-built gloss for one lemma, for the lemma detail page. Null if
     * this lemma has no gloss yet (batch_gloss.py/load_glosses.py haven't
     * covered every lemma that ever shows up, e.g. any added after the last
     * run).
     *
     * gloss_alt is a Postgres TEXT[]; DBAL has no native array type mapping
     * configured here, and its raw `{a,"b c"}` literal syntax is fiddly to
     * parse correctly by hand (quoting/escaping for multi-word entries), so
     * Postgres itself unwraps it via array_to_string with an ASCII unit
     * separator (0x1F) that will never collide with real gloss text.
     *
     * @return array{gloss_nl: ?string, gloss_alt: array<int, string>, note: ?string, source: string, reviewed: bool}|null
     */
    public function getLemmaGloss(string $lemma): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT gloss_nl, array_to_string(gloss_alt, E'\x1F') AS gloss_alt, note, source, reviewed
             FROM lemma_gloss WHERE lemma = :lemma",
            ['lemma' => $lemma]
        );
        if ($row === false) {
            return null;
        }
        return [
            'gloss_nl'  => $row['gloss_nl'],
            'gloss_alt' => $row['gloss_alt'] !== null && $row['gloss_alt'] !== ''
                ? explode("\x1F", $row['gloss_alt']) : [],
            'note'      => $row['note'],
            'source'    => $row['source'],
            'reviewed'  => (bool) $row['reviewed'],
        ];
    }

    /**
     * Every occurrence of one lemma in the corpus, most recent (by reading
     * order) not implied -- paginated, since a common lemma can run into
     * the thousands (e.g. 'sum' occurs 16,816 times). Each occurrence
     * includes a KWIC-style context window (plain character slicing around
     * the token, not sentence-aware) so the lemma detail page can show the
     * word in situ without linking out for every single one.
     *
     * @return array<int, array{
     *   ref: string, book: ?int, chapter: ?int, section: ?int,
     *   context_before: string, context_word: string, context_after: string,
     *   truncated_before: bool, truncated_after: bool
     * }>
     */
    public function getLemmaOccurrences(string $lemma, int $limit, int $offset): array
    {
        $workId = $this->getWorkId();
        if ($workId === null) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.ref, s.book, s.chapter, s.section, s.text_la, t.char_start, t.char_end
             FROM token t
             JOIN segment s ON s.id = t.segment_id
             WHERE s.work_id = :work_id AND t.is_word AND t.lemma = :lemma
             ORDER BY s.seq, t.position
             LIMIT :limit OFFSET :offset',
            ['work_id' => $workId, 'lemma' => $lemma, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]
        );

        $window = 45;
        return array_map(function ($r) use ($window) {
            $start = (int) $r['char_start'];
            $end = (int) $r['char_end'];
            $beforeStart = max(0, $start - $window);
            return [
                'ref'              => $r['ref'],
                'book'             => $r['book'] !== null ? (int) $r['book'] : null,
                'chapter'          => $r['chapter'] !== null ? (int) $r['chapter'] : null,
                'section'          => $r['section'] !== null ? (int) $r['section'] : null,
                'context_before'   => mb_substr($r['text_la'], $beforeStart, $start - $beforeStart),
                'context_word'     => mb_substr($r['text_la'], $start, $end - $start),
                'context_after'    => mb_substr($r['text_la'], $end, $window),
                'truncated_before' => $beforeStart > 0,
                'truncated_after'  => $end + $window < mb_strlen($r['text_la']),
            ];
        }, $rows);
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
     * A translation_id's own sentence_alignment rows, resolved to
     * {la_text, nl_text} pairs against $textLa -- shared by getSegmentForEdit
     * (LLM layer, editable) and the read-only Weijenberg preview below. Row
     * count and boundaries are always that translation's own, independent of
     * any other layer's row structure.
     *
     * @return array<int, array{id: int, la_text: string, nl_text: string}>
     */
    private function resolveAlignedRows(int $translationId, string $textLa): array
    {
        // Excludes the heading row (la_start = HEADING_LA_START, -1): the
        // translate page shows segment.heading separately at the top, and
        // -1 isn't a real text_la offset (mb_substr would treat it as
        // "from the end", producing garbage la_text for that row anyway).
        $alignmentRows = $this->connection->fetchAllAssociative(
            'SELECT id, la_start, nl_text FROM sentence_alignment
             WHERE translation_id = :translation_id AND la_start >= 0 ORDER BY row_seq',
            ['translation_id' => $translationId]
        );
        $count = count($alignmentRows);
        if ($count === 0) {
            return [];
        }

        $textLength = mb_strlen($textLa);
        $rows = [];
        foreach ($alignmentRows as $i => $a) {
            $start = $a['la_start'];
            $end = $i + 1 < $count ? $alignmentRows[$i + 1]['la_start'] : $textLength;
            $rows[] = [
                'id'      => (int) $a['id'],
                'la_text' => trim(mb_substr($textLa, $start, $end - $start)),
                'nl_text' => $a['nl_text'],
            ];
        }
        return $rows;
    }

    /**
     * One segment plus its current (LLM or manually-edited) Dutch
     * translation, for the manual-edit page. If the segment already has a
     * sentence-level alignment, `rows` holds one entry per aligned row (its
     * fixed Latin excerpt for reference, plus its editable Dutch text) so the
     * editor can offer per-row editing that leaves the alignment intact
     * (see saveSegmentRowTranslations) instead of the whole-block editor
     * (saveSegmentTranslation) that has to drop it.
     *
     * `weijenberg_rows` is null unless the Weijenberg (1865) translation has
     * been manually sentence-aligned for this segment (via the drag editor,
     * InstitutioAlignmentController) -- an unaligned Weijenberg translation
     * is never shown on this page, since without row boundaries there's no
     * way to display it next to the right Latin excerpt. When present, it's
     * read-only here (Weijenberg alignment/wording is only edited via the
     * drag editor), and its row count/boundaries are its own, independent of
     * `rows` (the LLM layer's).
     *
     * @return array{
     *     id: int, ref: string, book: ?int, chapter: ?int, heading: ?string,
     *     text_la: string, text_nl: ?string,
     *     rows: array<int, array{id: int, la_text: string, nl_text: string}>,
     *     weijenberg_rows: array<int, array{id: int, la_text: string, nl_text: string}>|null
     * }|null
     */
    public function getSegmentForEdit(int $segmentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT s.id, s.ref, s.book, s.chapter, s.heading, s.text_la,
                    tr.id AS translation_id, tr.text_nl,
                    wb.id AS weijenberg_translation_id
             FROM segment s
             LEFT JOIN translation tr ON tr.segment_id = s.id AND tr.layer = 'llm'
             LEFT JOIN translation wb ON wb.segment_id = s.id AND wb.layer = 'weijenberg1865'
             WHERE s.id = :id",
            ['id' => $segmentId]
        );
        if ($row === false) {
            return null;
        }

        $rows = $row['translation_id'] !== null
            ? $this->resolveAlignedRows((int) $row['translation_id'], $row['text_la'])
            : [];

        $weijenbergRows = null;
        if ($row['weijenberg_translation_id'] !== null) {
            $wbRows = $this->resolveAlignedRows((int) $row['weijenberg_translation_id'], $row['text_la']);
            if ($wbRows) {
                $weijenbergRows = $wbRows;
            }
        }

        return [
            'id'              => (int) $row['id'],
            'ref'             => $row['ref'],
            'book'            => $row['book'] !== null ? (int) $row['book'] : null,
            'chapter'         => $row['chapter'] !== null ? (int) $row['chapter'] : null,
            'heading'         => $row['heading'],
            'text_la'         => $row['text_la'],
            'text_nl'         => $row['text_nl'],
            'rows'            => $rows,
            'weijenberg_rows' => $weijenbergRows,
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

        // Excludes the heading row (la_start = HEADING_LA_START, -1) --
        // it's shown/edited separately (segment.heading), never folded
        // into text_nl.
        $joined = $this->connection->fetchOne(
            "SELECT string_agg(nl_text, ' ' ORDER BY row_seq) FROM sentence_alignment
             WHERE translation_id = :translation_id AND la_start >= 0",
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
     * Adds a `parts` breakdown to each of $sentences -- the same word/text
     * splicing chapter.html.twig's word-hover popup relies on
     * (InstitutioController::splitTextWithTokensAndAnnotations), minus the
     * annotation handling the alignment editor doesn't render. Kept as its
     * own copy rather than shared with the controller: that method is also
     * annotation-aware and controller-coupled (citationVerseHref), and this
     * call site only ever needs words.
     *
     * @param array<int, array{offset: int, text: string}> $sentences
     * @return array<int, array{offset: int, text: string, parts: array<int, array{type: string, content: string, lemma?: ?string, gloss?: ?string}>}>
     */
    private function attachWordParts(array $sentences, string $textLa, int $textLength, int $segmentId): array
    {
        $tokenRows = $this->connection->fetchAllAssociative(
            'SELECT t.char_start, t.char_end, t.lemma, lg.gloss_nl
             FROM token t
             LEFT JOIN lemma_gloss lg ON lg.lemma = t.lemma
             WHERE t.segment_id = :segment_id AND t.is_word
             ORDER BY t.char_start',
            ['segment_id' => $segmentId]
        );

        $count = count($sentences);
        foreach ($sentences as $i => &$sentence) {
            $start = $sentence['offset'];
            $end = $i + 1 < $count ? $sentences[$i + 1]['offset'] : $textLength;

            $sentenceTokens = [];
            foreach ($tokenRows as $t) {
                $tokenStart = (int) $t['char_start'];
                if ($tokenStart >= $start && $tokenStart < $end) {
                    $sentenceTokens[] = [
                        'char_start' => $tokenStart - $start,
                        'char_end'   => min((int) $t['char_end'], $end) - $start,
                        'lemma'      => $t['lemma'],
                        'gloss'      => $t['gloss_nl'],
                    ];
                }
            }

            $sentence['parts'] = $this->splitTextIntoWordParts($sentence['text'], $sentenceTokens);
        }
        unset($sentence);

        return $sentences;
    }

    /**
     * @param array<int, array{char_start: int, char_end: int, lemma: ?string, gloss: ?string}> $tokens
     *   Must be ordered by char_start ascending.
     * @return array<int, array{type: string, content: string, lemma?: ?string, gloss?: ?string}>
     */
    private function splitTextIntoWordParts(string $text, array $tokens): array
    {
        $parts = [];
        $cursor = 0;
        foreach ($tokens as $tok) {
            $start = $tok['char_start'];
            $end = $tok['char_end'];
            if ($start < $cursor) {
                continue; // overlapping/out-of-order token -- shouldn't happen, skip defensively
            }
            if ($start > $cursor) {
                $parts[] = ['type' => 'text', 'content' => mb_substr($text, $cursor, $start - $cursor)];
            }
            $parts[] = [
                'type'    => 'word',
                'content' => mb_substr($text, $start, $end - $start),
                'lemma'   => $tok['lemma'],
                'gloss'   => $tok['gloss'],
            ];
            $cursor = $end;
        }
        $remaining = mb_substr($text, $cursor);
        if ($remaining !== '') {
            $parts[] = ['type' => 'text', 'content' => $remaining];
        }
        return $parts;
    }

    private const LLM_LAYER = 'llm';
    private const WEIJENBERG_LAYER = 'weijenberg1865';
    private const HEADING_LA_START = -1;

    /**
     * True if $seq is the lowest seq among segments sharing the same
     * (work, book, chapter) grouping -- heading/heading_nl are denormalized
     * onto every segment of a chapter, so aligning the heading only makes
     * sense once per chapter, on its first segment.
     */
    private function isFirstSegmentOfChapter(int $workId, ?int $book, ?int $chapter, int $seq): bool
    {
        $minSeq = $this->connection->fetchOne(
            'SELECT MIN(seq) FROM segment
             WHERE work_id = :work_id AND book IS NOT DISTINCT FROM :book AND chapter IS NOT DISTINCT FROM :chapter',
            ['work_id' => $workId, 'book' => $book, 'chapter' => $chapter]
        );
        return $minSeq !== false && (int) $minSeq === $seq;
    }

    /**
     * Segment + word-level-editable alignment structure for the drag-based
     * alignment editor (InstitutioAlignmentController) -- a separate page
     * from the plain per-row text editor (InstitutioTranslateController).
     *
     * `la_sentences` is the segment's full, fixed Latin sentence list
     * (deterministic, never re-split by the editor); each sentence's `parts`
     * is the same word/lemma/gloss breakdown the public chapter page's
     * word-hover popup uses (see attachWordParts), so the Latin panel can
     * render the identical hover popup per word. `boundary_offsets` is
     * the subset of those sentences' offsets (excluding the very first,
     * which is the segment start and not a togglable gap) that currently
     * start a sentence_alignment row for the LLM translation -- this is
     * the *reference* row structure the editor keeps every translation
     * panel in lockstep with (see saveSegmentAlignment). `rows` holds the
     * LLM translation's own words per row plus each row's already-joined
     * Latin text (for the page's initial preview render, before any JS has
     * run); row count and boundary_offsets count always match by
     * construction.
     *
     * `weijenberg` is null if this segment has no Weijenberg (1865)
     * translation at all. Otherwise: if Weijenberg already has its own
     * sentence_alignment (from a previous save in this same editor), its
     * rows are used as-is, independent of the LLM reference structure --
     * the two only get *locked* together by future gap-toggles, a
     * pre-existing mismatch isn't retroactively reconciled. If Weijenberg
     * has no alignment yet (the normal case right now: it was loaded
     * straight from the scan, never split), it's initialized to the LLM
     * reference row count with every word starting out in row 0 -- a
     * natural "not yet distributed" starting point for the editor's manual
     * word-dragging, not a computed alignment.
     *
     * `heading` is non-null only when this segment actually has a heading
     * AND is the first segment of its chapter (heading/heading_nl are
     * denormalized onto every segment sharing a chapter, so aligning it
     * only makes sense once). When present, it's additionally prepended as
     * row 0 to `rows` and `weijenberg.rows` -- a HEADING_LA_START (-1)
     * sentinel `la_start`, never a real text_la offset, marks it as
     * structurally separate from the body (see saveSegmentAlignment). Its
     * LLM words default to the already-translated `heading_nl`; Weijenberg
     * has no equivalent separate field (the ingested text just has the
     * heading sentence running straight into the body), so its heading row
     * starts empty until manually carved out of row 1's leading words.
     *
     * @return array{
     *   id: int, ref: string,
     *   la_sentences: array<int, array{offset: int, text: string, parts: array}>,
     *   boundary_offsets: array<int, int>,
     *   rows: array<int, array{la_text: string, words: array<int, string>}>,
     *   weijenberg: array{rows: array<int, array{words: array<int, string>}>}|null,
     *   heading: string|null
     * }|null
     */
    public function getSegmentAlignmentForEdit(int $segmentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT s.id, s.ref, s.text_la, s.work_id, s.book, s.chapter, s.seq, s.heading, s.heading_nl,
                    llm.id AS llm_translation_id, llm.text_nl AS llm_text_nl,
                    wb.id  AS weijenberg_translation_id, wb.text_nl AS weijenberg_text_nl
             FROM segment s
             LEFT JOIN translation llm ON llm.segment_id = s.id AND llm.layer = :llm_layer
             LEFT JOIN translation wb  ON wb.segment_id = s.id AND wb.layer = :weijenberg_layer
             WHERE s.id = :id",
            ['id' => $segmentId, 'llm_layer' => self::LLM_LAYER, 'weijenberg_layer' => self::WEIJENBERG_LAYER]
        );
        if ($row === false || $row['llm_translation_id'] === null) {
            return null;
        }

        $hasHeading = trim((string) $row['heading']) !== '' && $this->isFirstSegmentOfChapter(
            (int) $row['work_id'],
            $row['book'] !== null ? (int) $row['book'] : null,
            $row['chapter'] !== null ? (int) $row['chapter'] : null,
            (int) $row['seq']
        );

        $allSentences = $this->splitLatinSentences($row['text_la']);
        $textLength = mb_strlen($row['text_la']);
        $allSentences = $this->attachWordParts($allSentences, $row['text_la'], $textLength, $segmentId);

        $llmAlignmentRows = $this->connection->fetchAllAssociative(
            'SELECT la_start, nl_text FROM sentence_alignment
             WHERE translation_id = :translation_id ORDER BY row_seq',
            ['translation_id' => $row['llm_translation_id']]
        );
        $llmHeadingRow = null;
        $llmBodyRows = [];
        foreach ($llmAlignmentRows as $r) {
            if ((int) $r['la_start'] === self::HEADING_LA_START) {
                $llmHeadingRow = $r;
            } else {
                $llmBodyRows[] = $r;
            }
        }
        if (!$llmBodyRows) {
            $llmBodyRows = [['la_start' => 0, 'nl_text' => $row['llm_text_nl'] ?? '']];
        }

        $laStarts = array_map(static fn($r) => (int) $r['la_start'], $llmBodyRows);
        $boundaryOffsets = array_slice($laStarts, 1);
        $count = count($laStarts);

        $rows = [];
        foreach ($llmBodyRows as $i => $r) {
            $start = $laStarts[$i];
            $end = $i + 1 < $count ? $laStarts[$i + 1] : $textLength;
            $rows[] = [
                'la_text' => trim(mb_substr($row['text_la'], $start, $end - $start)),
                'words'   => preg_split('/\s+/u', trim((string) $r['nl_text']), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ];
        }

        if ($hasHeading) {
            $headingNl = $llmHeadingRow['nl_text'] ?? ($row['heading_nl'] ?? '');
            array_unshift($rows, [
                'la_text' => $row['heading'],
                'words'   => preg_split('/\s+/u', trim((string) $headingNl), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            ]);
        }

        $weijenberg = null;
        if ($row['weijenberg_translation_id'] !== null) {
            $wbAlignmentRows = $this->connection->fetchAllAssociative(
                'SELECT la_start, nl_text FROM sentence_alignment
                 WHERE translation_id = :translation_id ORDER BY row_seq',
                ['translation_id' => $row['weijenberg_translation_id']]
            );
            $wbHeadingRow = null;
            $wbBodyAlignmentRows = [];
            foreach ($wbAlignmentRows as $r) {
                if ((int) $r['la_start'] === self::HEADING_LA_START) {
                    $wbHeadingRow = $r;
                } else {
                    $wbBodyAlignmentRows[] = $r;
                }
            }

            if ($wbBodyAlignmentRows) {
                $wbRows = array_map(
                    static fn($r) => ['words' => preg_split('/\s+/u', trim((string) $r['nl_text']), -1, PREG_SPLIT_NO_EMPTY) ?: []],
                    $wbBodyAlignmentRows
                );
            } else {
                $words = preg_split('/\s+/u', trim((string) $row['weijenberg_text_nl']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $wbRows = array_fill(0, $count, ['words' => []]);
                $wbRows[0]['words'] = $words;
            }

            if ($hasHeading) {
                $headingWords = $wbHeadingRow !== null
                    ? (preg_split('/\s+/u', trim((string) $wbHeadingRow['nl_text']), -1, PREG_SPLIT_NO_EMPTY) ?: [])
                    : [];
                array_unshift($wbRows, ['words' => $headingWords]);
            }

            $weijenberg = ['rows' => $wbRows];
        }

        return [
            'id'               => (int) $row['id'],
            'ref'              => $row['ref'],
            'la_sentences'     => $allSentences,
            'boundary_offsets' => $boundaryOffsets,
            'rows'             => $rows,
            'weijenberg'       => $weijenberg,
            'heading'          => $hasHeading ? $row['heading'] : null,
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
     * HEADING_LA_START (-1) is additionally accepted as row 0's la_start,
     * but only when this segment actually qualifies for heading alignment
     * (see getSegmentAlignmentForEdit) -- otherwise it's rejected exactly
     * like any other bogus offset.
     * Rows may have zero words -- alignment is often done incrementally
     * (e.g. via the click-to-assign shortcut), and a mid-progress save with
     * still-empty trailing rows must not be blocked.
     *
     * The heading row (if present) is deliberately excluded from the
     * text_nl reconstruction below -- it's a structurally separate field
     * (segment.heading / segment.heading_nl), not part of the body, and
     * folding it in would duplicate it (LLM already has it in heading_nl)
     * or wrongly re-merge it into the body once Weijenberg's copy has been
     * carved out. For the LLM layer specifically, a saved heading row is
     * also written back to segment.heading_nl, since that column is the
     * one read elsewhere (e.g. the chapter page); Weijenberg has no such
     * column, so its heading translation only ever lives in this table.
     *
     * $layer selects which translation ('llm' or 'weijenberg1865') this
     * call applies to; each layer has its own translation_id and its own
     * independent sentence_alignment/alignment rows, saved separately.
     *
     * @param array<int, array{la_start: int, words: array<int, string>}> $rows
     * @return bool true if word-level alignment (phase 4) existed and was
     *   dropped as a result, so the caller can warn the editor.
     * @throws \InvalidArgumentException on invalid row data
     * @throws \RuntimeException if the segment has no translation for this layer yet
     */
    public function saveSegmentAlignment(int $segmentId, string $layer, array $rows): bool
    {
        $segment = $this->connection->fetchAssociative(
            "SELECT s.text_la, s.work_id, s.book, s.chapter, s.seq, s.heading, tr.id AS translation_id
             FROM segment s
             LEFT JOIN translation tr ON tr.segment_id = s.id AND tr.layer = :layer
             WHERE s.id = :id",
            ['id' => $segmentId, 'layer' => $layer]
        );
        if ($segment === false || $segment['translation_id'] === null) {
            throw new \RuntimeException("Segment {$segmentId} heeft nog geen '{$layer}'-vertaling om uit te lijnen.");
        }
        $translationId = (int) $segment['translation_id'];

        if (!$rows) {
            throw new \InvalidArgumentException('Ten minste één rij is vereist.');
        }

        $hasHeading = trim((string) $segment['heading']) !== '' && $this->isFirstSegmentOfChapter(
            (int) $segment['work_id'],
            $segment['book'] !== null ? (int) $segment['book'] : null,
            $segment['chapter'] !== null ? (int) $segment['chapter'] : null,
            (int) $segment['seq']
        );

        $validOffsets = array_column($this->splitLatinSentences($segment['text_la']), 'offset');
        $validOffsets[] = 0;
        if ($hasHeading) {
            $validOffsets[] = self::HEADING_LA_START;
        }
        $validOffsets = array_flip($validOffsets);

        $starts = array_column($rows, 'la_start');
        foreach ($rows as $r) {
            if (!isset($validOffsets[$r['la_start']])) {
                throw new \InvalidArgumentException("Ongeldige la_start: {$r['la_start']} is geen zinsgrens.");
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

        $bodyRows = array_filter($rows, static fn($r) => $r['la_start'] !== self::HEADING_LA_START);
        $joined = implode(' ', array_map(static fn($r) => implode(' ', $r['words']), $bodyRows));
        $this->connection->executeStatement(
            "UPDATE translation SET text_nl = :text_nl, model = 'manual' WHERE id = :id",
            ['text_nl' => $joined, 'id' => $translationId]
        );

        if ($layer === self::LLM_LAYER) {
            foreach ($rows as $r) {
                if ($r['la_start'] === self::HEADING_LA_START) {
                    $this->connection->executeStatement(
                        'UPDATE segment SET heading_nl = :heading_nl WHERE id = :id',
                        ['heading_nl' => implode(' ', $r['words']), 'id' => $segmentId]
                    );
                    break;
                }
            }
        }

        return $this->connection->executeStatement(
            'DELETE FROM alignment WHERE translation_id = :translation_id',
            ['translation_id' => $translationId]
        ) > 0;
    }

    /**
     * Splits Dutch text into words with character offsets, mirroring
     * align_segments.py's tokenize_nl() (TOKEN_RE = r"\S+") exactly, so a
     * word's `start` here always matches an `alignment.target_start` value
     * SimAlign could have written. preg_match_all's OFFSET_CAPTURE reports
     * byte offsets even with the /u modifier, so each is converted to a
     * character offset via mb_strlen on the preceding substring (same
     * conversion as splitLatinSentences).
     *
     * @return array<int, array{start: int, end: int, text: string}>
     */
    private function splitDutchWords(string $text): array
    {
        preg_match_all('/\S+/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $words = [];
        foreach ($matches[0] as [$word, $byteOffset]) {
            $start = mb_strlen(substr($text, 0, $byteOffset), 'UTF-8');
            $words[] = ['start' => $start, 'end' => $start + mb_strlen($word, 'UTF-8'), 'text' => $word];
        }
        return $words;
    }

    /**
     * Segment + word-level alignment structure for the word-linking screen
     * (InstitutioWordLinkController) -- styled and behaved like the Bible
     * corpus's Hebrew/Greek<->Dutch linking screen (LinkingController +
     * word_linker_controller.js), reviewing/correcting the `alignment`
     * table SimAlign populates in phase 4 (align_segments.py).
     *
     * Dutch words have no persistent id of their own (unlike the Bible
     * corpus's translation_words table), so a word's character offset
     * within translation.text_nl doubles as its stable identifier here --
     * exactly the value already stored in alignment.target_start.
     *
     * @return array{
     *   id: int, ref: string, translation_id: int,
     *   tokens: array<int, array{id: int, surface: string, lemma: ?string,
     *     best_method: string,
     *     links: array<int, array{start: int, end: int, text: string, source: string}>}>,
     *   nl_words: array<int, array{start: int, text: string, best_method: string}>
     * }|null
     */
    public function getSegmentWordLinksForEdit(int $segmentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT s.id, s.ref, tr.id AS translation_id, tr.text_nl
             FROM segment s
             LEFT JOIN translation tr ON tr.segment_id = s.id AND tr.layer = 'llm'
             WHERE s.id = :id",
            ['id' => $segmentId]
        );
        if ($row === false || $row['translation_id'] === null) {
            return null;
        }
        $translationId = (int) $row['translation_id'];

        $tokenRows = $this->connection->fetchAllAssociative(
            'SELECT id, surface, lemma FROM token
             WHERE segment_id = :segment_id AND is_word ORDER BY position',
            ['segment_id' => $segmentId]
        );

        $linkRows = $this->connection->fetchAllAssociative(
            'SELECT token_id, target_start, target_end, target_text, source
             FROM alignment WHERE translation_id = :translation_id',
            ['translation_id' => $translationId]
        );

        $linksByToken = [];
        $bestByNlStart = [];
        foreach ($linkRows as $l) {
            $link = [
                'start'  => (int) $l['target_start'],
                'end'    => (int) $l['target_end'],
                'text'   => $l['target_text'],
                'source' => $l['source'],
            ];
            $linksByToken[(int) $l['token_id']][] = $link;
            if (!isset($bestByNlStart[$link['start']]) || $link['source'] === 'manual') {
                $bestByNlStart[$link['start']] = $link['source'];
            }
        }

        $tokens = array_map(static function ($t) use ($linksByToken) {
            $links = $linksByToken[(int) $t['id']] ?? [];
            $hasManual = (bool) array_filter($links, static fn($l) => $l['source'] === 'manual');
            return [
                'id'          => (int) $t['id'],
                'surface'     => $t['surface'],
                'lemma'       => $t['lemma'],
                'links'       => $links,
                'best_method' => $links ? ($hasManual ? 'manual' : 'simalign') : 'none',
            ];
        }, $tokenRows);

        $nlWords = array_map(
            static fn($w) => [
                'start'       => $w['start'],
                'text'        => $w['text'],
                'best_method' => $bestByNlStart[$w['start']] ?? 'none',
            ],
            $this->splitDutchWords((string) $row['text_nl'])
        );

        return [
            'id'             => (int) $row['id'],
            'ref'            => $row['ref'],
            'translation_id' => $translationId,
            'tokens'         => $tokens,
            'nl_words'       => $nlWords,
        ];
    }

    /**
     * Manually (re-)link one Latin word token to zero or more Dutch words,
     * replacing whatever links (manual or SimAlign) it already had --
     * mirrors LinkingRepository::saveManualLinks()'s "delete this source
     * word's links for this translation, then insert the new set" pattern.
     * An empty $targetStarts is a valid "confirmed: no counterpart" result,
     * matching that same semantics (no separate "manually empty" marker
     * table here, unlike the Bible corpus -- an unlinked token and a
     * confirmed-empty one aren't distinguished, which is an acceptable
     * simplification for this smaller, single-editor corpus).
     *
     * @param array<int, int> $targetStarts Dutch word start offsets (each
     *   must be a real word boundary in the segment's current translation;
     *   an offset that doesn't match one is silently skipped rather than
     *   corrupting a row with a bogus span).
     * @throws \InvalidArgumentException if the token doesn't belong to this
     *   segment, or the segment has no translation yet
     */
    public function saveManualWordLink(int $segmentId, int $tokenId, array $targetStarts): void
    {
        $tokenBelongs = (bool) $this->connection->fetchOne(
            'SELECT 1 FROM token WHERE id = :token_id AND segment_id = :segment_id AND is_word',
            ['token_id' => $tokenId, 'segment_id' => $segmentId]
        );
        if (!$tokenBelongs) {
            throw new \InvalidArgumentException('Woord hoort niet bij dit segment.');
        }

        $translation = $this->connection->fetchAssociative(
            "SELECT id, text_nl FROM translation WHERE segment_id = :segment_id AND layer = 'llm'",
            ['segment_id' => $segmentId]
        );
        if ($translation === false) {
            throw new \InvalidArgumentException('Segment heeft nog geen vertaling.');
        }
        $translationId = (int) $translation['id'];

        $wordsByStart = [];
        foreach ($this->splitDutchWords((string) $translation['text_nl']) as $w) {
            $wordsByStart[$w['start']] = $w;
        }

        $this->connection->executeStatement(
            'DELETE FROM alignment WHERE token_id = :token_id AND translation_id = :translation_id',
            ['token_id' => $tokenId, 'translation_id' => $translationId]
        );

        foreach ($targetStarts as $start) {
            $w = $wordsByStart[$start] ?? null;
            if ($w === null) {
                continue;
            }
            $this->connection->executeStatement(
                "INSERT INTO alignment (token_id, translation_id, target_start, target_end, target_text, confidence, source)
                 VALUES (:token_id, :translation_id, :start, :end, :text, NULL, 'manual')
                 ON CONFLICT (token_id, translation_id, target_start) DO UPDATE
                     SET target_end = EXCLUDED.target_end, target_text = EXCLUDED.target_text,
                         source = 'manual', confidence = NULL",
                [
                    'token_id'       => $tokenId,
                    'translation_id' => $translationId,
                    'start'          => $w['start'],
                    'end'            => $w['end'],
                    'text'           => $w['text'],
                ]
            );
        }
    }
}
