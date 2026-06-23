<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * LinkingRepository
 * All queries needed for the manual word-linking UI screens.
 */
class LinkingRepository
{
    public function __construct(private readonly Connection $connection) {}

    // ── Screen 1: passage linking ─────────────────────────────────────────────

    /**
     * Full passage data for linking: source words + dutch words with current links.
     */
    public function fetchPassageForLinking(int $bookId, int $chapter, int $verse, int $translationId): array
    {
        $testament = $bookId <= 39 ? 'OT' : 'NT';

        $sourceWords = $testament === 'OT'
            ? $this->fetchHebrewForLinking($bookId, $chapter, $verse, $translationId)
            : $this->fetchGreekForLinking($bookId, $chapter, $verse, $translationId);

        $dutchWords = $this->fetchDutchForLinking($bookId, $chapter, $verse, $translationId);

        return [
            'testament'    => $testament,
            'source_words' => $sourceWords,
            'dutch_words'  => $dutchWords,
        ];
    }

    private function fetchHebrewForLinking(int $bookId, int $chapter, int $verse, int $translationId): array
    {
        $sql = <<<SQL
            SELECT
                hw.id,
                hw.word_position,
                hw.word_text,
                hw.transliteration,
                regexp_replace(
                    CASE WHEN hw.strongs ~ '^[HGhg]'
                         THEN regexp_replace(hw.strongs, '[A-Za-z]+$', '')
                         ELSE 'H' || regexp_replace(hw.strongs, '[A-Za-z]+$', '')
                    END,
                    '^([HG])0+(\d)', '\\1\\2'
                ) AS strongs,
                hw.morph_code,
                COALESCE(
                    json_agg(
                        json_build_object(
                            'link_id', wl.id,
                            'tw_id',   tw.id,
                            'method',  lc_best.method,
                            'score',   lc_best.score
                        ) ORDER BY tw.word_position
                    ) FILTER (WHERE wl.id IS NOT NULL AND tv.id IS NOT NULL),
                    '[]'
                ) AS links,
                CASE WHEN mel.id IS NOT NULL THEN 1 ELSE 0 END AS is_manually_empty
            FROM hebrew_words hw
            LEFT JOIN word_links wl         ON wl.hebrew_word_id = hw.id
            LEFT JOIN translation_words tw  ON tw.id = wl.translation_word_id
            LEFT JOIN translation_verses tv ON tv.id = tw.verse_id
                                           AND tv.translation_id = :translation_id
            LEFT JOIN LATERAL (
                SELECT method, score FROM link_confidence
                WHERE link_id = wl.id
                ORDER BY score DESC LIMIT 1
            ) lc_best ON true
            LEFT JOIN manual_empty_links mel ON mel.hebrew_word_id = hw.id
                                            AND mel.translation_id = :translation_id
            WHERE hw.book_id = :book_id
              AND hw.chapter = :chapter
              AND hw.verse   = :verse
            GROUP BY hw.id, mel.id
            ORDER BY hw.word_position
        SQL;

        return $this->fetchWithJsonLinks($sql, $bookId, $chapter, $verse, $translationId);
    }

    private function fetchGreekForLinking(int $bookId, int $chapter, int $verse, int $translationId): array
    {
        $sql = <<<SQL
            SELECT
                gw.id,
                gw.word_position,
                gw.word_text,
                gw.transliteration,
                regexp_replace(
                    CASE WHEN gw.strongs ~ '^[HGhg]'
                         THEN regexp_replace(gw.strongs, '[A-Za-z]+$', '')
                         ELSE 'G' || regexp_replace(gw.strongs, '[A-Za-z]+$', '')
                    END,
                    '^([HG])0+(\d)', '\\1\\2'
                ) AS strongs,
                gw.parse_code,
                COALESCE(
                    json_agg(
                        json_build_object(
                            'link_id', wl.id,
                            'tw_id',   tw.id,
                            'method',  lc_best.method,
                            'score',   lc_best.score
                        ) ORDER BY tw.word_position
                    ) FILTER (WHERE wl.id IS NOT NULL AND tv.id IS NOT NULL),
                    '[]'
                ) AS links,
                CASE WHEN mel.id IS NOT NULL THEN 1 ELSE 0 END AS is_manually_empty
            FROM greek_words gw
            LEFT JOIN word_links wl         ON wl.greek_word_id = gw.id
            LEFT JOIN translation_words tw  ON tw.id = wl.translation_word_id
            LEFT JOIN translation_verses tv ON tv.id = tw.verse_id
                                           AND tv.translation_id = :translation_id
            LEFT JOIN LATERAL (
                SELECT method, score FROM link_confidence
                WHERE link_id = wl.id
                ORDER BY score DESC LIMIT 1
            ) lc_best ON true
            LEFT JOIN manual_empty_links mel ON mel.greek_word_id = gw.id
                                            AND mel.translation_id = :translation_id
            WHERE gw.book_id = :book_id
              AND gw.chapter = :chapter
              AND gw.verse   = :verse
            GROUP BY gw.id, mel.id
            ORDER BY gw.word_position
        SQL;

        return $this->fetchWithJsonLinks($sql, $bookId, $chapter, $verse, $translationId);
    }

    private function fetchWithJsonLinks(string $sql, int $bookId, int $chapter, int $verse, int $translationId): array
    {
        $rows = $this->connection->fetchAllAssociative($sql, [
            'book_id'        => $bookId,
            'chapter'        => $chapter,
            'verse'          => $verse,
            'translation_id' => $translationId,
        ]);

        return array_map(fn($row) => array_merge($row, [
            'links' => json_decode($row['links'], true),
        ]), $rows);
    }

    private function fetchDutchForLinking(int $bookId, int $chapter, int $verse, int $translationId): array
    {
        $sql = <<<SQL
            SELECT
                tw.id,
                tw.word_position,
                tw.word_text,
                tw.char_start,
                tw.char_end,
                tw.is_filler::int AS is_filler,
                tv.verse_text,
                -- All links pointing to this Dutch word
                COALESCE(
                    json_agg(
                        json_build_object(
                            'link_id',      wl.id,
                            'source_lang',  wl.source_language,
                            'he_word_id',   wl.hebrew_word_id,
                            'gk_word_id',   wl.greek_word_id,
                            'method',       lc_best.method,
                            'score',        lc_best.score
                        )
                    ) FILTER (WHERE wl.id IS NOT NULL),
                    '[]'
                ) AS links
            FROM translation_words tw
            JOIN translation_verses tv ON tw.verse_id = tv.id
            LEFT JOIN word_links wl ON wl.translation_word_id = tw.id
            LEFT JOIN LATERAL (
                SELECT method, score FROM link_confidence
                WHERE link_id = wl.id
                ORDER BY score DESC LIMIT 1
            ) lc_best ON true
            WHERE tv.translation_id = :translation_id
              AND tv.book_id = :book_id
              AND tv.chapter = :chapter
              AND tv.verse   = :verse
            GROUP BY tw.id, tv.verse_text
            ORDER BY tw.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'translation_id' => $translationId,
            'book_id'        => $bookId,
            'chapter'        => $chapter,
            'verse'          => $verse,
        ]);

        $rows = array_map(fn($row) => array_merge($row, [
            'links' => json_decode($row['links'], true),
        ]), $rows);

        return self::addPunctuation($rows);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Compute punct_after by scanning verse_text in word order.
     * For each word, find it at/after the current scan position and collect
     * any non-word, non-space characters that immediately follow it.
     */
    private static function addPunctuation(array $rows): array
    {
        if (empty($rows)) {
            return $rows;
        }
        $verseText = $rows[0]['verse_text'] ?? '';
        $verseLen  = mb_strlen($verseText);
        $scanPos   = 0;

        foreach ($rows as &$row) {
            $word    = $row['word_text'];
            $wordLen = mb_strlen($word);
            $found   = mb_strpos($verseText, $word, $scanPos);

            if ($found === false) {
                $row['punct_after'] = '';
                continue;
            }

            $after = $found + $wordLen;
            $punct = '';
            while ($after < $verseLen) {
                $ch = mb_substr($verseText, $after, 1);
                if ($ch === ' ' || preg_match('/\w/u', $ch)) {
                    break;
                }
                $punct .= $ch;
                $after++;
            }

            $row['punct_after'] = $punct;
            $scanPos = $found + $wordLen;
        }
        unset($row);
        return $rows;
    }

    // ── Screen 2: Strong's linking ────────────────────────────────────────────

    /**
     * All transliterations for a Strong's number, ordered by occurrence count.
     */
    public function fetchStrongsTransliterations(string $strongs): array
    {
        $testament = str_starts_with($strongs, 'H') ? 'OT' : 'NT';
        $table     = $testament === 'OT' ? 'hebrew_words' : 'greek_words';

        $sql = <<<SQL
            SELECT
                transliteration,
                COUNT(*) AS occurrence_count
            FROM {$table}
            WHERE regexp_replace(strongs, '[A-Za-z]+$', '') = :strongs
              AND transliteration IS NOT NULL
              AND transliteration <> ''
            GROUP BY transliteration
            ORDER BY occurrence_count DESC, transliteration
        SQL;

        return $this->connection->fetchAllAssociative($sql, ['strongs' => $this->padStrongsId($strongs)]);
    }

    /**
     * Count distinct verses containing a Strong's number (for pagination).
     */
    public function countStrongsVerses(string $strongs): int
    {
        $table = str_starts_with($strongs, 'H') ? 'hebrew_words' : 'greek_words';

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT book_id || '-' || chapter || '-' || verse)
             FROM {$table}
             WHERE regexp_replace(strongs, '[A-Za-z]+$', '') = :strongs",
            ['strongs' => $this->padStrongsId($strongs)]
        );
    }

    /**
     * Verses containing a Strong's number, with source + Dutch words for linking.
     * Returns one page of results.
     */
    public function fetchStrongsVerses(string $strongs, int $translationId, int $page = 1, int $perPage = 30): array
    {
        $testament = str_starts_with($strongs, 'H') ? 'OT' : 'NT';
        $table     = $testament === 'OT' ? 'hebrew_words' : 'greek_words';
        $offset    = ($page - 1) * $perPage;

        $sql = <<<SQL
            SELECT DISTINCT
                sw.book_id,
                sw.chapter,
                sw.verse,
                b.name_nl  AS book_name,
                b.usfm_code
            FROM {$table} sw
            JOIN books b ON b.id = sw.book_id
            WHERE regexp_replace(sw.strongs, '[A-Za-z]+$', '') = :strongs
            ORDER BY sw.book_id, sw.chapter, sw.verse
            LIMIT :limit OFFSET :offset
        SQL;

        $verses = $this->connection->fetchAllAssociative($sql, [
            'strongs' => $this->padStrongsId($strongs),
            'limit'   => $perPage,
            'offset'  => $offset,
        ]);

        $result = [];
        foreach ($verses as $v) {
            $passage = $testament === 'OT'
                ? $this->fetchHebrewForLinking($v['book_id'], $v['chapter'], $v['verse'], $translationId)
                : $this->fetchGreekForLinking($v['book_id'], $v['chapter'], $v['verse'], $translationId);

            $dutch = $this->fetchDutchForLinking($v['book_id'], $v['chapter'], $v['verse'], $translationId);

            $result[] = [
                'book_id'      => $v['book_id'],
                'chapter'      => $v['chapter'],
                'verse'        => $v['verse'],
                'book_name'    => $v['book_name'],
                'usfm_code'    => $v['usfm_code'],
                'testament'    => $testament,
                'source_words' => $passage,
                'dutch_words'  => $dutch,
            ];
        }

        return $result;
    }

    /**
     * Progress: how many words with this Strong's have at least one manual link.
     */
    public function fetchStrongsProgress(string $strongs, int $translationId): array
    {
        $testament = str_starts_with($strongs, 'H') ? 'OT' : 'NT';
        $table     = $testament === 'OT' ? 'hebrew_words' : 'greek_words';
        $id_col    = $testament === 'OT' ? 'hebrew_word_id' : 'greek_word_id';

        $sql = <<<SQL
            SELECT
                COUNT(DISTINCT sw.id)                                          AS total,
                COUNT(DISTINCT wl.{$id_col})                                   AS linked,
                COUNT(DISTINCT CASE WHEN lc.method = 'manual'
                    THEN wl.{$id_col} END)                                     AS manual
            FROM {$table} sw
            LEFT JOIN word_links wl         ON wl.{$id_col} = sw.id
            LEFT JOIN translation_words tw  ON tw.id = wl.translation_word_id
            LEFT JOIN translation_verses tv ON tv.id = tw.verse_id
                                           AND tv.translation_id = :translation_id
            LEFT JOIN link_confidence lc    ON lc.link_id = wl.id
            WHERE regexp_replace(sw.strongs, '[A-Za-z]+$', '') = :strongs
        SQL;

        return $this->connection->fetchAssociative($sql, [
            'strongs'        => $this->padStrongsId($strongs),
            'translation_id' => $translationId,
        ]);
    }

    // ── Link mutation helpers (used by LinkingController) ─────────────────────

    /**
     * Create a manual link between a source word and one or more Dutch words,
     * OR record that the source word intentionally has no Dutch translation.
     *
     * When $twIds is empty the word is saved as "manually confirmed: no link",
     * stored in manual_empty_links. Existing word_links for this source word
     * within the given translation are removed either way.
     */
    public function saveManualLinks(string $lang, int $sourceWordId, array $twIds, int $translationId): void
    {
        $idCol = $lang === 'HE' ? 'hebrew_word_id' : 'greek_word_id';

        // Delete existing word_links for this source word scoped to this translation.
        // (word_links → translation_words → translation_verses.translation_id)
        $this->connection->executeStatement(
            "DELETE FROM word_links wl
             WHERE wl.{$idCol} = :src_id
               AND EXISTS (
                   SELECT 1
                   FROM translation_words tw
                   JOIN translation_verses tv ON tv.id = tw.verse_id
                   WHERE tw.id = wl.translation_word_id
                     AND tv.translation_id = :translation_id
               )",
            ['src_id' => $sourceWordId, 'translation_id' => $translationId]
        );

        if (empty($twIds)) {
            // ── "No translation" path ────────────────────────────────────────
            // Upsert a manual_empty_links record (composite unique index handles dedup).
            $this->connection->executeStatement(
                "INSERT INTO manual_empty_links (source_language, {$idCol}, translation_id, created_at)
                 VALUES (:lang, :src_id, :translation_id, NOW())
                 ON CONFLICT ({$idCol}, translation_id) WHERE {$idCol} IS NOT NULL
                 DO UPDATE SET created_at = NOW()",
                ['lang' => $lang, 'src_id' => $sourceWordId, 'translation_id' => $translationId]
            );
        } else {
            // ── Normal linking path ──────────────────────────────────────────
            // Remove any previous "no translation" annotation for this word + translation.
            $this->connection->executeStatement(
                "DELETE FROM manual_empty_links WHERE {$idCol} = :src_id AND translation_id = :translation_id",
                ['src_id' => $sourceWordId, 'translation_id' => $translationId]
            );

            // Insert new links with manual confidence.
            // ON CONFLICT … DO UPDATE (no-op update) ensures RETURNING always gives us the id,
            // whether this is a fresh row or an already-existing one.
            foreach ($twIds as $twId) {
                $linkId = $this->connection->fetchOne(
                    "INSERT INTO word_links (source_language, {$idCol}, translation_word_id)
                     VALUES (:lang, :src_id, :tw_id)
                     ON CONFLICT ({$idCol}, translation_word_id) WHERE {$idCol} IS NOT NULL
                     DO UPDATE SET source_language = EXCLUDED.source_language
                     RETURNING id",
                    ['lang' => $lang, 'src_id' => $sourceWordId, 'tw_id' => (int) $twId]
                );

                $this->connection->executeStatement(
                    "INSERT INTO link_confidence (link_id, method, score, created_at)
                     VALUES (:link_id, 'manual', 1.000, NOW())
                     ON CONFLICT (link_id, method) DO UPDATE SET score = 1.000, created_at = NOW()",
                    ['link_id' => $linkId]
                );
            }
        }
    }

    /**
     * Delete a specific word link by id.
     */
    public function deleteLink(int $linkId): void
    {
        $this->connection->executeStatement(
            "DELETE FROM word_links WHERE id = :id",
            ['id' => $linkId]
        );
    }

    /**
     * Check whether a source word ID exists in the appropriate language table.
     * Used to validate input before writing links.
     */
    public function sourceWordExists(string $lang, int $id): bool
    {
        $table = $lang === 'HE' ? 'hebrew_words' : 'greek_words';
        return (bool) $this->connection->fetchOne(
            "SELECT 1 FROM {$table} WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * Return the Strong's dictionary entry for a given Strong's number,
     * or null if the strongs_entries table has not been populated yet.
     *
     * @return array{strongs_id:string, lang:string, lemma:string|null,
     *               transliteration:string|null, pronunciation:string|null,
     *               pos:string|null, morph:string|null, definition:string|null,
     *               etymology:string|null, kjv_renderings:string|null,
     *               short_def:string|null}|null
     */
    public function fetchStrongsEntry(string $strongs): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT strongs_id, lang, lemma, transliteration, pronunciation,
                    pos, morph, definition, etymology, kjv_renderings, short_def,
                    definition_nl, etymology_nl, short_def_nl
             FROM strongs_entries
             WHERE strongs_id = :id",
            ['id' => $this->normalizeStrongsId($strongs)]
        );

        return $row ?: null;
    }

    /**
     * Normalize a Strong's number to the canonical form stored in strongs_entries:
     *   prefix letter (H or G) + integer with no leading zeros.
     *
     * Handles:
     *   H7225G  → H7225   (TAHOT trailing variant letter)
     *   H0853   → H853    (leading zeros in hebrew_words)
     *   G0037   → G37     (leading zeros in greek_words)
     */
    private function normalizeStrongsId(string $strongs): string
    {
        $strongs = strtoupper(trim($strongs));

        // Strip trailing non-digit suffix (e.g. 'G' in H7225G)
        $strongs = (string) preg_replace('/^([HG]\d+)[A-Z]+$/', '$1', $strongs);

        // Strip leading zeros from the numeric part
        $strongs = (string) preg_replace('/^([HG])0+(\d)/', '$1$2', $strongs);

        return $strongs;
    }

    /**
     * Convert a canonical Strong's ID (e.g. 'H1', 'H853') to the zero-padded
     * 4-digit format stored in hebrew_words / greek_words by parse_tahot.py
     * (e.g. 'H0001', 'H0853').  Suffix letters (H5921A) are preserved.
     */
    private function padStrongsId(string $strongs): string
    {
        $strongs = strtoupper(trim($strongs));
        if (preg_match('/^([HG])(\d+)([A-Z]?)$/', $strongs, $m)) {
            return $m[1] . str_pad($m[2], 4, '0', STR_PAD_LEFT) . $m[3];
        }
        return $strongs;
    }

    /**
     * Save Dutch translation fields for a Strong's entry.
     */
    public function saveStrongsTranslation(string $strongs, ?string $shortDefNl, ?string $definitionNl, ?string $etymologyNl): void
    {
        $this->connection->executeStatement(
            "UPDATE strongs_entries
             SET short_def_nl  = :short_def_nl,
                 definition_nl = :definition_nl,
                 etymology_nl  = :etymology_nl
             WHERE strongs_id  = :id",
            [
                'id'           => $this->normalizeStrongsId($strongs),
                'short_def_nl' => $shortDefNl ?: null,
                'definition_nl'=> $definitionNl ?: null,
                'etymology_nl' => $etymologyNl ?: null,
            ]
        );
    }

    // ── Propagation helpers for non-authority translations ────────────────────

    /**
     * Return the source-language authority translation ID for the same family
     * as $translationId, or null if $translationId IS the authority (or has no family).
     */
    public function findAuthorityTranslationId(int $translationId): ?int
    {
        $id = $this->connection->fetchOne(
            "SELECT ta.id
             FROM translations ta
             JOIN translations tb ON tb.family = ta.family AND tb.id != ta.id
             WHERE ta.source_lang_authority = TRUE
               AND tb.id = :translation_id",
            ['translation_id' => $translationId]
        );

        return $id ? (int) $id : null;
    }

    /**
     * Fetch passage for linking, augmenting source words that have no direct
     * word_links with propagated suggestions from inter_translation_links.
     *
     * Used when the selected translation is not the source-language authority
     * (e.g. HSV). Propagated suggestions are shown as pre-selected Dutch words
     * so the user can confirm them with a single Save click.
     *
     * link_id is NULL for suggestions (no real word_links row yet).
     */
    public function fetchPassageForLinkingWithPropagation(
        int $bookId, int $chapter, int $verse,
        int $translationId, int $authorityTranslationId
    ): array {
        $passage = $this->fetchPassageForLinking($bookId, $chapter, $verse, $translationId);

        // Get propagated links indexed by source_word_id
        $testament   = $bookId <= 39 ? 'OT' : 'NT';
        $sourceIdCol = $testament === 'OT' ? 'hebrew_word_id' : 'greek_word_id';

        $sql = <<<SQL
            SELECT
                wl_auth.{$sourceIdCol} AS source_word_id,
                tw_t.id                AS tw_id,
                tw_t.word_position,
                itl.method,
                itl.confidence
            FROM word_links wl_auth
            JOIN translation_words tw_auth  ON tw_auth.id = wl_auth.translation_word_id
            JOIN translation_verses tv_auth ON tv_auth.id = tw_auth.verse_id
                AND tv_auth.translation_id = :authority_id
                AND tv_auth.book_id        = :book_id
                AND tv_auth.chapter        = :chapter
                AND tv_auth.verse          = :verse
            JOIN inter_translation_links itl
                ON (itl.word_a_id = tw_auth.id OR itl.word_b_id = tw_auth.id)
               AND itl.method != 'manual_empty'
            JOIN translation_words tw_t
                ON tw_t.id = CASE
                    WHEN itl.word_a_id = tw_auth.id THEN itl.word_b_id
                    ELSE itl.word_a_id END
            JOIN translation_verses tv_t ON tv_t.id = tw_t.verse_id
                AND tv_t.translation_id = :target_id
            ORDER BY wl_auth.{$sourceIdCol}, tw_t.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'authority_id' => $authorityTranslationId,
            'target_id'    => $translationId,
            'book_id'      => $bookId,
            'chapter'      => $chapter,
            'verse'        => $verse,
        ]);

        // Group propagated rows by source_word_id
        $propagated = [];
        foreach ($rows as $row) {
            $srcId              = (int) $row['source_word_id'];
            $propagated[$srcId][] = [
                'link_id'    => null,   // not a real word_links row yet
                'tw_id'      => (int) $row['tw_id'],
                'method'     => $row['method'],
                'score'      => $row['confidence'] !== null
                                    ? (float) $row['confidence'] / 100.0
                                    : null,
            ];
        }

        // Augment source words that have no direct links
        foreach ($passage['source_words'] as &$word) {
            if (empty($word['links'])) {
                $word['links']       = $propagated[(int) $word['id']] ?? [];
                $word['propagated']  = !empty($word['links']);   // flag for template
            } else {
                $word['propagated']  = false;
            }
        }
        unset($word);

        // Also populate dutch_words link badges from propagated data
        // (reverse map: tw_id → source_word_id list)
        $twToSrc = [];
        foreach ($propagated as $srcId => $links) {
            foreach ($links as $lnk) {
                $twToSrc[$lnk['tw_id']][] = $srcId;
            }
        }
        foreach ($passage['dutch_words'] as &$dw) {
            if (empty($dw['links'])) {
                $twId = (int) $dw['id'];
                if (isset($twToSrc[$twId])) {
                    foreach ($twToSrc[$twId] as $srcId) {
                        $dw['links'][] = [
                            'link_id'     => null,
                            'source_lang' => $testament === 'OT' ? 'HE' : 'GR',
                            'he_word_id'  => $testament === 'OT' ? $srcId : null,
                            'gk_word_id'  => $testament === 'NT' ? $srcId : null,
                            'method'      => 'propagated',
                            'score'       => null,
                        ];
                    }
                }
            }
        }
        unset($dw);

        return $passage;
    }

    // ── Inter-translation linking ──────────────────────────────────────────────

    /**
     * All translation pairs in the same family, with progress stats.
     */
    public function fetchTranslationPairs(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT
                ta.id   AS id_a,  ta.code  AS code_a,  ta.name AS name_a,
                tb.id   AS id_b,  tb.code  AS code_b,  tb.name AS name_b,
                ta.family
             FROM translations ta
             JOIN translations tb ON tb.family = ta.family AND tb.id != ta.id
             WHERE ta.source_lang_authority = TRUE
             ORDER BY ta.family, tb.code"
        );
    }

    /**
     * Progress for an inter-translation pair: verses linked vs total.
     */
    public function fetchInterTranslationProgress(int $translationAId, int $translationBId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(DISTINCT tv_a.id) AS total_verses,
                COUNT(DISTINCT CASE
                    WHEN EXISTS (
                        SELECT 1 FROM inter_translation_links itl
                        JOIN translation_words tw_a ON tw_a.id IN (itl.word_a_id, itl.word_b_id)
                        JOIN translation_verses tv_aa ON tv_aa.id = tw_a.verse_id
                            AND tv_aa.id = tv_a.id
                        LIMIT 1
                    ) THEN tv_a.id END
                ) AS linked_verses
             FROM translation_verses tv_a
             WHERE tv_a.translation_id = :id_a
               AND EXISTS (
                   SELECT 1 FROM translation_verses tv_b
                   WHERE tv_b.translation_id = :id_b
                     AND tv_b.book_id  = tv_a.book_id
                     AND tv_b.chapter  = tv_a.chapter
                     AND tv_b.verse    = tv_a.verse
               )",
            ['id_a' => $translationAId, 'id_b' => $translationBId]
        );

        // Simpler word-level stats
        $words = $this->connection->fetchAssociative(
            "SELECT
                COUNT(DISTINCT tw_a.id) AS total_words_a,
                COUNT(DISTINCT CASE
                    WHEN itl.id IS NOT NULL AND itl.method != 'manual_empty'
                    THEN tw_a.id END
                ) AS linked_words_a,
                COUNT(DISTINCT CASE WHEN itl.method = 'manual' THEN tw_a.id END) AS manual_words_a
             FROM translation_words tw_a
             JOIN translation_verses tv_a ON tv_a.id = tw_a.verse_id
                AND tv_a.translation_id = :id_a
             LEFT JOIN inter_translation_links itl
                ON (itl.word_a_id = tw_a.id OR itl.word_b_id = tw_a.id)",
            ['id_a' => $translationAId]
        );

        return array_merge($row ?: [], $words ?: []);
    }

    /**
     * Fetch verse data for both translations plus existing ITL links for the verse.
     */
    public function fetchInterTranslationVerseData(
        int $bookId, int $chapter, int $verse,
        int $translationAId, int $translationBId
    ): array {
        $wordsA = $this->fetchDutchForLinkingWithFiller($bookId, $chapter, $verse, $translationAId);
        $wordsB = $this->fetchDutchForLinkingWithFiller($bookId, $chapter, $verse, $translationBId);

        // Collect word IDs for both sides
        $idsA = array_column($wordsA, 'id');
        $idsB = array_column($wordsB, 'id');

        $links = [];
        if ($idsA && $idsB) {
            $links = $this->fetchItlLinks($idsA, $idsB);
        }

        // Build word_id → word_text map with string keys so Twig can look up by ID.
        // (PHP array_merge renumbers integer keys, so we must use string keys here.)
        $wordMap = [];
        foreach ($wordsA as $w) {
            $wordMap[(string) $w['id']] = $w['word_text'];
        }
        foreach ($wordsB as $w) {
            $wordMap[(string) $w['id']] = $w['word_text'];
        }

        return [
            'words_a'  => $wordsA,
            'words_b'  => $wordsB,
            'links'    => $links,
            'word_map' => $wordMap,
        ];
    }

    private function fetchDutchForLinkingWithFiller(int $bookId, int $chapter, int $verse, int $translationId): array
    {
        $sql = <<<SQL
            SELECT
                tw.id,
                tw.word_position,
                tw.word_text,
                tw.is_filler,
                tv.verse_text
            FROM translation_words tw
            JOIN translation_verses tv ON tw.verse_id = tv.id
            WHERE tv.translation_id = :translation_id
              AND tv.book_id = :book_id
              AND tv.chapter = :chapter
              AND tv.verse   = :verse
            ORDER BY tw.word_position
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            'translation_id' => $translationId,
            'book_id'        => $bookId,
            'chapter'        => $chapter,
            'verse'          => $verse,
        ]);
    }

    private function fetchItlLinks(array $idsA, array $idsB): array
    {
        if (!$idsA || !$idsB) return [];

        // Build safe integer lists (no user input, internal IDs)
        $listA = implode(',', array_map('intval', $idsA));
        $listB = implode(',', array_map('intval', $idsB));

        return $this->connection->fetchAllAssociative(
            "SELECT id, word_a_id, word_b_id, method, confidence
             FROM inter_translation_links
             WHERE (word_a_id IN ({$listA}) AND word_b_id IN ({$listB}))
                OR (word_a_id IN ({$listB}) AND word_b_id IN ({$listA}))"
        );
    }

    /**
     * Save an inter-translation link (word_a_id < word_b_id enforced).
     */
    public function saveInterTranslationLink(int $wordAId, int $wordBId, string $method = 'manual', ?int $confidence = null): void
    {
        // Enforce ordering constraint
        [$a, $b] = $wordAId < $wordBId ? [$wordAId, $wordBId] : [$wordBId, $wordAId];

        $this->connection->executeStatement(
            "INSERT INTO inter_translation_links (word_a_id, word_b_id, method, confidence)
             VALUES (:a, :b, :method, :confidence)
             ON CONFLICT (word_a_id, word_b_id) DO UPDATE
                SET method = EXCLUDED.method,
                    confidence = CASE WHEN EXCLUDED.confidence IS NULL THEN NULL ELSE EXCLUDED.confidence END",
            ['a' => $a, 'b' => $b, 'method' => $method, 'confidence' => $confidence]
        );
    }

    /**
     * Delete an inter-translation link by its two word IDs.
     */
    public function deleteInterTranslationLink(int $wordAId, int $wordBId): void
    {
        [$a, $b] = $wordAId < $wordBId ? [$wordAId, $wordBId] : [$wordBId, $wordAId];
        $this->connection->executeStatement(
            "DELETE FROM inter_translation_links WHERE word_a_id = :a AND word_b_id = :b",
            ['a' => $a, 'b' => $b]
        );
    }

    /**
     * Reset all auto-generated ITL links for a verse (keeps manual ones).
     * Supply the word IDs for both sides of the pair.
     */
    public function resetVerseAutoLinks(array $idsA, array $idsB): int
    {
        if (!$idsA || !$idsB) return 0;
        $listA = implode(',', array_map('intval', $idsA));
        $listB = implode(',', array_map('intval', $idsB));

        return (int) $this->connection->executeStatement(
            "DELETE FROM inter_translation_links
             WHERE method NOT IN ('manual', 'manual_empty')
               AND ((word_a_id IN ({$listA}) AND word_b_id IN ({$listB}))
                 OR (word_a_id IN ({$listB}) AND word_b_id IN ({$listA})))"
        );
    }


}
