<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The spelling rules for the modern-spelling version of a historical Dutch
 * translation layer (table spelling_rule, migration Version20260928150000;
 * applied by App\Service\SpellingRules), and the text they apply to.
 */
class SpellingRuleRepository
{
    public const DEFAULT_LAYER = 'los1900';

    /** Fields a rule is exported/imported with. */
    private const FIELDS = ['kind', 'source', 'target', 'exceptions', 'position', 'active', 'note'];

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * All rules of a layer, in the order they apply: word rules (by source),
     * then patterns by position.
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $layer = self::DEFAULT_LAYER): array
    {
        return array_map([$this, 'normalise'], $this->connection->fetchAllAssociative(
            "SELECT * FROM spelling_rule WHERE layer = ?
             ORDER BY CASE kind WHEN 'word' THEN 0 ELSE 1 END, position, lower(source), id",
            [$layer]
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM spelling_rule WHERE id = ?', [$id]);
        return $row ? $this->normalise($row) : null;
    }

    /** Whether another rule of the layer has this kind and source already. */
    public function exists(string $layer, string $kind, string $source, ?int $exceptId = null): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM spelling_rule WHERE layer = ? AND kind = ? AND lower(source) = lower(?) AND id <> ?',
            [$layer, $kind, trim($source), $exceptId ?? 0]
        );
    }

    /** @param array<string, mixed> $data kind, source, target, exceptions, position, active, note */
    public function create(string $layer, array $data, ?string $user): int
    {
        return (int) $this->connection->fetchOne(
            'INSERT INTO spelling_rule (layer, kind, source, target, exceptions, position, active, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$layer, ...$this->values($data), $user]
        );
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->connection->executeStatement(
            'UPDATE spelling_rule SET kind = ?, source = ?, target = ?, exceptions = ?, position = ?, active = ?,
                    note = ?, updated_at = now() WHERE id = ?',
            [...$this->values($data), $id]
        );
    }

    public function delete(int $id): void
    {
        $this->connection->executeStatement('DELETE FROM spelling_rule WHERE id = ?', [$id]);
    }

    public function toggle(int $id): void
    {
        $this->connection->executeStatement(
            'UPDATE spelling_rule SET active = NOT active, updated_at = now() WHERE id = ?', [$id]);
    }

    /**
     * The rules of a layer for the other environment (dev <-> prod).
     *
     * @return array{format: string, layer: string, exported_at: string, rules: list<array<string, mixed>>}
     */
    public function export(string $layer = self::DEFAULT_LAYER): array
    {
        return [
            'format'      => 'alefomega-spelling-rules/1',
            'layer'       => $layer,
            'exported_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'rules'       => array_map(
                fn($r) => array_intersect_key($r, array_flip(self::FIELDS)),
                $this->all($layer)
            ),
        ];
    }

    /**
     * Rules from an export: a rule with the same kind and source is
     * updated, others are added; with $replace the layer's other rules go.
     * One transaction: all or nothing.
     *
     * @param list<array<string, mixed>> $rules
     * @return array{added: int, updated: int, removed: int}
     */
    public function import(string $layer, array $rules, bool $replace, ?string $user): array
    {
        return $this->connection->transactional(function () use ($layer, $rules, $replace, $user): array {
            $added = $updated = 0;
            $keep = [];
            foreach ($rules as $rule) {
                $id = $this->connection->fetchOne(
                    'SELECT id FROM spelling_rule WHERE layer = ? AND kind = ? AND lower(source) = lower(?)',
                    [$layer, $rule['kind'], trim((string) $rule['source'])]
                );
                if ($id) {
                    $this->update((int) $id, $rule);
                    $updated++;
                } else {
                    $id = $this->create($layer, $rule, $user);
                    $added++;
                }
                $keep[] = (int) $id;
            }
            $removed = 0;
            if ($replace) {
                $removed = $keep
                    ? $this->connection->executeStatement(
                        'DELETE FROM spelling_rule WHERE layer = ? AND id NOT IN (?)',
                        [$layer, $keep], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::INTEGER])
                    : $this->connection->executeStatement('DELETE FROM spelling_rule WHERE layer = ?', [$layer]);
            }
            return ['added' => $added, 'updated' => $updated, 'removed' => (int) $removed];
        });
    }

    /**
     * The text the rules apply to: the translator's comments (not his Bible
     * text), with his misprints as meant (⟦C:intended‖printed‖note⟧).
     *
     * @return list<string>
     */
    public function corpus(string $layer = self::DEFAULT_LAYER): array
    {
        $texts = $this->connection->fetchFirstColumn(
            "SELECT t.text_nl FROM translation t JOIN segment s ON s.id = t.segment_id
             WHERE t.layer = ? AND s.kind IN ('commentary', 'argument') ORDER BY s.id",
            [$layer]
        );
        return array_map(
            fn($t) => preg_replace('/⟦C:([^‖⟧]*)‖[^⟧]*⟧/u', '$1', preg_replace('/⟦H:[^⟧]*⟧/u', ' ', (string) $t)),
            $texts
        );
    }

    /**
     * Modern Dutch word forms (lower case): those of the Herziene
     * Statenvertaling -- only as a word list, to tell which of the
     * translator's words are old-fashioned. Cached for a day.
     *
     * @return array<string, true>
     */
    public function modernVocabulary(): array
    {
        return $this->cache->get('spelling_modern_vocabulary_hsv', function (ItemInterface $item): array {
            $item->expiresAfter(86400);
            $words = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT lower(tw.word_normalised)
                 FROM translation_words tw
                 JOIN translation_verses tv ON tv.id = tw.verse_id
                 JOIN translations t ON t.id = tv.translation_id
                 WHERE t.code = 'HSV'"
            );
            return array_fill_keys($words, true);
        });
    }

    /** @param array<string, mixed> $row */
    private function normalise(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['position'] = (int) $row['position'];
        $row['active'] = in_array($row['active'], [true, 1, '1', 't', 'true'], true);
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private function values(array $data): array
    {
        $active = $data['active'] ?? true;
        return [
            $data['kind'],
            trim((string) $data['source']),
            trim((string) $data['target']),
            trim((string) ($data['exceptions'] ?? '')),
            (int) ($data['position'] ?? 0),
            in_array($active, [true, 1, '1', 't', 'true', 'on'], true) ? 'true' : 'false',
            ($note = trim((string) ($data['note'] ?? ''))) === '' ? null : $note,
        ];
    }
}
