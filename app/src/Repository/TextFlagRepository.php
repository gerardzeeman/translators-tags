<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Flags on problems in the text of a commentary (table text_flag, migration
 * Version20260929090000): the flagged text with some context, in the Latin
 * ('la'), Los ('los') or modern-spelling ('modern') column of a segment, a
 * category and a note, open or resolved -- a reference list for fixing the
 * ingest later. Placed and shown on the chapter page (flags_controller.js),
 * listed on /commentaren/flags.
 */
class TextFlagRepository
{
    public const FIELDS = ['la' => 'Latijn', 'los' => 'Los (1900)', 'modern' => 'Moderne spelling'];

    public const CATEGORIES = [
        'leesteken'  => 'Leesteken',
        'ocr'        => 'OCR- of spelfout',
        'woord'      => 'Woord ontbreekt of te veel',
        'uitlijning' => 'Uitlijning Latijn/Nederlands',
        'spelling'   => 'Moderne spelling',
        'overig'     => 'Overig',
    ];

    public function __construct(private readonly Connection $connection) {}

    /**
     * The flags on these segments, by segment.
     *
     * @param list<int> $segmentIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function forSegments(array $segmentIds): array
    {
        if (!$segmentIds) {
            return [];
        }
        $out = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT * FROM text_flag WHERE segment_id IN (?) ORDER BY id',
            [$segmentIds], [ArrayParameterType::INTEGER]
        ) as $row) {
            $out[(int) $row['segment_id']][] = self::normalise($row);
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM text_flag WHERE id = ?', [$id]);
        return $row ? self::normalise($row) : null;
    }

    /**
     * All flags with where they are, for the overview: newest first,
     * optionally only open/resolved ones, of one category or chapter.
     *
     * @return list<array<string, mixed>>
     */
    public function search(?string $status = null, ?string $category = null, ?int $chapter = null): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($status) {
            $where[] = 'f.status = ?';
            $params[] = $status;
        }
        if ($category) {
            $where[] = 'f.category = ?';
            $params[] = $category;
        }
        if ($chapter) {
            $where[] = 's.chapter = ?';
            $params[] = $chapter;
        }
        return array_map([self::class, 'normalise'], $this->connection->fetchAllAssociative(
            'SELECT f.*, s.ref, s.chapter, s.section, s.kind AS segment_kind, s.text_la, w.slug AS work,
                    (SELECT t.text_nl FROM translation t WHERE t.segment_id = s.id AND t.layer = \'los1900\') AS text_nl
             FROM text_flag f JOIN segment s ON s.id = f.segment_id JOIN work w ON w.id = s.work_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY f.status, s.chapter NULLS FIRST, s.section, f.id',
            $params
        ));
    }

    /** @return array<string, int> status => count */
    public function counts(): array
    {
        return array_map('intval', $this->connection->fetchAllKeyValue(
            'SELECT status, count(*) FROM text_flag GROUP BY status'));
    }

    /** @param array<string, mixed> $data field, snippet, context_before, context_after, category, note */
    public function create(int $segmentId, array $data, ?string $user): int
    {
        return (int) $this->connection->fetchOne(
            'INSERT INTO text_flag (segment_id, field, snippet, context_before, context_after, category, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$segmentId, $data['field'], $data['snippet'], $data['context_before'] ?? '', $data['context_after'] ?? '',
             $data['category'], self::nullIfEmpty($data['note'] ?? null), $user]
        );
    }

    /** Category, note and status; resolving records who and when. */
    public function update(int $id, string $category, ?string $note, string $status, ?string $user): void
    {
        $this->connection->executeStatement(
            "UPDATE text_flag SET category = ?, note = ?, status = ?, updated_at = now(),
                    resolved_by = CASE WHEN ? = 'resolved' THEN COALESCE(resolved_by, ?) END,
                    resolved_at = CASE WHEN ? = 'resolved' THEN COALESCE(resolved_at, now()) END
             WHERE id = ?",
            [$category, self::nullIfEmpty($note), $status, $status, $user, $status, $id]
        );
    }

    public function delete(int $id): void
    {
        $this->connection->executeStatement('DELETE FROM text_flag WHERE id = ?', [$id]);
    }

    /**
     * All flags for the other environment (dev <-> prod), their segment by
     * work and ref (ids differ between the two).
     *
     * @return array{format: string, exported_at: string, flags: list<array<string, mixed>>}
     */
    public function export(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT w.slug AS work, s.ref AS segment, f.field, f.snippet, f.context_before, f.context_after,
                    f.category, f.note, f.status, f.created_by, f.created_at, f.resolved_by, f.resolved_at
             FROM text_flag f JOIN segment s ON s.id = f.segment_id JOIN work w ON w.id = s.work_id ORDER BY f.id'
        );
        return ['format' => 'alefomega-text-flags/1', 'exported_at' => (new \DateTimeImmutable())->format(DATE_ATOM), 'flags' => $rows];
    }

    /**
     * Flags from an export: one with the same segment, column, text and
     * context is updated (category, note, status), others added. A flag whose
     * segment this database lacks is skipped. One transaction.
     *
     * @param list<array<string, mixed>> $flags
     * @return array{added: int, updated: int, skipped: int}
     */
    public function import(array $flags, ?string $user): array
    {
        return $this->connection->transactional(function () use ($flags, $user): array {
            $added = $updated = $skipped = 0;
            foreach ($flags as $f) {
                $segmentId = $this->connection->fetchOne(
                    'SELECT s.id FROM segment s JOIN work w ON w.id = s.work_id WHERE w.slug = ? AND s.ref = ?',
                    [$f['work'] ?? '', $f['segment'] ?? '']
                );
                if (!$segmentId || !isset(self::FIELDS[$f['field'] ?? '']) || !isset(self::CATEGORIES[$f['category'] ?? ''])
                    || trim((string) ($f['snippet'] ?? '')) === '') {
                    $skipped++;
                    continue;
                }
                $id = $this->connection->fetchOne(
                    'SELECT id FROM text_flag WHERE segment_id = ? AND field = ? AND snippet = ? AND context_before = ? AND context_after = ?',
                    [$segmentId, $f['field'], $f['snippet'], $f['context_before'] ?? '', $f['context_after'] ?? '']
                );
                $status = ($f['status'] ?? 'open') === 'resolved' ? 'resolved' : 'open';
                if ($id) {
                    $this->update((int) $id, $f['category'], $f['note'] ?? null, $status, $f['resolved_by'] ?? $user);
                    $updated++;
                } else {
                    $id = $this->create((int) $segmentId, $f, $f['created_by'] ?? $user);
                    if ($status === 'resolved') {
                        $this->update($id, $f['category'], $f['note'] ?? null, 'resolved', $f['resolved_by'] ?? $user);
                    }
                    $added++;
                }
            }
            return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
        });
    }

    /** @param array<string, mixed> $row */
    private static function normalise(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['segment_id'] = (int) $row['segment_id'];
        return $row;
    }

    private static function nullIfEmpty(?string $s): ?string
    {
        return $s === null || trim($s) === '' ? null : trim($s);
    }
}
