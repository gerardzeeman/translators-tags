<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * PassageRepository
 * Uses raw DBAL queries for the complex multi-table passage fetch,
 * which is more efficient than Doctrine ORM for deeply nested joins.
 */
class PassageRepository
{
    public function __construct(
        private readonly Connection     $connection,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * Fetch all source words for a passage with their Dutch link data.
     * Returns a structured array ready for the Twig template.
     *
     * @return array{
     *   source_words: array,
     *   dutch_verse: array,
     *   testament: string
     * }
     */
    public function fetchPassage(int $bookId, int $chapter, int $verse, int $translationId): array
    {
        // Determine testament
        $testament = $bookId <= 39 ? 'OT' : 'NT';

        if ($testament === 'OT') {
            $sourceWords = $this->fetchHebrewWords($bookId, $chapter, $verse, $translationId);
        } else {
            $sourceWords = $this->fetchGreekWords($bookId, $chapter, $verse, $translationId);
        }

        $dutchVerse = $this->fetchDutchVerse($bookId, $chapter, $verse, $translationId);

        return [
            'testament'    => $testament,
            'source_words' => $sourceWords,
            'dutch_verse'  => $dutchVerse,
        ];
    }

    private function fetchHebrewWords(int $bookId, int $chapter, int $verse, int $translationId): array
    {
        $sql = <<<SQL
            SELECT
                hw.id,
                hw.word_position,
                hw.word_text,
                hw.transliteration,
                hw.lemma,
                hw.strongs,
                hw.morph_code,
                hw.is_ketiv,
                hw.has_qere,
                -- Best link per Dutch word (for this translation), sorted by Dutch word position
                COALESCE(
                    json_agg(
                        json_build_object(
                            'tw_id',       best.tw_id,
                            'word_text',   best.word_text,
                            'word_pos',    best.word_position,
                            'method',      best.method,
                            'score',       best.score
                        )
                        ORDER BY best.word_position ASC
                    ) FILTER (WHERE best.tw_id IS NOT NULL),
                    '[]'
                ) AS dutch_links
            FROM hebrew_words hw
            LEFT JOIN LATERAL (
                SELECT DISTINCT ON (tw.id)
                    tw.id              AS tw_id,
                    tw.word_text       AS word_text,
                    tw.word_position   AS word_position,
                    lc.method          AS method,
                    lc.score           AS score
                FROM word_links wl
                JOIN translation_words tw  ON tw.id = wl.translation_word_id
                JOIN translation_verses tv ON tv.id = tw.verse_id
                JOIN link_confidence lc    ON lc.link_id = wl.id
                WHERE wl.hebrew_word_id = hw.id
                  AND tv.translation_id = :translation_id
                ORDER BY tw.id, lc.score DESC
            ) best ON true
            WHERE hw.book_id = :book_id
              AND hw.chapter = :chapter
              AND hw.verse   = :verse
            GROUP BY hw.id
            ORDER BY hw.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'book_id'        => $bookId,
            'chapter'        => $chapter,
            'verse'          => $verse,
            'translation_id' => $translationId,
        ]);

        return array_map(fn($row) => array_merge($row, [
            'dutch_links' => json_decode($row['dutch_links'], true),
        ]), $rows);
    }

    private function fetchGreekWords(int $bookId, int $chapter, int $verse, int $translationId): array
    {
        $sql = <<<SQL
            SELECT
                gw.id,
                gw.word_position,
                gw.word_text,
                gw.lemma,
                gw.strongs,
                gw.parse_code,
                gw.transliteration,
                -- Best link per Dutch word (for this translation), sorted by Dutch word position
                COALESCE(
                    json_agg(
                        json_build_object(
                            'tw_id',       best.tw_id,
                            'word_text',   best.word_text,
                            'word_pos',    best.word_position,
                            'method',      best.method,
                            'score',       best.score
                        )
                        ORDER BY best.word_position ASC
                    ) FILTER (WHERE best.tw_id IS NOT NULL),
                    '[]'
                ) AS dutch_links
            FROM greek_words gw
            LEFT JOIN LATERAL (
                SELECT DISTINCT ON (tw.id)
                    tw.id              AS tw_id,
                    tw.word_text       AS word_text,
                    tw.word_position   AS word_position,
                    lc.method          AS method,
                    lc.score           AS score
                FROM word_links wl
                JOIN translation_words tw  ON tw.id = wl.translation_word_id
                JOIN translation_verses tv ON tv.id = tw.verse_id
                JOIN link_confidence lc    ON lc.link_id = wl.id
                WHERE wl.greek_word_id = gw.id
                  AND tv.translation_id = :translation_id
                ORDER BY tw.id, lc.score DESC
            ) best ON true
            WHERE gw.book_id = :book_id
              AND gw.chapter = :chapter
              AND gw.verse   = :verse
            GROUP BY gw.id
            ORDER BY gw.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'book_id'        => $bookId,
            'chapter'        => $chapter,
            'verse'          => $verse,
            'translation_id' => $translationId,
        ]);

        return array_map(fn($row) => array_merge($row, [
            'dutch_links' => json_decode($row['dutch_links'], true),
        ]), $rows);
    }

    private function fetchDutchVerse(int $bookId, int $chapter, int $verse, int $translationId): array
    {
        $sql = <<<SQL
            SELECT
                tv.id          AS verse_id,
                tv.verse_text,
                tw.id          AS word_id,
                tw.word_position,
                tw.word_text,
                tw.char_start,
                tw.char_end,
                tw.is_filler::int AS is_filler,
                COALESCE(wl_best.method, itl_best.method)            AS best_method,
                COALESCE(wl_best.score,  itl_best.score)             AS best_score
            FROM translation_verses tv
            JOIN translation_words tw ON tw.verse_id = tv.id
            LEFT JOIN LATERAL (
                SELECT lc2.method, lc2.score
                FROM word_links wl2
                JOIN link_confidence lc2 ON lc2.link_id = wl2.id
                WHERE wl2.translation_word_id = tw.id
                ORDER BY lc2.score DESC
                LIMIT 1
            ) wl_best ON true
            LEFT JOIN LATERAL (
                SELECT itl.method, itl.confidence::float / 100.0 AS score
                FROM inter_translation_links itl
                WHERE (itl.word_a_id = tw.id OR itl.word_b_id = tw.id)
                  AND itl.method != 'manual_empty'
                ORDER BY CASE itl.method
                    WHEN 'manual'            THEN 0
                    WHEN 'auto_source_pivot' THEN 1
                    WHEN 'auto_sequence'     THEN 2
                    ELSE 3 END
                LIMIT 1
            ) itl_best ON true
            WHERE tv.translation_id = :translation_id
              AND tv.book_id = :book_id
              AND tv.chapter = :chapter
              AND tv.verse   = :verse
            ORDER BY tw.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'translation_id' => $translationId,
            'book_id'        => $bookId,
            'chapter'        => $chapter,
            'verse'          => $verse,
        ]);

        return self::addPunctuation($rows);
    }

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

    /**
     * Batch version of fetchPassage: fetches all translations in 2 queries instead of 2N.
     * Returns [translationId => ['testament', 'source_words', 'dutch_verse']].
     *
     * @param int[] $translationIds
     */
    public function fetchPassageBatch(int $bookId, int $chapter, int $verse, array $translationIds): array
    {
        if (empty($translationIds)) {
            return [];
        }

        $testament = $bookId <= 39 ? 'OT' : 'NT';

        $sourceWordsByTrans = $testament === 'OT'
            ? $this->fetchHebrewWordsBatch($bookId, $chapter, $verse, $translationIds)
            : $this->fetchGreekWordsBatch($bookId, $chapter, $verse, $translationIds);

        $dutchVersesByTrans = $this->fetchDutchVerseBatch($bookId, $chapter, $verse, $translationIds);

        $result = [];
        foreach ($translationIds as $tid) {
            $result[$tid] = [
                'testament'    => $testament,
                'source_words' => $sourceWordsByTrans[$tid] ?? [],
                'dutch_verse'  => $dutchVersesByTrans[$tid]  ?? [],
            ];
        }
        return $result;
    }

    private function fetchHebrewWordsBatch(int $bookId, int $chapter, int $verse, array $translationIds): array
    {
        $baseSql = <<<SQL
            SELECT id, word_position, word_text, transliteration, lemma, strongs,
                   morph_code, is_ketiv, has_qere
            FROM hebrew_words
            WHERE book_id = :book_id AND chapter = :chapter AND verse = :verse
            ORDER BY word_position
        SQL;

        $baseRows = $this->connection->fetchAllAssociative($baseSql, [
            'book_id' => $bookId, 'chapter' => $chapter, 'verse' => $verse,
        ]);

        if (empty($baseRows)) {
            return array_fill_keys($translationIds, []);
        }

        $linkSql = <<<SQL
            SELECT DISTINCT ON (tv.translation_id, wl.hebrew_word_id, tw.id)
                wl.hebrew_word_id AS source_word_id,
                tv.translation_id,
                tw.id AS tw_id, tw.word_text, tw.word_position,
                lc.method, lc.score
            FROM word_links wl
            JOIN translation_words tw ON tw.id = wl.translation_word_id
            JOIN translation_verses tv ON tv.id = tw.verse_id
            JOIN link_confidence lc ON lc.link_id = wl.id
            WHERE tv.translation_id IN (:translation_ids)
              AND tv.book_id = :book_id
              AND tv.chapter = :chapter
              AND tv.verse   = :verse
            ORDER BY tv.translation_id, wl.hebrew_word_id, tw.id, lc.score DESC
        SQL;

        $linkRows = $this->connection->fetchAllAssociative($linkSql, [
            'translation_ids' => $translationIds,
            'book_id'         => $bookId,
            'chapter'         => $chapter,
            'verse'           => $verse,
        ], ['translation_ids' => ArrayParameterType::INTEGER]);

        $linksByTransAndWord = [];
        foreach ($linkRows as $row) {
            $linksByTransAndWord[$row['translation_id']][$row['source_word_id']][] = [
                'tw_id'     => $row['tw_id'],
                'word_text' => $row['word_text'],
                'word_pos'  => (int) $row['word_position'],
                'method'    => $row['method'],
                'score'     => $row['score'] !== null ? (float) $row['score'] : null,
            ];
        }

        $result = [];
        foreach ($translationIds as $tid) {
            $words = [];
            foreach ($baseRows as $row) {
                $links = $linksByTransAndWord[$tid][$row['id']] ?? [];
                usort($links, fn($a, $b) => $a['word_pos'] <=> $b['word_pos']);
                $words[] = array_merge($row, ['dutch_links' => $links]);
            }
            $result[$tid] = $words;
        }
        return $result;
    }

    private function fetchGreekWordsBatch(int $bookId, int $chapter, int $verse, array $translationIds): array
    {
        $baseSql = <<<SQL
            SELECT id, word_position, word_text, transliteration, lemma, strongs, parse_code
            FROM greek_words
            WHERE book_id = :book_id AND chapter = :chapter AND verse = :verse
            ORDER BY word_position
        SQL;

        $baseRows = $this->connection->fetchAllAssociative($baseSql, [
            'book_id' => $bookId, 'chapter' => $chapter, 'verse' => $verse,
        ]);

        if (empty($baseRows)) {
            return array_fill_keys($translationIds, []);
        }

        $linkSql = <<<SQL
            SELECT DISTINCT ON (tv.translation_id, wl.greek_word_id, tw.id)
                wl.greek_word_id AS source_word_id,
                tv.translation_id,
                tw.id AS tw_id, tw.word_text, tw.word_position,
                lc.method, lc.score
            FROM word_links wl
            JOIN translation_words tw ON tw.id = wl.translation_word_id
            JOIN translation_verses tv ON tv.id = tw.verse_id
            JOIN link_confidence lc ON lc.link_id = wl.id
            WHERE tv.translation_id IN (:translation_ids)
              AND tv.book_id = :book_id
              AND tv.chapter = :chapter
              AND tv.verse   = :verse
            ORDER BY tv.translation_id, wl.greek_word_id, tw.id, lc.score DESC
        SQL;

        $linkRows = $this->connection->fetchAllAssociative($linkSql, [
            'translation_ids' => $translationIds,
            'book_id'         => $bookId,
            'chapter'         => $chapter,
            'verse'           => $verse,
        ], ['translation_ids' => ArrayParameterType::INTEGER]);

        $linksByTransAndWord = [];
        foreach ($linkRows as $row) {
            $linksByTransAndWord[$row['translation_id']][$row['source_word_id']][] = [
                'tw_id'     => $row['tw_id'],
                'word_text' => $row['word_text'],
                'word_pos'  => (int) $row['word_position'],
                'method'    => $row['method'],
                'score'     => $row['score'] !== null ? (float) $row['score'] : null,
            ];
        }

        $result = [];
        foreach ($translationIds as $tid) {
            $words = [];
            foreach ($baseRows as $row) {
                $links = $linksByTransAndWord[$tid][$row['id']] ?? [];
                usort($links, fn($a, $b) => $a['word_pos'] <=> $b['word_pos']);
                $words[] = array_merge($row, ['dutch_links' => $links]);
            }
            $result[$tid] = $words;
        }
        return $result;
    }

    private function fetchDutchVerseBatch(int $bookId, int $chapter, int $verse, array $translationIds): array
    {
        $sql = <<<SQL
            SELECT
                tv.translation_id,
                tv.id  AS verse_id,
                tv.verse_text,
                tw.id  AS word_id,
                tw.word_position,
                tw.word_text,
                tw.char_start,
                tw.char_end,
                tw.is_filler::int AS is_filler,
                COALESCE(wl_best.method, itl_best.method) AS best_method,
                COALESCE(wl_best.score,  itl_best.score)  AS best_score
            FROM translation_verses tv
            JOIN translation_words tw ON tw.verse_id = tv.id
            LEFT JOIN LATERAL (
                SELECT lc2.method, lc2.score
                FROM word_links wl2
                JOIN link_confidence lc2 ON lc2.link_id = wl2.id
                WHERE wl2.translation_word_id = tw.id
                ORDER BY lc2.score DESC LIMIT 1
            ) wl_best ON true
            LEFT JOIN LATERAL (
                SELECT itl.method, itl.confidence::float / 100.0 AS score
                FROM inter_translation_links itl
                WHERE (itl.word_a_id = tw.id OR itl.word_b_id = tw.id)
                  AND itl.method != 'manual_empty'
                ORDER BY CASE itl.method
                    WHEN 'manual'            THEN 0
                    WHEN 'auto_source_pivot' THEN 1
                    WHEN 'auto_sequence'     THEN 2
                    ELSE 3 END
                LIMIT 1
            ) itl_best ON true
            WHERE tv.translation_id IN (:translation_ids)
              AND tv.book_id = :book_id
              AND tv.chapter = :chapter
              AND tv.verse   = :verse
            ORDER BY tv.translation_id, tw.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'translation_ids' => $translationIds,
            'book_id'         => $bookId,
            'chapter'         => $chapter,
            'verse'           => $verse,
        ], ['translation_ids' => ArrayParameterType::INTEGER]);

        $grouped = [];
        foreach ($rows as $row) {
            $tid = $row['translation_id'];
            unset($row['translation_id']);
            $grouped[$tid][] = $row;
        }

        $result = [];
        foreach ($grouped as $tid => $verseRows) {
            $result[$tid] = self::addPunctuation($verseRows);
        }
        return $result;
    }

    /**
     * Batch version of fetchPropagatedLinksForVerse for multiple target translations.
     * Returns [targetTranslationId => [sourceWordId => [links]]].
     *
     * @param int[] $targetTranslationIds
     */
    public function fetchPropagatedLinksForVerseBatch(
        int    $bookId,
        int    $chapter,
        int    $verse,
        string $testament,
        int    $authorityTranslationId,
        array  $targetTranslationIds,
    ): array {
        if (empty($targetTranslationIds)) {
            return [];
        }

        $sourceIdCol = $testament === 'OT' ? 'hebrew_word_id' : 'greek_word_id';

        $sql = <<<SQL
            WITH authority_links AS (
                SELECT wl.{$sourceIdCol} AS source_word_id, tw_auth.id AS auth_tw_id
                FROM word_links wl
                JOIN translation_words tw_auth ON tw_auth.id = wl.translation_word_id
                JOIN translation_verses tv_auth ON tv_auth.id = tw_auth.verse_id
                WHERE tv_auth.translation_id = :authority_id
                  AND tv_auth.book_id = :book_id
                  AND tv_auth.chapter = :chapter
                  AND tv_auth.verse   = :verse
            )
            SELECT
                al.source_word_id,
                tv_t.translation_id,
                tw_t.id AS tw_id, tw_t.word_text, tw_t.word_position,
                itl.method AS itl_method, itl.confidence
            FROM authority_links al
            JOIN inter_translation_links itl
                ON (itl.word_a_id = al.auth_tw_id OR itl.word_b_id = al.auth_tw_id)
               AND itl.method != 'manual_empty'
            JOIN translation_words tw_t
                ON tw_t.id = CASE
                    WHEN itl.word_a_id = al.auth_tw_id THEN itl.word_b_id
                    ELSE itl.word_a_id
                END
            JOIN translation_verses tv_t ON tv_t.id = tw_t.verse_id
                AND tv_t.translation_id IN (:target_ids)
            ORDER BY tv_t.translation_id, al.source_word_id, tw_t.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'authority_id' => $authorityTranslationId,
            'target_ids'   => $targetTranslationIds,
            'book_id'      => $bookId,
            'chapter'      => $chapter,
            'verse'        => $verse,
        ], ['target_ids' => ArrayParameterType::INTEGER]);

        $result = [];
        foreach ($rows as $row) {
            $tid   = $row['translation_id'];
            $srcId = $row['source_word_id'];
            $result[$tid][$srcId][] = [
                'tw_id'     => $row['tw_id'],
                'word_text' => $row['word_text'],
                'word_pos'  => (int) $row['word_position'],
                'method'    => $row['itl_method'],
                'score'     => $row['confidence'] !== null ? (float) $row['confidence'] / 100.0 : null,
            ];
        }
        return $result;
    }

    /**
     * Fetch source-language link propagation for a non-authority translation.
     *
     * Walks the chain: source_word → word_links → authority_tw → inter_translation_links → target_tw
     * Returns a map of source_word_id → [array of link arrays].
     */
    public function fetchPropagatedLinksForVerse(
        int    $bookId,
        int    $chapter,
        int    $verse,
        string $testament,
        int    $authorityTranslationId,
        int    $targetTranslationId,
    ): array {
        $sourceIdCol = $testament === 'OT' ? 'hebrew_word_id' : 'greek_word_id';

        $sql = <<<SQL
            WITH authority_links AS (
                SELECT
                    wl.{$sourceIdCol}   AS source_word_id,
                    tw_auth.id          AS auth_tw_id
                FROM word_links wl
                JOIN translation_words tw_auth  ON tw_auth.id = wl.translation_word_id
                JOIN translation_verses tv_auth ON tv_auth.id = tw_auth.verse_id
                WHERE tv_auth.translation_id = :authority_id
                  AND tv_auth.book_id = :book_id
                  AND tv_auth.chapter = :chapter
                  AND tv_auth.verse   = :verse
            )
            SELECT
                al.source_word_id,
                tw_t.id            AS tw_id,
                tw_t.word_text,
                tw_t.word_position,
                itl.method         AS itl_method,
                itl.confidence
            FROM authority_links al
            JOIN inter_translation_links itl
                ON (itl.word_a_id = al.auth_tw_id OR itl.word_b_id = al.auth_tw_id)
               AND itl.method != 'manual_empty'
            JOIN translation_words tw_t
                ON tw_t.id = CASE
                    WHEN itl.word_a_id = al.auth_tw_id THEN itl.word_b_id
                    ELSE itl.word_a_id
                END
            JOIN translation_verses tv_t ON tv_t.id = tw_t.verse_id
                AND tv_t.translation_id = :target_id
            ORDER BY al.source_word_id, tw_t.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'authority_id' => $authorityTranslationId,
            'target_id'    => $targetTranslationId,
            'book_id'      => $bookId,
            'chapter'      => $chapter,
            'verse'        => $verse,
        ]);

        $result = [];
        foreach ($rows as $row) {
            $srcId            = $row['source_word_id'];
            $result[$srcId][] = [
                'tw_id'     => $row['tw_id'],
                'word_text' => $row['word_text'],
                'word_pos'  => (int) $row['word_position'],
                'method'    => $row['itl_method'],
                'score'     => $row['confidence'] !== null ? (float) $row['confidence'] / 100.0 : null,
            ];
        }

        return $result;
    }

    /** Returns verse counts per chapter for a book/testament combination. */
    public function getChapterVerseCounts(int $bookId): array
    {
        $testament = $bookId <= 39 ? 'OT' : 'NT';
        $table     = $testament === 'OT' ? 'hebrew_words' : 'greek_words';

        $sql = "SELECT chapter, MAX(verse) AS verse_count
                FROM {$table}
                WHERE book_id = :book_id
                GROUP BY chapter
                ORDER BY chapter";

        return $this->connection->fetchAllAssociative($sql, ['book_id' => $bookId]);
    }

    /** Coverage statistics: how many source words have at least one link. Cached 1 hour. */
    public function getCoverageStats(): array
    {
        return $this->cache->get('coverage_stats', function (\Symfony\Contracts\Cache\ItemInterface $item): array {
            $item->expiresAfter(3600);
            return $this->fetchCoverageStats();
        });
    }

    private function fetchCoverageStats(): array
    {
        $sql = <<<SQL
            SELECT
                'OT' AS testament,
                COUNT(DISTINCT hw.id)                                               AS total_words,
                COUNT(DISTINCT wl.hebrew_word_id)                                   AS linked_words,
                COUNT(DISTINCT CASE WHEN lc.method = 'manual'      THEN wl.id END) AS manual_links,
                COUNT(DISTINCT CASE WHEN lc.method = 'manual_hint' THEN wl.id END) AS manual_hint_links,
                COUNT(DISTINCT CASE WHEN lc.method = 'proper_noun' THEN wl.id END) AS proper_noun_links,
                COUNT(DISTINCT CASE WHEN lc.method = 'positional'  THEN wl.id END) AS positional_links
            FROM hebrew_words hw
            LEFT JOIN word_links wl      ON wl.hebrew_word_id = hw.id
            LEFT JOIN link_confidence lc ON lc.link_id = wl.id
            UNION ALL
            SELECT
                'NT',
                COUNT(DISTINCT gw.id),
                COUNT(DISTINCT wl.greek_word_id),
                COUNT(DISTINCT CASE WHEN lc.method = 'manual'      THEN wl.id END),
                COUNT(DISTINCT CASE WHEN lc.method = 'manual_hint' THEN wl.id END),
                COUNT(DISTINCT CASE WHEN lc.method = 'proper_noun' THEN wl.id END),
                COUNT(DISTINCT CASE WHEN lc.method = 'positional'  THEN wl.id END)
            FROM greek_words gw
            LEFT JOIN word_links wl      ON wl.greek_word_id = gw.id
            LEFT JOIN link_confidence lc ON lc.link_id = wl.id
        SQL;

        return $this->connection->fetchAllAssociative($sql);
    }
}
