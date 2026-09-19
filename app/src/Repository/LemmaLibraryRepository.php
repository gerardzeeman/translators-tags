<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * LemmaLibraryRepository
 * A general, work-agnostic view onto `lemma_gloss` -- unlike
 * InstitutioRepository's lemma methods (which exist for the Institutio's
 * own /institutie/lemmas pages and filter to a single hardcoded
 * work_id), every query here spans all currently-ingested works at once,
 * matching db/migrate_add_lemma_number.sql's premise: one shared lexicon,
 * numbered by frequency across the whole corpus, not any one work's.
 *
 * `number` (see scripts/build_lemma_library.py) is the library's stable,
 * citable identifier -- the Latin-corpus equivalent of a Strong's number
 * -- so lookups here are by number first, lemma string second.
 */
class LemmaLibraryRepository
{
    /** Display order for the "Werken" tags, independent of the slugs' own alphabetical order. */
    private const WORK_ORDER = [
        'heidelbergse-catechismus' => 0,
        'ngb'                      => 1,
        'canones-dordraceni'       => 2,
        'institutio-1559'          => 3,
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    /** Total numbered lemmas (i.e. actually used in a currently-ingested work), for pagination. */
    public function getLemmaCount(?string $search = null): int
    {
        if ($search === null || $search === '') {
            return (int) $this->connection->fetchOne(
                'SELECT count(*) FROM lemma_gloss WHERE number IS NOT NULL'
            );
        }
        return (int) $this->connection->fetchOne(
            "SELECT count(*) FROM lemma_gloss
             WHERE number IS NOT NULL AND (lemma ILIKE :q OR gloss_nl ILIKE :q)",
            ['q' => '%' . $search . '%']
        );
    }

    /**
     * One page of the library, ordered by number ascending (1 = most
     * frequent lemma across the whole corpus). Each row also lists which
     * work slugs the lemma actually occurs in, so a reader can see at a
     * glance whether a word is Institutio-only, shared, or confession-only.
     * @return array<int, array{number: int, lemma: string, gloss_nl: ?string, freq: int, works: array<int, string>}>
     */
    public function getLemmaPage(int $limit, int $offset, ?string $search = null): array
    {
        $where = 'lg.number IS NOT NULL';
        $params = ['limit' => $limit, 'offset' => $offset];
        $types = ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER];
        if ($search !== null && $search !== '') {
            $where .= ' AND (lg.lemma ILIKE :q OR lg.gloss_nl ILIKE :q)';
            $params['q'] = '%' . $search . '%';
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT lg.number, lg.lemma, lg.gloss_nl,
                    ls.freq,
                    array_agg(DISTINCT w.slug ORDER BY w.slug) AS works
             FROM lemma_gloss lg
             JOIN lemma_stats ls ON ls.lemma = lg.lemma
             JOIN token t ON t.lemma = lg.lemma AND t.is_word
             JOIN segment s ON s.id = t.segment_id
             JOIN work w ON w.id = s.work_id
             WHERE {$where}
             GROUP BY lg.number, lg.lemma, lg.gloss_nl, ls.freq
             ORDER BY lg.number ASC
             LIMIT :limit OFFSET :offset",
            $params,
            $types
        );

        return array_map(
            fn($r) => [
                'number'   => (int) $r['number'],
                'lemma'    => $r['lemma'],
                'gloss_nl' => $r['gloss_nl'],
                'freq'     => (int) $r['freq'],
                'works'    => $this->sortWorks($this->parsePgArray($r['works'])),
            ],
            $rows
        );
    }

    /** The lemma string for a given library number, or null if unassigned. */
    public function getLemmaByNumber(int $number): ?string
    {
        $lemma = $this->connection->fetchOne(
            'SELECT lemma FROM lemma_gloss WHERE number = :number',
            ['number' => $number]
        );
        return $lemma === false ? null : $lemma;
    }

    /**
     * Full gloss + library number for one lemma, for the detail page.
     * gloss_alt unwrapped the same way as InstitutioRepository::getLemmaGloss
     * (see that method's docblock for why).
     * @return array{number: ?int, gloss_nl: ?string, gloss_alt: array<int, string>, note: ?string, source: string, reviewed: bool}|null
     */
    public function getLemmaGloss(string $lemma): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT number, gloss_nl, array_to_string(gloss_alt, E'\x1F') AS gloss_alt, note, source, reviewed
             FROM lemma_gloss WHERE lemma = :lemma",
            ['lemma' => $lemma]
        );
        if ($row === false) {
            return null;
        }
        return [
            'number'    => $row['number'] !== null ? (int) $row['number'] : null,
            'gloss_nl'  => $row['gloss_nl'],
            'gloss_alt' => $row['gloss_alt'] !== null && $row['gloss_alt'] !== ''
                ? explode("\x1F", $row['gloss_alt']) : [],
            'note'      => $row['note'],
            'source'    => $row['source'],
            'reviewed'  => (bool) $row['reviewed'],
        ];
    }

    /**
     * Distinct word-form variants (norm + morphology) for a set of lemmas,
     * across every work -- same shape as InstitutioRepository's version,
     * just without the work_id filter.
     * @param array<int, string> $lemmas
     * @return array<string, array<int, array{norm: string, morph: ?string, freq: int}>> keyed by lemma
     */
    public function getLemmaVariants(array $lemmas): array
    {
        if (!$lemmas) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT lemma, norm, morph, count(*) AS freq
             FROM token
             WHERE is_word AND lemma IN (:lemmas)
             GROUP BY lemma, norm, morph
             ORDER BY lemma, freq DESC, norm ASC',
            ['lemmas' => $lemmas],
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
     * Every occurrence of one lemma across all works, most frequent lemma
     * potentially running into the thousands -- paginated. Each occurrence
     * carries its work's slug/title (unlike InstitutioRepository's version,
     * where the work is implicit) plus a KWIC-style context window, so the
     * library detail page can show provenance across all 4 works at a
     * glance without a separate lookup per row.
     * @return array<int, array{
     *   work_slug: string, work_title: string, ref: string,
     *   book: ?int, chapter: ?int, section: ?int,
     *   context_before: string, context_word: string, context_after: string,
     *   truncated_before: bool, truncated_after: bool
     * }>
     */
    public function getLemmaOccurrences(string $lemma, int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT w.slug AS work_slug, w.title AS work_title,
                    s.ref, s.book, s.chapter, s.section, s.text_la, t.char_start, t.char_end
             FROM token t
             JOIN segment s ON s.id = t.segment_id
             JOIN work w ON w.id = s.work_id
             WHERE t.is_word AND t.lemma = :lemma
             ORDER BY w.slug, s.seq, t.position
             LIMIT :limit OFFSET :offset',
            ['lemma' => $lemma, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER]
        );

        $window = 45;
        return array_map(function ($r) use ($window) {
            $start = (int) $r['char_start'];
            $end = (int) $r['char_end'];
            $beforeStart = max(0, $start - $window);
            return [
                'work_slug'        => $r['work_slug'],
                'work_title'       => $r['work_title'],
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

    /** Postgres text[] literal (e.g. '{a,b}') -> PHP array. array_agg output here is always simple slugs (no quoting needed). */
    private function parsePgArray(?string $literal): array
    {
        if ($literal === null || $literal === '{}') {
            return [];
        }
        return explode(',', trim($literal, '{}'));
    }

    /** Reorders work slugs per self::WORK_ORDER; unknown slugs sort after the known ones. */
    private function sortWorks(array $works): array
    {
        usort($works, fn($a, $b) => (self::WORK_ORDER[$a] ?? PHP_INT_MAX) <=> (self::WORK_ORDER[$b] ?? PHP_INT_MAX));
        return $works;
    }
}
