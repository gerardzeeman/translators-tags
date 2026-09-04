<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * LinkingRepository
 * All queries needed for the manual word-linking UI screens.
 */
class LinkingRepository
{
    public function __construct(
        private readonly Connection     $connection,
        private readonly CacheInterface $cache,
    ) {}

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
        $key = 'strongs_translit_' . strtolower($strongs);
        return $this->cache->get($key, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($strongs): array {
            $item->expiresAfter(3600);
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
                LIMIT 50
            SQL;

            return $this->connection->fetchAllAssociative($sql, ['strongs' => $this->padStrongsId($strongs)]);
        });
    }

    /**
     * Count distinct verses containing a Strong's number (for pagination).
     */
    public function countStrongsVerses(string $strongs): int
    {
        $table = str_starts_with($strongs, 'H') ? 'hebrew_words' : 'greek_words';

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM (
                SELECT DISTINCT book_id, chapter, verse
                FROM {$table}
                WHERE regexp_replace(strongs, '[A-Za-z]+$', '') = :strongs
             ) sub",
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
        $padded    = $this->padStrongsId($strongs);

        // Query 1: verse list with book metadata (1 query)
        $verseSql = <<<SQL
            SELECT DISTINCT
                sw.book_id, sw.chapter, sw.verse,
                b.name_nl AS book_name, b.usfm_code
            FROM {$table} sw
            JOIN books b ON b.id = sw.book_id
            WHERE regexp_replace(sw.strongs, '[A-Za-z]+$', '') = :strongs
            ORDER BY sw.book_id, sw.chapter, sw.verse
            LIMIT :limit OFFSET :offset
        SQL;

        $verses = $this->connection->fetchAllAssociative($verseSql, [
            'strongs' => $padded,
            'limit'   => $perPage,
            'offset'  => $offset,
        ]);

        if (empty($verses)) {
            return [];
        }

        // Query 2: all source words for the page in one CTE-based query
        $sourceByVerse = $testament === 'OT'
            ? $this->fetchHebrewBatch($table, $padded, $translationId, $perPage, $offset)
            : $this->fetchGreekBatch($table, $padded, $translationId, $perPage, $offset);

        // Query 3: all Dutch words for the page in one CTE-based query
        $dutchByVerse = $this->fetchDutchBatch($table, $padded, $translationId, $perPage, $offset);

        return array_map(function ($v) use ($testament, $sourceByVerse, $dutchByVerse) {
            $key = "{$v['book_id']}-{$v['chapter']}-{$v['verse']}";
            return [
                'book_id'      => $v['book_id'],
                'chapter'      => $v['chapter'],
                'verse'        => $v['verse'],
                'book_name'    => $v['book_name'],
                'usfm_code'    => $v['usfm_code'],
                'testament'    => $testament,
                'source_words' => $sourceByVerse[$key] ?? [],
                'dutch_words'  => $dutchByVerse[$key]  ?? [],
            ];
        }, $verses);
    }

    private function fetchHebrewBatch(string $table, string $padded, int $translationId, int $perPage, int $offset): array
    {
        $sql = <<<SQL
            WITH verse_page AS (
                SELECT DISTINCT book_id, chapter, verse
                FROM {$table}
                WHERE regexp_replace(strongs, '[A-Za-z]+$', '') = :strongs
                ORDER BY book_id, chapter, verse
                LIMIT :limit OFFSET :offset
            )
            SELECT
                hw.id, hw.word_position, hw.word_text, hw.transliteration,
                hw.book_id, hw.chapter, hw.verse,
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
            FROM verse_page vp
            JOIN hebrew_words hw ON hw.book_id = vp.book_id AND hw.chapter = vp.chapter AND hw.verse = vp.verse
            LEFT JOIN word_links wl         ON wl.hebrew_word_id = hw.id
            LEFT JOIN translation_words tw  ON tw.id = wl.translation_word_id
            LEFT JOIN translation_verses tv ON tv.id = tw.verse_id AND tv.translation_id = :translation_id
            LEFT JOIN LATERAL (
                SELECT method, score FROM link_confidence
                WHERE link_id = wl.id
                ORDER BY score DESC LIMIT 1
            ) lc_best ON true
            LEFT JOIN manual_empty_links mel ON mel.hebrew_word_id = hw.id AND mel.translation_id = :translation_id
            GROUP BY hw.id, hw.book_id, hw.chapter, hw.verse, mel.id
            ORDER BY hw.book_id, hw.chapter, hw.verse, hw.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'strongs'        => $padded,
            'limit'          => $perPage,
            'offset'         => $offset,
            'translation_id' => $translationId,
        ]);

        return $this->groupSourceRowsByVerse($rows);
    }

    private function fetchGreekBatch(string $table, string $padded, int $translationId, int $perPage, int $offset): array
    {
        $sql = <<<SQL
            WITH verse_page AS (
                SELECT DISTINCT book_id, chapter, verse
                FROM {$table}
                WHERE regexp_replace(strongs, '[A-Za-z]+$', '') = :strongs
                ORDER BY book_id, chapter, verse
                LIMIT :limit OFFSET :offset
            )
            SELECT
                gw.id, gw.word_position, gw.word_text, gw.transliteration,
                gw.book_id, gw.chapter, gw.verse,
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
            FROM verse_page vp
            JOIN greek_words gw ON gw.book_id = vp.book_id AND gw.chapter = vp.chapter AND gw.verse = vp.verse
            LEFT JOIN word_links wl         ON wl.greek_word_id = gw.id
            LEFT JOIN translation_words tw  ON tw.id = wl.translation_word_id
            LEFT JOIN translation_verses tv ON tv.id = tw.verse_id AND tv.translation_id = :translation_id
            LEFT JOIN LATERAL (
                SELECT method, score FROM link_confidence
                WHERE link_id = wl.id
                ORDER BY score DESC LIMIT 1
            ) lc_best ON true
            LEFT JOIN manual_empty_links mel ON mel.greek_word_id = gw.id AND mel.translation_id = :translation_id
            GROUP BY gw.id, gw.book_id, gw.chapter, gw.verse, mel.id
            ORDER BY gw.book_id, gw.chapter, gw.verse, gw.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'strongs'        => $padded,
            'limit'          => $perPage,
            'offset'         => $offset,
            'translation_id' => $translationId,
        ]);

        return $this->groupSourceRowsByVerse($rows);
    }

    private function fetchDutchBatch(string $table, string $padded, int $translationId, int $perPage, int $offset): array
    {
        $sql = <<<SQL
            WITH verse_page AS (
                SELECT DISTINCT book_id, chapter, verse
                FROM {$table}
                WHERE regexp_replace(strongs, '[A-Za-z]+$', '') = :strongs
                ORDER BY book_id, chapter, verse
                LIMIT :limit OFFSET :offset
            )
            SELECT
                tw.id, tw.word_position, tw.word_text, tw.char_start, tw.char_end,
                tw.is_filler::int AS is_filler,
                tv.verse_text, tv.book_id, tv.chapter, tv.verse,
                COALESCE(
                    json_agg(
                        json_build_object(
                            'link_id',     wl.id,
                            'source_lang', wl.source_language,
                            'he_word_id',  wl.hebrew_word_id,
                            'gk_word_id',  wl.greek_word_id,
                            'method',      lc_best.method,
                            'score',       lc_best.score
                        )
                    ) FILTER (WHERE wl.id IS NOT NULL),
                    '[]'
                ) AS links
            FROM verse_page vp
            JOIN translation_verses tv ON tv.book_id = vp.book_id AND tv.chapter = vp.chapter AND tv.verse = vp.verse
                AND tv.translation_id = :translation_id
            JOIN translation_words tw ON tw.verse_id = tv.id
            LEFT JOIN word_links wl ON wl.translation_word_id = tw.id
            LEFT JOIN LATERAL (
                SELECT method, score FROM link_confidence
                WHERE link_id = wl.id
                ORDER BY score DESC LIMIT 1
            ) lc_best ON true
            GROUP BY tw.id, tv.verse_text, tv.book_id, tv.chapter, tv.verse
            ORDER BY tv.book_id, tv.chapter, tv.verse, tw.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'strongs'        => $padded,
            'limit'          => $perPage,
            'offset'         => $offset,
            'translation_id' => $translationId,
        ]);

        $rows = array_map(fn($row) => array_merge($row, [
            'links' => json_decode($row['links'], true),
        ]), $rows);

        // Group by verse, apply punctuation per group, then re-key
        $grouped = [];
        foreach ($rows as $row) {
            $key = "{$row['book_id']}-{$row['chapter']}-{$row['verse']}";
            $grouped[$key][] = $row;
        }

        $result = [];
        foreach ($grouped as $key => $verseRows) {
            $result[$key] = self::addPunctuation($verseRows);
        }
        return $result;
    }

    private function groupSourceRowsByVerse(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = "{$row['book_id']}-{$row['chapter']}-{$row['verse']}";
            $grouped[$key][] = array_merge($row, [
                'links' => json_decode($row['links'], true),
            ]);
        }
        return $grouped;
    }

    /**
     * Progress: how many words with this Strong's have at least one manual link.
     */
    public function fetchStrongsProgress(string $strongs, int $translationId): array
    {
        $key = 'strongs_progress_' . strtolower($strongs) . '_' . $translationId;
        return $this->cache->get($key, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($strongs, $translationId): array {
            $item->expiresAfter(300);
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
        });
    }

    // ── Link mutation helpers (used by LinkingController) ─────────────────────

    /**
     * Create a manual link between a source word and one or more Dutch words,
     * OR record that the source word intentionally has no Dutch translation.
     *
     * When $twIds is empty the word is saved as "manually confirmed: no link",
     * stored in manual_empty_links. Existing word_links for this source word
     * within the given translation are removed either way.
     *
     * $createdByUserId is recorded on link_confidence for the $twIds branch
     * (manual_empty_links has no such column). Not attached to a specific
     * user if null — e.g. when this is ever called from a script.
     */
    public function saveManualLinks(string $lang, int $sourceWordId, array $twIds, int $translationId, ?int $createdByUserId = null): void
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

            // Batch-insert all word_links, then batch-insert link_confidence.
            // Using RETURNING to get IDs for the confidence insert.
            // ON CONFLICT … DO UPDATE (no-op) ensures RETURNING always fires.
            $this->connection->transactional(function () use ($idCol, $lang, $sourceWordId, $twIds, $createdByUserId): void {
                $linkIds = [];
                foreach ($twIds as $twId) {
                    $linkIds[] = $this->connection->fetchOne(
                        "INSERT INTO word_links (source_language, {$idCol}, translation_word_id)
                         VALUES (:lang, :src_id, :tw_id)
                         ON CONFLICT ({$idCol}, translation_word_id) WHERE {$idCol} IS NOT NULL
                         DO UPDATE SET source_language = EXCLUDED.source_language
                         RETURNING id",
                        ['lang' => $lang, 'src_id' => $sourceWordId, 'tw_id' => (int) $twId]
                    );
                }

                foreach ($linkIds as $linkId) {
                    // COALESCE keeps the original creator on re-save (e.g. re-picking the
                    // same Dutch word after tweaking the selection) — matches the pattern
                    // already used for inter_translation_links, see saveInterTranslationLink().
                    $this->connection->executeStatement(
                        "INSERT INTO link_confidence (link_id, method, score, created_at, created_by_user_id)
                         VALUES (:link_id, 'manual', 1.000, NOW(), :created_by_user_id)
                         ON CONFLICT (link_id, method) DO UPDATE
                            SET score = 1.000,
                                created_at = NOW(),
                                created_by_user_id = COALESCE(link_confidence.created_by_user_id, EXCLUDED.created_by_user_id)",
                        ['link_id' => (int) $linkId, 'created_by_user_id' => $createdByUserId]
                    );
                }
            });
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
     * Verify that all given translation_word IDs belong to the specified translation.
     * Prevents cross-translation link injection (IDOR guard).
     */
    public function translationWordsBelongToTranslation(array $twIds, int $translationId): bool
    {
        if (empty($twIds)) {
            return true;
        }
        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT tw.id)
             FROM translation_words tw
             JOIN translation_verses tv ON tv.id = tw.verse_id
             WHERE tw.id IN (:ids)
               AND tv.translation_id = :translation_id",
            ['ids' => $twIds, 'translation_id' => $translationId],
            ['ids' => ArrayParameterType::INTEGER]
        );
        return $count === count($twIds);
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
     * Every word_links row for a single source word (one Hebrew/Greek
     * occurrence), across all translations, with every link_confidence entry
     * recorded against it (a link can carry more than one method — e.g. an
     * older 'positional' guess left in place alongside a later 'manual'
     * confirmation on the same row). Read-only review data for the
     * ROLE_LINKER-only "all links" panel.
     *
     * Grouped by translation — a source word usually maps to more than one
     * Dutch word within the same translation (e.g. one Greek word rendered
     * as a short phrase), and those belong in one table together, but two
     * translations must never be merged into the same block.
     *
     * Returns one entry per translation:
     *   translation_code, translation_name, usfm_code, book_name,
     *   chapter, verse, links: [ {link_id, tw_id, word_text, word_position,
     *   is_filler, confidences: [...]}, ... ]
     */
    public function fetchWordLinksDetail(string $lang, int $sourceWordId): array
    {
        $idCol = $lang === 'HE' ? 'hebrew_word_id' : 'greek_word_id';

        $sql = <<<SQL
            SELECT
                wl.id             AS link_id,
                t.code            AS translation_code,
                t.name            AS translation_name,
                b.usfm_code,
                b.name_nl         AS book_name,
                tv.chapter,
                tv.verse,
                tw.id             AS tw_id,
                tw.word_text,
                tw.word_position,
                tw.is_filler,
                lc.method,
                lc.score,
                lc.notes,
                lc.created_at,
                u.display_name    AS created_by_name,
                u.email           AS created_by_email
            FROM word_links wl
            JOIN translation_words tw    ON tw.id = wl.translation_word_id
            JOIN translation_verses tv   ON tv.id = tw.verse_id
            JOIN translations t          ON t.id = tv.translation_id
            JOIN books b                 ON b.id = tv.book_id
            LEFT JOIN link_confidence lc ON lc.link_id = wl.id
            LEFT JOIN users u            ON u.id = lc.created_by_user_id
            WHERE wl.{$idCol} = :source_id
            ORDER BY t.code, tw.word_position, lc.score DESC NULLS LAST, lc.method
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, ['source_id' => $sourceWordId]);

        $links = [];
        foreach ($rows as $row) {
            $linkId = (int) $row['link_id'];
            if (!isset($links[$linkId])) {
                $links[$linkId] = [
                    'link_id'          => $linkId,
                    'translation_code' => $row['translation_code'],
                    'translation_name' => $row['translation_name'],
                    'usfm_code'        => $row['usfm_code'],
                    'book_name'        => $row['book_name'],
                    'chapter'          => (int) $row['chapter'],
                    'verse'            => (int) $row['verse'],
                    'tw_id'            => (int) $row['tw_id'],
                    'word_text'        => $row['word_text'],
                    'word_position'    => (int) $row['word_position'],
                    'is_filler'        => (bool) $row['is_filler'],
                    'confidences'      => [],
                ];
            }
            if ($row['method'] !== null) {
                $links[$linkId]['confidences'][] = [
                    'method'           => $row['method'],
                    'score'            => $row['score'] !== null ? (float) $row['score'] : null,
                    'notes'            => $row['notes'],
                    'created_at'       => $row['created_at'],
                    'created_by_name'  => $row['created_by_name'],
                    'created_by_email' => $row['created_by_email'],
                ];
            }
        }

        // Group per translation, preserving first-seen (SQL-sorted) order.
        $groups = [];
        foreach ($links as $link) {
            $code = $link['translation_code'];
            if (!isset($groups[$code])) {
                $groups[$code] = [
                    'translation_code' => $link['translation_code'],
                    'translation_name' => $link['translation_name'],
                    'usfm_code'        => $link['usfm_code'],
                    'book_name'        => $link['book_name'],
                    'chapter'          => $link['chapter'],
                    'verse'            => $link['verse'],
                    'links'            => [],
                ];
            }
            $groups[$code]['links'][] = $link;
        }

        return array_values($groups);
    }

    /**
     * Manual-link statistics per Strong's number, for one testament and
     * translation: how often each Strong's number was manually linked, and
     * to which Dutch word(s) — ranked by frequency, top $topN kept.
     *
     * Same underlying signal as align_heuristic.py's manual-hint index
     * (method = 'manual' only), just aggregated for display instead of used
     * as an alignment hint.
     *
     * A source word occurrence can be manually linked to more than one Dutch
     * word (e.g. G746/ἀρχή → "den" + "beginne") — those are separate
     * word_links rows but belong together as one translation. Grouped first
     * by source word occurrence (id), concatenating its linked Dutch words in
     * reading order, before counting phrase frequency — so "den beginne" is
     * counted as one unit, not as "den" and "beginne" separately.
     *
     * @return list<array{
     *     strongs_id: string, lemma: ?string, transliteration: ?string,
     *     short_def: ?string, total: int,
     *     translations: list<array{word: string, count: int}>
     * }>
     */
    public function fetchManualTranslationStats(string $testament, int $translationId, int $topN = 5): array
    {
        $table = $testament === 'OT' ? 'hebrew_words' : 'greek_words';
        $idCol = $testament === 'OT' ? 'hebrew_word_id' : 'greek_word_id';
        $prefix = $testament === 'OT' ? 'H' : 'G';

        // Same canonical-Strong's-id normalisation used elsewhere in this
        // class (e.g. fetchHebrewForLinking) so this joins cleanly against
        // strongs_entries.strongs_id.
        $normalisedStrongs = <<<SQL
            regexp_replace(
                CASE WHEN sw.strongs ~ '^[HGhg]'
                     THEN regexp_replace(sw.strongs, '[A-Za-z]+$', '')
                     ELSE '{$prefix}' || regexp_replace(sw.strongs, '[A-Za-z]+$', '')
                END,
                '^([HG])0+(\d)', '\\1\\2'
            )
        SQL;

        $sql = <<<SQL
            WITH manual_words AS (
                SELECT
                    wl.{$idCol}   AS source_id,
                    {$normalisedStrongs} AS strongs,
                    tw.word_normalised,
                    tw.word_position
                FROM word_links wl
                JOIN link_confidence lc     ON lc.link_id = wl.id AND lc.method = 'manual'
                JOIN translation_words tw   ON tw.id = wl.translation_word_id
                JOIN translation_verses tv  ON tv.id = tw.verse_id
                JOIN {$table} sw            ON sw.id = wl.{$idCol}
                WHERE sw.strongs IS NOT NULL
                  AND tv.translation_id = :translation_id
            ),
            phrases AS (
                SELECT
                    source_id, strongs,
                    string_agg(word_normalised, ' ' ORDER BY word_position) AS phrase
                FROM manual_words
                GROUP BY source_id, strongs
            ),
            counts AS (
                SELECT strongs, phrase, COUNT(*) AS cnt
                FROM phrases
                GROUP BY strongs, phrase
            ),
            totals AS (
                SELECT strongs, SUM(cnt) AS total
                FROM counts
                GROUP BY strongs
            ),
            ranked AS (
                SELECT strongs, phrase, cnt,
                       ROW_NUMBER() OVER (PARTITION BY strongs ORDER BY cnt DESC, phrase) AS rn
                FROM counts
            )
            SELECT
                t.strongs, t.total,
                r.phrase, r.cnt,
                se.lemma, se.transliteration, se.short_def, se.short_def_nl
            FROM totals t
            JOIN ranked r ON r.strongs = t.strongs AND r.rn <= :top_n
            LEFT JOIN strongs_entries se ON se.strongs_id = t.strongs
            ORDER BY t.total DESC, t.strongs, r.rn
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'translation_id' => $translationId,
            'top_n'          => $topN,
        ]);

        $stats = [];
        foreach ($rows as $row) {
            $strongsId = $row['strongs'];
            if (!isset($stats[$strongsId])) {
                $stats[$strongsId] = [
                    'strongs_id'      => $strongsId,
                    'lemma'           => $row['lemma'],
                    'transliteration' => $row['transliteration'],
                    'short_def'       => $row['short_def_nl'] ?: $row['short_def'],
                    'total'           => (int) $row['total'],
                    'translations'    => [],
                ];
            }
            $stats[$strongsId]['translations'][] = [
                'word'  => $row['phrase'],
                'count' => (int) $row['cnt'],
            ];
        }

        return array_values($stats);
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
        $normalId = $this->normalizeStrongsId($strongs);
        $paddedId = $this->padStrongsId($normalId);

        $row = $this->connection->fetchAssociative(
            "SELECT se.strongs_id, se.lang, se.lemma, se.transliteration, se.pronunciation,
                    se.pos, se.morph, se.definition, se.etymology, se.kjv_renderings,
                    se.short_def, se.definition_nl, se.etymology_nl, se.short_def_nl,
                    CASE se.lang
                        WHEN 'HE' THEN (
                            SELECT COUNT(*) FROM hebrew_words
                            WHERE regexp_replace(strongs, '[A-Za-z]+$', '') = :padded
                        )
                        ELSE (
                            SELECT COUNT(*) FROM greek_words
                            WHERE regexp_replace(strongs, '[A-Za-z]+$', '') = :padded
                        )
                    END AS occurrence_count
             FROM strongs_entries se
             WHERE se.strongs_id = :id",
            ['id' => $normalId, 'padded' => $paddedId]
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
                ta.id   AS id_a,  ta.code  AS code_a,  ta.name AS name_a,  COALESCE(ta.abbreviation, ta.code) AS abbreviation_a,
                tb.id   AS id_b,  tb.code  AS code_b,  tb.name AS name_b,  COALESCE(tb.abbreviation, tb.code) AS abbreviation_b,
                ta.family
             FROM translations ta
             JOIN translations tb ON tb.family = ta.family AND tb.id != ta.id
             WHERE ta.source_lang_authority = TRUE
             ORDER BY ta.family, CASE tb.code WHEN 'SV1657' THEN 1 WHEN 'SVGBS' THEN 2 WHEN 'HSV' THEN 3 ELSE 4 END, tb.code"
        );
    }

    /**
     * Translation pairs for the 4-way historical-spelling alignment pipeline:
     * every translation sharing a family with the `is_alignment_pivot` one
     * (currently SV1657), pivoted on it. Deliberately a separate query from
     * fetchTranslationPairs() -- that one pivots on source_lang_authority
     * (SV/Jongbloed, for the unrelated Hebrew/Greek word_links propagation),
     * not is_alignment_pivot. See migration Version20260904120000.
     */
    public function fetchHistoricalAlignmentPairs(): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT
                ta.id   AS id_a,  ta.code  AS code_a,  ta.name AS name_a,  COALESCE(ta.abbreviation, ta.code) AS abbreviation_a,
                tb.id   AS id_b,  tb.code  AS code_b,  tb.name AS name_b,  COALESCE(tb.abbreviation, tb.code) AS abbreviation_b,
                ta.family
             FROM translations ta
             JOIN translations tb ON tb.family = ta.family AND tb.id != ta.id
             WHERE ta.is_alignment_pivot = TRUE
             ORDER BY ta.family, tb.code"
        );
    }

    /**
     * Batch-fetches everything the book-/chapter-overview pages need to
     * compute a HistoricalAlignmentScoreService score per verse, in as few
     * queries as possible: one for the pivot's own verse list (authoritative
     * for SV1657 -- avoids relying on Hebrew/Greek-derived verse counts,
     * which can disagree with SV1657 due to versification differences), one
     * for every word across the four translations in scope, one for every
     * link touching those words. The per-verse score computation itself
     * then happens in PHP over already-in-memory data -- no N+1 queries per
     * verse, which matters for a book like Psalms (~2500 verses).
     *
     * @return array{
     *   pivot_code: string,
     *   verse_list: list<array{chapter: int, verse: int}>,
     *   words_by_verse: array<string, array<string, list<array>>>,
     *   links_by_verse: array<string, list<array>>,
     * }
     */
    public function fetchHistoricalAlignmentScopeData(int $bookId, ?int $chapter = null): array
    {
        $pivot = $this->connection->fetchAssociative(
            'SELECT id, code FROM translations WHERE is_alignment_pivot = TRUE LIMIT 1'
        );
        $pivotId = (int) $pivot['id'];
        $pivotCode = (string) $pivot['code'];

        $verseListParams = ['pivotId' => $pivotId, 'bookId' => $bookId];
        $verseListSql = 'SELECT chapter, verse FROM translation_verses WHERE translation_id = :pivotId AND book_id = :bookId';
        if ($chapter !== null) {
            $verseListSql .= ' AND chapter = :chapter';
            $verseListParams['chapter'] = $chapter;
        }
        $verseListSql .= ' ORDER BY chapter, verse';
        $verseList = $this->connection->fetchAllAssociative($verseListSql, $verseListParams);

        $wordsParams = ['bookId' => $bookId];
        $wordsSql = "SELECT tv.chapter, tv.verse, t.code, tw.id, tw.word_position, tw.word_text, tw.is_filler, tw.alignment_note
                     FROM translation_words tw
                     JOIN translation_verses tv ON tv.id = tw.verse_id
                     JOIN translations t ON t.id = tv.translation_id
                     WHERE tv.book_id = :bookId
                       AND t.family = (SELECT family FROM translations WHERE is_alignment_pivot = TRUE LIMIT 1)";
        if ($chapter !== null) {
            $wordsSql .= ' AND tv.chapter = :chapter';
            $wordsParams['chapter'] = $chapter;
        }
        $words = $this->connection->fetchAllAssociative($wordsSql, $wordsParams);

        $wordsByVerse = [];
        $verseKeyByWordId = [];
        foreach ($words as $w) {
            $key = $w['chapter'] . ':' . $w['verse'];
            $wordsByVerse[$key][$w['code']][] = $w;
            $verseKeyByWordId[(int) $w['id']] = $key;
        }

        $linksParams = ['bookId' => $bookId];
        $linksSql = 'SELECT itl.word_a_id, itl.word_b_id, itl.method, itl.score
                     FROM inter_translation_links itl
                     JOIN translation_words twa ON twa.id = itl.word_a_id
                     JOIN translation_verses tva ON tva.id = twa.verse_id
                     WHERE tva.book_id = :bookId';
        if ($chapter !== null) {
            $linksSql .= ' AND tva.chapter = :chapter';
            $linksParams['chapter'] = $chapter;
        }
        $links = $this->connection->fetchAllAssociative($linksSql, $linksParams);

        $linksByVerse = [];
        foreach ($links as $l) {
            $key = $verseKeyByWordId[(int) $l['word_a_id']] ?? null;
            if ($key !== null) {
                $linksByVerse[$key][] = $l;
            }
        }

        return [
            'pivot_code' => $pivotCode,
            'verse_list' => $verseList,
            'words_by_verse' => $wordsByVerse,
            'links_by_verse' => $linksByVerse,
        ];
    }

    /**
     * Clears alignment_note (particle_drop/prefix_drop) for the pivot
     * translation's words in the given scope, so a fresh recompute can
     * re-derive it cleanly across all three of its pairs (particle_drop is
     * pair-independent so always converges the same either way, but
     * prefix_drop can vary per pair -- see markAlignmentNote()'s
     * $onlyIfUnset union semantics). Call once per full recompute run,
     * before processing any pair.
     */
    public function clearAlignmentNotesForPivotScope(int $pivotTranslationId, ?string $book, ?int $chapter, ?int $verse): int
    {
        $sql = "UPDATE translation_words tw
                SET alignment_note = NULL
                FROM translation_verses tv, books b
                WHERE tw.verse_id = tv.id AND tv.book_id = b.id
                  AND tv.translation_id = :pivotId
                  AND tw.alignment_note IS NOT NULL";
        $params = ['pivotId' => $pivotTranslationId];

        if ($book !== null) {
            $sql .= ' AND b.usfm_code = :usfm';
            $params['usfm'] = $book;
        }
        if ($chapter !== null) {
            $sql .= ' AND tv.chapter = :chapter';
            $params['chapter'] = $chapter;
        }
        if ($verse !== null) {
            $sql .= ' AND tv.verse = :verse';
            $params['verse'] = $verse;
        }

        return (int) $this->connection->executeStatement($sql, $params);
    }

    /**
     * Marks words with a systematic-exclusion reason (see migration
     * Version20260904130000). With $onlyIfUnset, an existing note is never
     * downgraded/overwritten -- used for prefix_drop, which can be flagged
     * by one pair's alignment run and not another's, so a flag from any
     * pair should stick for the whole recompute.
     */
    public function markAlignmentNote(array $wordIds, string $note, bool $onlyIfUnset = false): void
    {
        if (!$wordIds) {
            return;
        }
        $sql = 'UPDATE translation_words SET alignment_note = :note WHERE id IN (:ids)';
        if ($onlyIfUnset) {
            $sql .= ' AND alignment_note IS NULL';
        }
        $this->connection->executeStatement(
            $sql,
            ['note' => $note, 'ids' => $wordIds],
            ['ids' => ArrayParameterType::INTEGER]
        );
    }

    /**
     * All data the historical-alignment review page needs for one verse:
     * the four translations (in fixed SV1657/SV/SVGBS/HSV display order),
     * their words (including alignment_note), and every
     * inter_translation_links row touching any of those words.
     *
     * @return array{translations: list<array>, words: array<string, list<array>>, links: list<array>, pivot_code: string}
     */
    public function fetchHistoricalAlignmentVerseData(int $bookId, int $chapter, int $verse): array
    {
        $translations = $this->connection->fetchAllAssociative(
            "SELECT id, code, name, COALESCE(abbreviation, code) AS abbreviation, is_alignment_pivot
             FROM translations
             WHERE family = (SELECT family FROM translations WHERE is_alignment_pivot = TRUE LIMIT 1)"
        );

        $displayOrder = ['SV1657' => 0, 'SV' => 1, 'SVGBS' => 2, 'HSV' => 3];
        usort($translations, static fn($a, $b) => ($displayOrder[$a['code']] ?? 99) <=> ($displayOrder[$b['code']] ?? 99));

        $pivotCode = null;
        $wordsByCode = [];
        foreach ($translations as $t) {
            if ($t['is_alignment_pivot']) {
                $pivotCode = $t['code'];
            }
            $wordsByCode[$t['code']] = $this->connection->fetchAllAssociative(
                "SELECT tw.id, tw.word_position, tw.word_text, tw.is_filler, tw.alignment_note
                 FROM translation_words tw
                 JOIN translation_verses tv ON tv.id = tw.verse_id
                 WHERE tv.translation_id = :tid AND tv.book_id = :bid AND tv.chapter = :ch AND tv.verse = :vs
                 ORDER BY tw.word_position",
                ['tid' => $t['id'], 'bid' => $bookId, 'ch' => $chapter, 'vs' => $verse]
            );
        }

        $allIds = [];
        foreach ($wordsByCode as $words) {
            foreach ($words as $w) {
                $allIds[] = (int) $w['id'];
            }
        }

        $links = [];
        if ($allIds) {
            $links = $this->connection->fetchAllAssociative(
                "SELECT id, word_a_id, word_b_id, method, score, confidence, updated_at
                 FROM inter_translation_links
                 WHERE word_a_id IN (:ids) OR word_b_id IN (:ids)",
                ['ids' => $allIds],
                ['ids' => ArrayParameterType::INTEGER]
            );
        }

        return ['translations' => $translations, 'words' => $wordsByCode, 'links' => $links, 'pivot_code' => (string) $pivotCode];
    }

    /**
     * Guards manual link/unlink API calls: both words must belong to the
     * same verse (book/chapter/verse) and to translations within the
     * alignment-pivot family, so a request can't forge a link between
     * unrelated words.
     */
    public function wordsShareVerseInAlignmentFamily(int $wordAId, int $wordBId): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT 1
             FROM translation_words wa
             JOIN translation_words wb ON true
             JOIN translation_verses tva ON tva.id = wa.verse_id
             JOIN translation_verses tvb ON tvb.id = wb.verse_id
             JOIN translations ta ON ta.id = tva.translation_id
             JOIN translations tb ON tb.id = tvb.translation_id
             WHERE wa.id = :a AND wb.id = :b
               AND ta.family = tb.family
               AND ta.family = (SELECT family FROM translations WHERE is_alignment_pivot = TRUE LIMIT 1)
               AND tva.book_id = tvb.book_id AND tva.chapter = tvb.chapter AND tva.verse = tvb.verse
             LIMIT 1",
            ['a' => $wordAId, 'b' => $wordBId]
        );
    }

    /**
     * "Goedkeuren" (plan sectie 6): promotes every link touching any of
     * these word IDs to `manual`, score 1.0 -- including links that were
     * already manual, which get their updated_at/updated_by refreshed as a
     * re-confirmation, per plan sectie 4/6.
     */
    public function approveVerseLinks(array $wordIds, int $userId): int
    {
        if (!$wordIds) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            "UPDATE inter_translation_links
             SET method = 'manual',
                 score = 1.0,
                 confidence = NULL,
                 updated_at = NOW(),
                 updated_by = :userId,
                 created_by_user_id = COALESCE(created_by_user_id, :userId)
             WHERE word_a_id IN (:ids) OR word_b_id IN (:ids)",
            ['ids' => $wordIds, 'userId' => $userId],
            ['ids' => ArrayParameterType::INTEGER]
        );
    }

    /**
     * Existing manual links within a word-ID scope, as raw (word_a_id,
     * word_b_id) pairs. Used to seed forced anchors for
     * HistoricalAlignmentService::alignPair() -- manual links are always
     * respected and never overwritten by the auto-linker.
     */
    public function fetchManualLinkPairs(array $idsA, array $idsB): array
    {
        if (!$idsA || !$idsB) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            "SELECT word_a_id, word_b_id FROM inter_translation_links
             WHERE method = 'manual'
               AND ((word_a_id IN (:listA) AND word_b_id IN (:listB))
                 OR (word_a_id IN (:listB) AND word_b_id IN (:listA)))",
            ['listA' => $idsA, 'listB' => $idsB],
            ['listA' => ArrayParameterType::INTEGER, 'listB' => ArrayParameterType::INTEGER]
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
                COUNT(DISTINCT CASE WHEN itl.id IS NOT NULL THEN tv_a.id END) AS linked_verses
             FROM translation_verses tv_a
             JOIN translation_verses tv_b
                ON tv_b.translation_id = :id_b
               AND tv_b.book_id = tv_a.book_id
               AND tv_b.chapter = tv_a.chapter
               AND tv_b.verse   = tv_a.verse
             LEFT JOIN translation_words tw_a ON tw_a.verse_id = tv_a.id
             LEFT JOIN inter_translation_links itl
                ON (itl.word_a_id = tw_a.id OR itl.word_b_id = tw_a.id)
             WHERE tv_a.translation_id = :id_a",
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

        return $this->connection->fetchAllAssociative(
            "SELECT id, word_a_id, word_b_id, method, confidence
             FROM inter_translation_links
             WHERE (word_a_id IN (:listA) AND word_b_id IN (:listB))
                OR (word_a_id IN (:listB) AND word_b_id IN (:listA))",
            ['listA' => $idsA, 'listB' => $idsB],
            ['listA' => ArrayParameterType::INTEGER, 'listB' => ArrayParameterType::INTEGER]
        );
    }

    /**
     * Save an inter-translation link (word_a_id < word_b_id enforced).
     * `updated_at`/`updated_by` are stamped on every write, including when
     * re-confirming a link that already existed (see plan sectie 4/6).
     */
    public function saveInterTranslationLink(
        int $wordAId,
        int $wordBId,
        string $method = 'manual',
        ?int $confidence = null,
        ?int $createdByUserId = null,
        ?float $score = null,
    ): void {
        [$a, $b] = $wordAId < $wordBId ? [$wordAId, $wordBId] : [$wordBId, $wordAId];

        $this->connection->executeStatement(
            "INSERT INTO inter_translation_links (word_a_id, word_b_id, method, confidence, score, created_by_user_id, updated_at, updated_by)
             VALUES (:a, :b, :method, :confidence, :score, :userId, NOW(), :userId)
             ON CONFLICT (word_a_id, word_b_id) DO UPDATE
                SET method = EXCLUDED.method,
                    confidence = CASE WHEN EXCLUDED.confidence IS NULL THEN NULL ELSE EXCLUDED.confidence END,
                    score = EXCLUDED.score,
                    created_by_user_id = COALESCE(inter_translation_links.created_by_user_id, EXCLUDED.created_by_user_id),
                    updated_at = NOW(),
                    updated_by = EXCLUDED.updated_by",
            ['a' => $a, 'b' => $b, 'method' => $method, 'confidence' => $confidence, 'score' => $score, 'userId' => $createdByUserId]
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

        return (int) $this->connection->executeStatement(
            "DELETE FROM inter_translation_links
             WHERE method NOT IN ('manual', 'manual_empty')
               AND ((word_a_id IN (:listA) AND word_b_id IN (:listB))
                 OR (word_a_id IN (:listB) AND word_b_id IN (:listA)))",
            ['listA' => $idsA, 'listB' => $idsB],
            ['listA' => ArrayParameterType::INTEGER, 'listB' => ArrayParameterType::INTEGER]
        );
    }


}
