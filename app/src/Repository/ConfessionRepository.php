<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * ConfessionRepository
 * Read-only queries for the confessional-document works added by
 * db/migrate_add_confession_documents.sql (Canones Dordraceni, and future
 * works such as the Nederlandse Geloofsbelijdenis / Heidelbergse Catechismus)
 * -- reuses the Institutio pipeline's work/segment/token/lemma_gloss tables,
 * but deliberately does NOT touch InstitutioRepository: that class is
 * hardcoded to a single work (WORK_SLUG = 'institutio-1559') and to a
 * book->chapter->section reading structure that doesn't fit these documents
 * (Canones interleaves numbered "Articulus" and "Rejectio Errorum"
 * paragraphs within a chapter -- see segment.kind), so a parallel,
 * work-slug-parametrized repository is simpler and safer than reshaping
 * 1400 lines of already-in-production Institutio code around a second
 * hierarchy shape it was never designed for.
 *
 * No translation/alignment support yet: these works don't have a Dutch
 * translation ingested (see PROJECTDOSSIER.md), so this repository only
 * ever reads text_la + token/lemma_gloss (for the word-hover gloss popup) --
 * no 'llm' translation layer join, unlike InstitutioRepository.
 */
class ConfessionRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    private function getWorkId(string $workSlug): ?int
    {
        $id = $this->connection->fetchOne('SELECT id FROM work WHERE slug = :slug', ['slug' => $workSlug]);
        return $id === false ? null : (int) $id;
    }

    /** @return array{id: int, slug: string, title: string, source: ?string}|null */
    public function getWork(string $workSlug): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, slug, title, source FROM work WHERE slug = :slug',
            ['slug' => $workSlug]
        );
        if ($row === false) {
            return null;
        }
        return ['id' => (int) $row['id'], 'slug' => $row['slug'], 'title' => $row['title'], 'source' => $row['source']];
    }

    /**
     * One row per chapter, with its heading and how many articles/rejections
     * it holds -- powers the table of contents.
     * @return array<int, array{chapter: int, heading: ?string, article_count: int, rejection_count: int}>
     */
    public function getChapters(string $workSlug): array
    {
        $workId = $this->getWorkId($workSlug);
        if ($workId === null) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT chapter,
                    max(heading) AS heading,
                    count(*) FILTER (WHERE kind = 'article')   AS article_count,
                    count(*) FILTER (WHERE kind = 'rejection') AS rejection_count
             FROM segment
             WHERE work_id = :work_id AND chapter IS NOT NULL
             GROUP BY chapter
             ORDER BY chapter",
            ['work_id' => $workId]
        );

        return array_map(
            fn($r) => [
                'chapter'         => (int) $r['chapter'],
                'heading'         => $r['heading'],
                'article_count'   => (int) $r['article_count'],
                'rejection_count' => (int) $r['rejection_count'],
            ],
            $rows
        );
    }

    /**
     * True if this work has segments with book/chapter both NULL matching
     * the given ref prefix (e.g. 'voorwoord' or 'besluit') -- used to show/
     * hide the "Voorwoord"/"Besluit" links on the table-of-contents page.
     */
    public function hasUnnumberedSection(string $workSlug, string $refPrefix): bool
    {
        $workId = $this->getWorkId($workSlug);
        if ($workId === null) {
            return false;
        }
        return (bool) $this->connection->fetchOne(
            "SELECT 1 FROM segment WHERE work_id = :work_id AND chapter IS NULL AND ref LIKE :prefix LIMIT 1",
            ['work_id' => $workId, 'prefix' => $refPrefix . '.%']
        );
    }

    /**
     * @return array{heading: ?string, articles: array<int, array>, rejections: array<int, array>}
     */
    public function getChapter(string $workSlug, int $chapter): array
    {
        $workId = $this->getWorkId($workSlug);
        if ($workId === null) {
            return ['heading' => null, 'articles' => [], 'rejections' => []];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, section, kind, heading, text_la
             FROM segment
             WHERE work_id = :work_id AND chapter = :chapter
             ORDER BY seq',
            ['work_id' => $workId, 'chapter' => $chapter]
        );
        if (!$rows) {
            return ['heading' => null, 'articles' => [], 'rejections' => []];
        }

        $withTokens = $this->attachTokens($rows);
        $articles = array_values(array_filter($withTokens, fn($r) => $r['kind'] === 'article'));
        $rejections = array_values(array_filter($withTokens, fn($r) => $r['kind'] === 'rejection'));

        return ['heading' => $rows[0]['heading'], 'articles' => $articles, 'rejections' => $rejections];
    }

    /**
     * The unnumbered segments (book/chapter both NULL) whose ref starts
     * with the given prefix, in order -- used for both "voorwoord" and
     * "besluit" (Canones' preface and conclusion).
     * @return array<int, array{id: int, section: int, text_la: string, tokens: array}>
     */
    public function getUnnumberedSection(string $workSlug, string $refPrefix): array
    {
        $workId = $this->getWorkId($workSlug);
        if ($workId === null) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, section, kind, heading, text_la
             FROM segment
             WHERE work_id = :work_id AND chapter IS NULL AND ref LIKE :prefix
             ORDER BY seq',
            ['work_id' => $workId, 'prefix' => $refPrefix . '.%']
        );

        return $this->attachTokens($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows each with at least 'id', 'text_la'
     * @return array<int, array<string, mixed>> same rows, with a 'tokens' key added
     */
    private function attachTokens(array $rows): array
    {
        $segmentIds = array_map(fn($r) => (int) $r['id'], $rows);
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

        return array_map(
            fn($r) => [
                'id'      => (int) $r['id'],
                'section' => (int) $r['section'],
                'kind'    => $r['kind'],
                'heading' => $r['heading'],
                'text_la' => $r['text_la'],
                'tokens'  => $tokensBySegment[(int) $r['id']] ?? [],
            ],
            $rows
        );
    }

    /**
     * Splits text_la into {type, content, lemma?, gloss?} parts using the
     * segment's tokens -- identical algorithm to InstitutioRepository's
     * private splitTextIntoWordParts(), duplicated rather than shared since
     * that method lives on a class this repository deliberately doesn't
     * depend on (see class docblock).
     * @param array<int, array{char_start: int, char_end: int, lemma: ?string, gloss: ?string}> $tokens
     * @return array<int, array{type: string, content: string, lemma?: ?string, gloss?: ?string}>
     */
    public function splitTextIntoWordParts(string $text, array $tokens): array
    {
        $parts = [];
        $cursor = 0;
        foreach ($tokens as $tok) {
            $start = $tok['char_start'];
            $end = $tok['char_end'];
            if ($start < $cursor) {
                continue;
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

    /**
     * The chapter numbers before/after $chapter that actually have segments,
     * for prev/next navigation -- null at either end of the work.
     * @return array{prev: ?int, next: ?int}
     */
    public function getAdjacentChapters(string $workSlug, int $chapter): array
    {
        $workId = $this->getWorkId($workSlug);
        if ($workId === null) {
            return ['prev' => null, 'next' => null];
        }
        $prev = $this->connection->fetchOne(
            'SELECT max(chapter) FROM segment WHERE work_id = :work_id AND chapter < :chapter',
            ['work_id' => $workId, 'chapter' => $chapter]
        );
        $next = $this->connection->fetchOne(
            'SELECT min(chapter) FROM segment WHERE work_id = :work_id AND chapter > :chapter',
            ['work_id' => $workId, 'chapter' => $chapter]
        );
        return [
            'prev' => $prev !== false && $prev !== null ? (int) $prev : null,
            'next' => $next !== false && $next !== null ? (int) $next : null,
        ];
    }

    /**
     * One segment's text_la, for the correction editor (see
     * ConfessionController::editText/saveText).
     * @return array{id: int, ref: string, text_la: string}|null
     */
    public function getSegmentText(int $segmentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, ref, text_la FROM segment WHERE id = :id',
            ['id' => $segmentId]
        );
        return $row === false ? null : ['id' => (int) $row['id'], 'ref' => $row['ref'], 'text_la' => $row['text_la']];
    }

    /**
     * Corrects a segment's Latin source text (e.g. fixing a source-OCR/
     * transcription error found after ingest). Unlike translation edits,
     * this changes text_la itself, which every token's char_start/char_end
     * was computed against -- so the segment's existing tokens are dropped
     * and its status reset to 'ingested', exactly like a fresh (re-)ingest,
     * so a subsequent `tokenize_latin.py` run picks it back up. The old/new
     * text is recorded in segment_text_correction as an audit trail (see
     * db/migrate_add_confession_documents.sql) -- append-only, never
     * updated or deleted.
     */
    public function saveTextCorrection(int $segmentId, string $newText, ?int $userId, ?string $note): void
    {
        $this->connection->transactional(function (Connection $conn) use ($segmentId, $newText, $userId, $note) {
            $old = $conn->fetchOne('SELECT text_la FROM segment WHERE id = :id', ['id' => $segmentId]);
            if ($old === false) {
                throw new \InvalidArgumentException("Segment {$segmentId} bestaat niet.");
            }
            if ($old === $newText) {
                return;
            }

            $conn->executeStatement(
                'INSERT INTO segment_text_correction (segment_id, old_text, new_text, note, corrected_by_user_id)
                 VALUES (:segment_id, :old_text, :new_text, :note, :user_id)',
                ['segment_id' => $segmentId, 'old_text' => $old, 'new_text' => $newText,
                 'note' => $note, 'user_id' => $userId]
            );

            $conn->executeStatement(
                "UPDATE segment SET text_la = :text_la, status = 'ingested' WHERE id = :id",
                ['text_la' => $newText, 'id' => $segmentId]
            );

            $conn->executeStatement('DELETE FROM token WHERE segment_id = :id', ['id' => $segmentId]);
        });
    }

    /**
     * @return array<int, array{new_text: string, note: ?string, created_at: \DateTimeInterface}>
     */
    public function getTextCorrectionHistory(int $segmentId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT old_text, new_text, note, created_at
             FROM segment_text_correction WHERE segment_id = :id ORDER BY created_at DESC',
            ['id' => $segmentId]
        );
    }
}
