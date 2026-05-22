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
    public function fetchPassageForLinking(int $bookId, int $chapter, int $verse): array
    {
        $testament = $bookId <= 39 ? 'OT' : 'NT';

        $sourceWords = $testament === 'OT'
            ? $this->fetchHebrewForLinking($bookId, $chapter, $verse)
            : $this->fetchGreekForLinking($bookId, $chapter, $verse);

        $dutchWords = $this->fetchDutchForLinking($bookId, $chapter, $verse);

        return [
            'testament'    => $testament,
            'source_words' => $sourceWords,
            'dutch_words'  => $dutchWords,
        ];
    }

    private function fetchHebrewForLinking(int $bookId, int $chapter, int $verse): array
    {
        $sql = <<<SQL
            SELECT
                hw.id,
                hw.word_position,
                hw.word_text,
                hw.transliteration,
                hw.strongs,
                hw.morph_code,
                COALESCE(
                    json_agg(
                        json_build_object(
                            'link_id', wl.id,
                            'tw_id',   tw.id,
                            'method',  lc_best.method,
                            'score',   lc_best.score
                        ) ORDER BY tw.word_position
                    ) FILTER (WHERE wl.id IS NOT NULL),
                    '[]'
                ) AS links,
                CASE WHEN mel.id IS NOT NULL THEN 1 ELSE 0 END AS is_manually_empty
            FROM hebrew_words hw
            LEFT JOIN word_links wl ON wl.hebrew_word_id = hw.id
            LEFT JOIN translation_words tw ON tw.id = wl.translation_word_id
            LEFT JOIN LATERAL (
                SELECT method, score FROM link_confidence
                WHERE link_id = wl.id
                ORDER BY score DESC LIMIT 1
            ) lc_best ON true
            LEFT JOIN manual_empty_links mel ON mel.hebrew_word_id = hw.id
            WHERE hw.book_id = :book_id
              AND hw.chapter = :chapter
              AND hw.verse   = :verse
            GROUP BY hw.id, mel.id
            ORDER BY hw.word_position
        SQL;

        return $this->fetchWithJsonLinks($sql, $bookId, $chapter, $verse);
    }

    private function fetchGreekForLinking(int $bookId, int $chapter, int $verse): array
    {
        $sql = <<<SQL
            SELECT
                gw.id,
                gw.word_position,
                gw.word_text,
                gw.transliteration,
                gw.strongs,
                gw.parse_code,
                COALESCE(
                    json_agg(
                        json_build_object(
                            'link_id', wl.id,
                            'tw_id',   tw.id,
                            'method',  lc_best.method,
                            'score',   lc_best.score
                        ) ORDER BY tw.word_position
                    ) FILTER (WHERE wl.id IS NOT NULL),
                    '[]'
                ) AS links,
                CASE WHEN mel.id IS NOT NULL THEN 1 ELSE 0 END AS is_manually_empty
            FROM greek_words gw
            LEFT JOIN word_links wl ON wl.greek_word_id = gw.id
            LEFT JOIN translation_words tw ON tw.id = wl.translation_word_id
            LEFT JOIN LATERAL (
                SELECT method, score FROM link_confidence
                WHERE link_id = wl.id
                ORDER BY score DESC LIMIT 1
            ) lc_best ON true
            LEFT JOIN manual_empty_links mel ON mel.greek_word_id = gw.id
            WHERE gw.book_id = :book_id
              AND gw.chapter = :chapter
              AND gw.verse   = :verse
            GROUP BY gw.id, mel.id
            ORDER BY gw.word_position
        SQL;

        return $this->fetchWithJsonLinks($sql, $bookId, $chapter, $verse);
    }

    private function fetchWithJsonLinks(string $sql, int $bookId, int $chapter, int $verse): array
    {
        $rows = $this->connection->fetchAllAssociative($sql, [
            'book_id' => $bookId,
            'chapter' => $chapter,
            'verse'   => $verse,
        ]);

        return array_map(fn($row) => array_merge($row, [
            'links' => json_decode($row['links'], true),
        ]), $rows);
    }

    private function fetchDutchForLinking(int $bookId, int $chapter, int $verse): array
    {
        $sql = <<<SQL
            SELECT
                tw.id,
                tw.word_position,
                tw.word_text,
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
            WHERE tv.translation_id = 1
              AND tv.book_id = :book_id
              AND tv.chapter = :chapter
              AND tv.verse   = :verse
            GROUP BY tw.id, tv.verse_text
            ORDER BY tw.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'book_id' => $bookId,
            'chapter' => $chapter,
            'verse'   => $verse,
        ]);

        return array_map(fn($row) => array_merge($row, [
            'links' => json_decode($row['links'], true),
        ]), $rows);
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
            WHERE strongs = :strongs
              AND transliteration IS NOT NULL
              AND transliteration <> ''
            GROUP BY transliteration
            ORDER BY occurrence_count DESC, transliteration
        SQL;

        return $this->connection->fetchAllAssociative($sql, ['strongs' => $strongs]);
    }

    /**
     * All verses containing a Strong's number, with source + Dutch words for linking.
     */
    public function fetchStrongsVerses(string $strongs): array
    {
        $testament = str_starts_with($strongs, 'H') ? 'OT' : 'NT';
        $table     = $testament === 'OT' ? 'hebrew_words' : 'greek_words';
        $lang      = $testament === 'OT' ? 'HE' : 'GR';
        $id_col    = $testament === 'OT' ? 'hebrew_word_id' : 'greek_word_id';

        // Get all distinct verses containing this Strong's number
        $sql = <<<SQL
            SELECT DISTINCT
                sw.book_id,
                sw.chapter,
                sw.verse,
                b.name_nl  AS book_name,
                b.usfm_code
            FROM {$table} sw
            JOIN books b ON b.id = sw.book_id
            WHERE sw.strongs = :strongs
            ORDER BY sw.book_id, sw.chapter, sw.verse
        SQL;

        $verses = $this->connection->fetchAllAssociative($sql, ['strongs' => $strongs]);

        // For each verse, fetch the full linking data
        $result = [];
        foreach ($verses as $v) {
            $passage = $testament === 'OT'
                ? $this->fetchHebrewForLinking($v['book_id'], $v['chapter'], $v['verse'])
                : $this->fetchGreekForLinking($v['book_id'], $v['chapter'], $v['verse']);

            $dutch = $this->fetchDutchForLinking($v['book_id'], $v['chapter'], $v['verse']);

            $result[] = [
                'book_id'   => $v['book_id'],
                'chapter'   => $v['chapter'],
                'verse'     => $v['verse'],
                'book_name' => $v['book_name'],
                'usfm_code' => $v['usfm_code'],
                'testament' => $testament,
                'source_words' => $passage,
                'dutch_words'  => $dutch,
            ];
        }

        return $result;
    }

    /**
     * Progress: how many words with this Strong's have at least one manual link.
     */
    public function fetchStrongsProgress(string $strongs): array
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
            LEFT JOIN word_links wl ON wl.{$id_col} = sw.id
            LEFT JOIN link_confidence lc ON lc.link_id = wl.id
            WHERE sw.strongs = :strongs
        SQL;

        return $this->connection->fetchAssociative($sql, ['strongs' => $strongs]);
    }

    // ── Link mutation helpers (used by LinkingController) ─────────────────────

    /**
     * Create a manual link between a source word and one or more Dutch words,
     * OR record that the source word intentionally has no Dutch translation.
     *
     * When $twIds is empty the word is saved as "manually confirmed: no link",
     * stored in manual_empty_links. All existing word_links are removed either way.
     */
    public function saveManualLinks(string $lang, int $sourceWordId, array $twIds): void
    {
        $idCol = $lang === 'HE' ? 'hebrew_word_id' : 'greek_word_id';

        // Delete ALL existing word_links for this source word (any method).
        // Manual confirmation replaces automatic links entirely.
        $this->connection->executeStatement(
            "DELETE FROM word_links WHERE {$idCol} = :src_id",
            ['src_id' => $sourceWordId]
        );

        if (empty($twIds)) {
            // ── "No translation" path ────────────────────────────────────────
            // Upsert a manual_empty_links record (partial unique index handles dedup).
            $this->connection->executeStatement(
                "INSERT INTO manual_empty_links (source_language, {$idCol}, created_at)
                 VALUES (:lang, :src_id, NOW())
                 ON CONFLICT ({$idCol}) WHERE {$idCol} IS NOT NULL
                 DO UPDATE SET created_at = NOW()",
                ['lang' => $lang, 'src_id' => $sourceWordId]
            );
        } else {
            // ── Normal linking path ──────────────────────────────────────────
            // Remove any previous "no translation" annotation for this word.
            $this->connection->executeStatement(
                "DELETE FROM manual_empty_links WHERE {$idCol} = :src_id",
                ['src_id' => $sourceWordId]
            );

            // Insert new links with manual confidence.
            foreach ($twIds as $twId) {
                $this->connection->executeStatement(
                    "INSERT INTO word_links (source_language, {$idCol}, translation_word_id)
                     VALUES (:lang, :src_id, :tw_id)",
                    ['lang' => $lang, 'src_id' => $sourceWordId, 'tw_id' => (int) $twId]
                );

                $linkId = $this->connection->lastInsertId();

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
}