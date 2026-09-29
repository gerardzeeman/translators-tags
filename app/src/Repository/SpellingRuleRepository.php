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
    private const FIELDS = ['kind', 'source', 'target', 'exceptions', 'position', 'active', 'note', 'is_default'];

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

    /** Whether another rule of the layer has this kind, source and target
     *  already (case counts: "Gods" and "gods" are two old forms). */
    public function exists(string $layer, string $kind, string $source, string $target, ?int $exceptId = null): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM spelling_rule WHERE layer = ? AND kind = ? AND source = ? AND target = ? AND id <> ?',
            [$layer, $kind, trim($source), trim($target), $exceptId ?? 0]
        );
    }

    /**
     * The word rules for the same old form as $source (SpellingRules::key):
     * the default and its alternatives.
     *
     * @return list<array<string, mixed>>
     */
    public function sameSource(string $layer, string $source): array
    {
        $key = \App\Service\SpellingRules::key($source);
        return array_values(array_filter(
            array_map([$this, 'normalise'], $this->connection->fetchAllAssociative(
                "SELECT * FROM spelling_rule WHERE layer = ? AND kind = 'word' AND lower(source) = lower(?) ORDER BY id",
                [$layer, trim($source)]
            )),
            fn($r) => \App\Service\SpellingRules::key($r['source']) === $key
        ));
    }

    /**
     * A new rule. A word rule for an old form that has one already is an
     * alternative, unless it is to be the default ($data['is_default']).
     *
     * @param array<string, mixed> $data kind, source, target, exceptions, position, active, note, is_default?
     */
    public function create(string $layer, array $data, ?string $user): int
    {
        $others = $data['kind'] === 'word' ? $this->sameSource($layer, $data['source']) : [];
        $default = !$others || self::truthy($data['is_default'] ?? false);
        $id = (int) $this->connection->fetchOne(
            'INSERT INTO spelling_rule (layer, kind, source, target, exceptions, position, active, note, is_default, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$layer, ...$this->values($data), $default ? 'true' : 'false', $user]
        );
        if ($default && $others) {
            $this->makeDefault($id);
        }
        return $id;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->connection->executeStatement(
            'UPDATE spelling_rule SET kind = ?, source = ?, target = ?, exceptions = ?, position = ?, active = ?,
                    note = ?, updated_at = now() WHERE id = ?',
            [...$this->values($data), $id]
        );
        if (self::truthy($data['is_default'] ?? false)) {
            $this->makeDefault($id);
        }
    }

    /** This rule the default for its old form; the others alternatives. */
    public function makeDefault(int $id): void
    {
        $rule = $this->find($id);
        if ($rule === null || $rule['kind'] !== 'word') {
            return;
        }
        foreach ($this->sameSource($rule['layer'], $rule['source']) as $r) {
            $this->connection->executeStatement(
                'UPDATE spelling_rule SET is_default = ?, updated_at = now() WHERE id = ?',
                [$r['id'] === $id ? 'true' : 'false', $r['id']]
            );
        }
    }

    /** Deleting the default makes the oldest alternative the default. */
    public function delete(int $id): void
    {
        $rule = $this->find($id);
        $this->connection->executeStatement('DELETE FROM spelling_rule WHERE id = ?', [$id]);
        if ($rule !== null && $rule['kind'] === 'word' && $rule['is_default']) {
            $rest = $this->sameSource($rule['layer'], $rule['source']);
            if ($rest) {
                $this->makeDefault($rest[0]['id']);
            }
        }
    }

    // ── Choices at single spots (spelling_choice) ────────────────────────────

    /**
     * The choices in these segments: segment id => old form (key) =>
     * occurrence => rule id, or 0 for Los's own form (SpellingContext).
     *
     * @param list<int> $segmentIds
     * @return array<int, array<string, array<int, int>>>
     */
    public function choicesFor(string $layer, array $segmentIds): array
    {
        if (!$segmentIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT segment_id, source, occurrence, rule_id FROM spelling_choice WHERE layer = ? AND segment_id IN (?)',
            [$layer, $segmentIds], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['segment_id']][$r['source']][(int) $r['occurrence']] = (int) ($r['rule_id'] ?? 0);
        }
        return $out;
    }

    /**
     * The choice at one spot: a rule id, 0 for Los's own form, or null to
     * go back to the default.
     */
    public function choose(string $layer, int $segmentId, string $key, int $occurrence, ?int $ruleId, ?string $user): void
    {
        if ($ruleId === null) {
            $this->connection->executeStatement(
                'DELETE FROM spelling_choice WHERE layer = ? AND segment_id = ? AND source = ? AND occurrence = ?',
                [$layer, $segmentId, $key, $occurrence]
            );
            return;
        }
        $this->connection->executeStatement(
            'INSERT INTO spelling_choice (layer, segment_id, source, occurrence, rule_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (layer, segment_id, source, occurrence)
             DO UPDATE SET rule_id = EXCLUDED.rule_id, created_by = EXCLUDED.created_by, updated_at = now()',
            [$layer, $segmentId, $key, $occurrence, $ruleId ?: null, $user]
        );
    }

    /**
     * Where a segment belongs -- its work, chapter (0: none) and kind -- and
     * its Dutch in this layer; for showing its modern spelling again after a
     * choice at one of its spots.
     *
     * @return array{id: int, work: string, chapter: int, kind: ?string, text_nl: ?string}|null
     */
    public function segmentWithText(string $layer, int $segmentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT s.id, w.slug AS work, COALESCE(s.chapter, 0) AS chapter, s.kind,
                    (SELECT t.text_nl FROM translation t WHERE t.segment_id = s.id AND t.layer = ?) AS text_nl
             FROM segment s JOIN work w ON w.id = s.work_id WHERE s.id = ?',
            [$layer, $segmentId]
        );
        return $row ? ['id' => (int) $row['id'], 'work' => $row['work'], 'chapter' => (int) $row['chapter'],
                       'kind' => $row['kind'], 'text_nl' => $row['text_nl']] : null;
    }

    /** @return array<int, int> rule id => the spots where it is chosen */
    public function choiceCounts(string $layer = self::DEFAULT_LAYER): array
    {
        return array_map('intval', $this->connection->fetchAllKeyValue(
            'SELECT rule_id, count(*) FROM spelling_choice WHERE layer = ? AND rule_id IS NOT NULL GROUP BY rule_id',
            [$layer]
        ));
    }

    /** @return array<string, int> old form => the spots where Los's own form is kept */
    public function keptCounts(string $layer = self::DEFAULT_LAYER): array
    {
        return array_map('intval', $this->connection->fetchAllKeyValue(
            'SELECT source, count(*) FROM spelling_choice WHERE layer = ? AND rule_id IS NULL GROUP BY source',
            [$layer]
        ));
    }

    public function toggle(int $id): void
    {
        $this->connection->executeStatement(
            'UPDATE spelling_rule SET active = NOT active, updated_at = now() WHERE id = ?', [$id]);
    }

    /**
     * The rules of a layer, and the choices made at single spots, for the
     * other environment (dev <-> prod). A choice names its segment by work
     * and ref and its rule by old and new form: ids differ between the two.
     *
     * @return array{format: string, layer: string, exported_at: string, rules: list<array<string, mixed>>,
     *               choices: list<array<string, mixed>>}
     */
    public function export(string $layer = self::DEFAULT_LAYER): array
    {
        $choices = $this->connection->fetchAllAssociative(
            'SELECT w.slug AS work, s.ref AS segment, c.source, c.occurrence, r.source AS rule_source, r.target AS rule_target
             FROM spelling_choice c
             JOIN segment s ON s.id = c.segment_id JOIN work w ON w.id = s.work_id
             LEFT JOIN spelling_rule r ON r.id = c.rule_id
             WHERE c.layer = ? ORDER BY s.id, c.source, c.occurrence',
            [$layer]
        );
        return [
            'format'      => 'alefomega-spelling-rules/2',
            'layer'       => $layer,
            'exported_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'rules'       => array_map(
                fn($r) => array_intersect_key($r, array_flip(self::FIELDS)),
                $this->all($layer)
            ),
            'choices'     => array_map(fn($c) => [
                'work'       => $c['work'],
                'segment'    => $c['segment'],
                'source'     => $c['source'],
                'occurrence' => (int) $c['occurrence'],
                // null: Los's own form
                'rule'       => $c['rule_source'] === null ? null : ['source' => $c['rule_source'], 'target' => $c['rule_target']],
            ], $choices),
        ];
    }

    /**
     * Rules (and choices) from an export: a rule with the same kind, old and
     * new form is updated, others are added; with $replace the layer's other
     * rules -- and its choices -- go. A choice whose segment or rule this
     * database lacks is skipped. One transaction: all or nothing.
     *
     * @param list<array<string, mixed>> $rules
     * @param list<array<string, mixed>> $choices
     * @return array{added: int, updated: int, removed: int, choices: int, choices_skipped: int}
     */
    public function import(string $layer, array $rules, bool $replace, ?string $user, array $choices = []): array
    {
        return $this->connection->transactional(function () use ($layer, $rules, $replace, $user, $choices): array {
            $added = $updated = 0;
            $keep = [];
            foreach ($rules as $rule) {
                $id = $this->connection->fetchOne(
                    'SELECT id FROM spelling_rule WHERE layer = ? AND kind = ? AND source = ? AND target = ?',
                    [$layer, $rule['kind'], trim((string) $rule['source']), trim((string) $rule['target'])]
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
                $this->connection->executeStatement('DELETE FROM spelling_choice WHERE layer = ?', [$layer]);
                $removed = $keep
                    ? $this->connection->executeStatement(
                        'DELETE FROM spelling_rule WHERE layer = ? AND id NOT IN (?)',
                        [$layer, $keep], [\Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ArrayParameterType::INTEGER])
                    : $this->connection->executeStatement('DELETE FROM spelling_rule WHERE layer = ?', [$layer]);
            }
            $imported = $skipped = 0;
            foreach ($choices as $c) {
                $segmentId = $this->connection->fetchOne(
                    'SELECT s.id FROM segment s JOIN work w ON w.id = s.work_id WHERE w.slug = ? AND s.ref = ?',
                    [$c['work'] ?? '', $c['segment'] ?? '']
                );
                $ruleId = 0;
                if (!empty($c['rule'])) {
                    $ruleId = (int) $this->connection->fetchOne(
                        "SELECT id FROM spelling_rule WHERE layer = ? AND kind = 'word' AND source = ? AND target = ?",
                        [$layer, $c['rule']['source'] ?? '', $c['rule']['target'] ?? '']
                    );
                }
                if (!$segmentId || (!empty($c['rule']) && !$ruleId)) {
                    $skipped++;
                    continue;
                }
                $this->choose($layer, (int) $segmentId, (string) $c['source'], (int) $c['occurrence'], $ruleId, $user);
                $imported++;
            }
            return ['added' => $added, 'updated' => $updated, 'removed' => (int) $removed,
                    'choices' => $imported, 'choices_skipped' => $skipped];
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
        $row['active'] = self::truthy($row['active']);
        $row['is_default'] = self::truthy($row['is_default'] ?? true);
        return $row;
    }

    private static function truthy(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 't', 'true', 'on'], true);
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
