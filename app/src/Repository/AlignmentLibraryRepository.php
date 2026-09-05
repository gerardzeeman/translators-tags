<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * DB-backed rule tables that let a manual link made in the historical-alignment
 * review UI be promoted into a reusable matching rule (see
 * App\Service\Alignment\AlignmentLibraryService and
 * App\Service\Alignment\HistoricalAlignmentService::DEFAULT_LEXICON and
 * friends, which these tables extend at construction time).
 */
class AlignmentLibraryRepository
{
    /** Allow-list for deleteEntry()'s table name -- never interpolate a caller-supplied string otherwise. */
    private const TABLES = [
        'lexicon' => 'alignment_lexicon',
        'bridge'  => 'alignment_synonym_bridge',
        'multi'   => 'alignment_multi_synonym_bridge',
        'phrase'  => 'alignment_phrase_bridge',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    // ── Loading (merged on top of HistoricalAlignmentService's defaults) ────

    /** @return array<string, string> source_form => target_form */
    public function loadLexicon(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT source_form, target_form FROM alignment_lexicon');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['source_form']] = $r['target_form'];
        }
        return $out;
    }

    /** @return array<string, string[]> source_form => list of acceptable target forms */
    public function loadSynonymBridge(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT source_form, target_form FROM alignment_synonym_bridge ORDER BY id');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['source_form']][] = $r['target_form'];
        }
        return $out;
    }

    /** @return array<string, string[]> source_form => set of target forms that must ALL appear */
    public function loadMultiSynonymBridge(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT source_form, target_forms FROM alignment_multi_synonym_bridge');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['source_form']] = $this->decodePgArray($r['target_forms']);
        }
        return $out;
    }

    /** @return list<array{0: string[], 1: string[]}> */
    public function loadPhraseBridge(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT source_forms, target_forms FROM alignment_phrase_bridge ORDER BY id');
        $out = [];
        foreach ($rows as $r) {
            $out[] = [$this->decodePgArray($r['source_forms']), $this->decodePgArray($r['target_forms'])];
        }
        return $out;
    }

    private function decodePgArray(string $pgArray): array
    {
        // psycopg/DBAL return PostgreSQL TEXT[] as a literal like {a,b,c}; no
        // element here ever contains a comma or brace (single normalised words).
        $trimmed = trim($pgArray, '{}');
        return $trimmed === '' ? [] : str_getcsv($trimmed);
    }

    // ── Writing ──────────────────────────────────────────────────────────────

    /** @return 'added'|'conflict' */
    public function addLexiconEntry(string $sourceForm, string $targetForm, ?int $sourceLinkId): string
    {
        $affected = $this->connection->executeStatement(
            'INSERT INTO alignment_lexicon (source_form, target_form, source_link_id)
             VALUES (:s, :t, :l)
             ON CONFLICT (source_form) DO NOTHING',
            ['s' => $sourceForm, 't' => $targetForm, 'l' => $sourceLinkId],
            ['l' => ParameterType::INTEGER]
        );

        return $affected > 0 ? 'added' : 'conflict';
    }

    /** @return 'added'|'exists' */
    public function addSynonymBridgeEntry(string $sourceForm, string $targetForm, ?int $sourceLinkId): string
    {
        $affected = $this->connection->executeStatement(
            'INSERT INTO alignment_synonym_bridge (source_form, target_form, source_link_id)
             VALUES (:s, :t, :l)
             ON CONFLICT (source_form, target_form) DO NOTHING',
            ['s' => $sourceForm, 't' => $targetForm, 'l' => $sourceLinkId],
            ['l' => ParameterType::INTEGER]
        );

        return $affected > 0 ? 'added' : 'exists';
    }

    /**
     * @param string[] $targetForms
     * @return 'added'|'conflict'
     */
    public function addMultiSynonymBridgeEntry(string $sourceForm, array $targetForms, ?int $sourceLinkId): string
    {
        $affected = $this->connection->executeStatement(
            'INSERT INTO alignment_multi_synonym_bridge (source_form, target_forms, source_link_id)
             VALUES (:s, :t, :l)
             ON CONFLICT (source_form) DO NOTHING',
            ['s' => $sourceForm, 't' => '{' . implode(',', array_map($this->escapePgArrayElement(...), $targetForms)) . '}', 'l' => $sourceLinkId],
            ['l' => ParameterType::INTEGER]
        );

        return $affected > 0 ? 'added' : 'conflict';
    }

    /**
     * @param string[] $sourceForms
     * @param string[] $targetForms
     * @return 'added'|'exists'
     */
    public function addPhraseBridgeEntry(array $sourceForms, array $targetForms, ?int $sourceLinkId): string
    {
        $affected = $this->connection->executeStatement(
            'INSERT INTO alignment_phrase_bridge (source_forms, target_forms, source_link_id)
             VALUES (:s, :t, :l)
             ON CONFLICT (source_forms, target_forms) DO NOTHING',
            [
                's' => '{' . implode(',', array_map($this->escapePgArrayElement(...), $sourceForms)) . '}',
                't' => '{' . implode(',', array_map($this->escapePgArrayElement(...), $targetForms)) . '}',
                'l' => $sourceLinkId,
            ],
            ['l' => ParameterType::INTEGER]
        );

        return $affected > 0 ? 'added' : 'exists';
    }

    private function escapePgArrayElement(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    // ── Pair-group lookup (for auto-detecting bridge/multi/phrase) ──────────

    /**
     * Given two just-linked translation_words, resolves which of their two
     * translations is historically older (source, per alignment_sequence)
     * and returns every word on each side of the connected group they
     * belong to within this verse -- i.e. every word from these two specific
     * translations transitively joined by inter_translation_links rows
     * between them. A manual link is always one word <-> one word, but
     * several such links sharing a word form the 1:1 / 1:n / n:m group that
     * bridge/multi/phrase detection needs to look at.
     *
     * Returns null if the two words don't resolve to two distinct
     * translations with a known chronological order.
     *
     * @return array{source_link_id: ?int, source_words: list<array{id:int,word_text:string}>, target_words: list<array{id:int,word_text:string}>}|null
     */
    public function findPairGroup(int $wordAId, int $wordBId): ?array
    {
        $meta = $this->connection->fetchAllAssociative(
            "SELECT tw.id, tw.word_position, tw.word_text, tv.translation_id, tv.book_id, tv.chapter, tv.verse,
                    t.alignment_sequence
             FROM translation_words tw
             JOIN translation_verses tv ON tv.id = tw.verse_id
             JOIN translations t ON t.id = tv.translation_id
             WHERE tw.id IN (:ids)",
            ['ids' => [$wordAId, $wordBId]],
            ['ids' => ArrayParameterType::INTEGER]
        );
        if (count($meta) !== 2) {
            return null;
        }
        [$wa, $wb] = $meta;
        if ((int) $wa['translation_id'] === (int) $wb['translation_id']
            || $wa['alignment_sequence'] === null || $wb['alignment_sequence'] === null) {
            return null;
        }

        [$src, $tgt] = ((int) $wa['alignment_sequence']) < ((int) $wb['alignment_sequence']) ? [$wa, $wb] : [$wb, $wa];

        $sourceWords = $this->connection->fetchAllAssociative(
            'SELECT tw.id, tw.word_position, tw.word_text
             FROM translation_words tw
             JOIN translation_verses tv ON tv.id = tw.verse_id
             WHERE tv.translation_id = :tid AND tv.book_id = :bid AND tv.chapter = :ch AND tv.verse = :vs
             ORDER BY tw.word_position',
            ['tid' => $src['translation_id'], 'bid' => $src['book_id'], 'ch' => $src['chapter'], 'vs' => $src['verse']]
        );
        $targetWords = $this->connection->fetchAllAssociative(
            'SELECT tw.id, tw.word_position, tw.word_text
             FROM translation_words tw
             JOIN translation_verses tv ON tv.id = tw.verse_id
             WHERE tv.translation_id = :tid AND tv.book_id = :bid AND tv.chapter = :ch AND tv.verse = :vs
             ORDER BY tw.word_position',
            ['tid' => $tgt['translation_id'], 'bid' => $tgt['book_id'], 'ch' => $tgt['chapter'], 'vs' => $tgt['verse']]
        );

        $sourceIds = array_column($sourceWords, 'id');
        $targetIds = array_column($targetWords, 'id');
        $links = $this->connection->fetchAllAssociative(
            '(SELECT word_a_id AS a, word_b_id AS b FROM inter_translation_links WHERE word_a_id IN (:src) AND word_b_id IN (:tgt))
             UNION ALL
             (SELECT word_b_id AS a, word_a_id AS b FROM inter_translation_links WHERE word_b_id IN (:src) AND word_a_id IN (:tgt))',
            ['src' => $sourceIds, 'tgt' => $targetIds],
            ['src' => ArrayParameterType::INTEGER, 'tgt' => ArrayParameterType::INTEGER]
        );

        // Union-find over {source ids} u {target ids}, edges = links between them.
        $parent = [];
        foreach ([...$sourceIds, ...$targetIds] as $id) {
            $parent[$id] = $id;
        }
        $find = function (int $x) use (&$parent, &$find): int {
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        foreach ($links as $l) {
            $ra = $find((int) $l['a']);
            $rb = $find((int) $l['b']);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        }

        $root = $find($wordAId);
        $groupSourceWords = array_values(array_filter($sourceWords, fn($w) => $find((int) $w['id']) === $root));
        $groupTargetWords = array_values(array_filter($targetWords, fn($w) => $find((int) $w['id']) === $root));

        [$a, $b] = $wordAId < $wordBId ? [$wordAId, $wordBId] : [$wordBId, $wordAId];
        $linkId = $this->connection->fetchOne(
            'SELECT id FROM inter_translation_links WHERE word_a_id = :a AND word_b_id = :b',
            ['a' => $a, 'b' => $b]
        );

        return [
            'source_link_id' => $linkId !== false ? (int) $linkId : null,
            'source_words'   => $groupSourceWords,
            'target_words'   => $groupTargetWords,
        ];
    }

    // ── Overview page ────────────────────────────────────────────────────────

    /** @return list<array{kind: string, id: int, source: string, target: string, created_at: string}> */
    public function listEntries(): array
    {
        $out = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, source_form, target_form, created_at FROM alignment_lexicon ORDER BY created_at DESC'
        ) as $r) {
            $out[] = ['kind' => 'lexicon', 'id' => (int) $r['id'], 'source' => $r['source_form'], 'target' => $r['target_form'], 'created_at' => $r['created_at']];
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, source_form, target_form, created_at FROM alignment_synonym_bridge ORDER BY created_at DESC'
        ) as $r) {
            $out[] = ['kind' => 'bridge', 'id' => (int) $r['id'], 'source' => $r['source_form'], 'target' => $r['target_form'], 'created_at' => $r['created_at']];
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, source_form, target_forms, created_at FROM alignment_multi_synonym_bridge ORDER BY created_at DESC'
        ) as $r) {
            $out[] = ['kind' => 'multi', 'id' => (int) $r['id'], 'source' => $r['source_form'], 'target' => implode(' + ', $this->decodePgArray($r['target_forms'])), 'created_at' => $r['created_at']];
        }
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, source_forms, target_forms, created_at FROM alignment_phrase_bridge ORDER BY created_at DESC'
        ) as $r) {
            $out[] = ['kind' => 'phrase', 'id' => (int) $r['id'], 'source' => implode(' ', $this->decodePgArray($r['source_forms'])), 'target' => implode(' ', $this->decodePgArray($r['target_forms'])), 'created_at' => $r['created_at']];
        }

        usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $out;
    }

    public function deleteEntry(string $kind, int $id): bool
    {
        if (!isset(self::TABLES[$kind])) {
            return false;
        }
        $table = self::TABLES[$kind];

        return $this->connection->executeStatement("DELETE FROM {$table} WHERE id = :id", ['id' => $id]) > 0;
    }
}
