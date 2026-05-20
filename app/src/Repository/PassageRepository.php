<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * PassageRepository
 * Uses raw DBAL queries for the complex multi-table passage fetch,
 * which is more efficient than Doctrine ORM for deeply nested joins.
 */
class PassageRepository
{
    public function __construct(private readonly Connection $connection) {}

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
    public function fetchPassage(int $bookId, int $chapter, int $verse): array
    {
        // Determine testament
        $testament = $bookId <= 39 ? 'OT' : 'NT';

        if ($testament === 'OT') {
            $sourceWords = $this->fetchHebrewWords($bookId, $chapter, $verse);
        } else {
            $sourceWords = $this->fetchGreekWords($bookId, $chapter, $verse);
        }

        $dutchVerse = $this->fetchDutchVerse($bookId, $chapter, $verse);

        return [
            'testament'    => $testament,
            'source_words' => $sourceWords,
            'dutch_verse'  => $dutchVerse,
        ];
    }

    private function fetchHebrewWords(int $bookId, int $chapter, int $verse): array
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
                -- Aggregate linked Dutch words as JSON
                COALESCE(
                    json_agg(
                        json_build_object(
                            'tw_id',       tw.id,
                            'word_text',   tw.word_text,
                            'word_pos',    tw.word_position,
                            'method',      lc.method,
                            'score',       lc.score
                        )
                        ORDER BY lc.score DESC
                    ) FILTER (WHERE tw.id IS NOT NULL),
                    '[]'
                ) AS dutch_links
            FROM hebrew_words hw
            LEFT JOIN word_links wl      ON wl.hebrew_word_id = hw.id
            LEFT JOIN translation_words tw ON tw.id = wl.translation_word_id
            LEFT JOIN link_confidence lc ON lc.link_id = wl.id
            WHERE hw.book_id = :book_id
              AND hw.chapter = :chapter
              AND hw.verse   = :verse
            GROUP BY hw.id
            ORDER BY hw.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'book_id' => $bookId,
            'chapter' => $chapter,
            'verse'   => $verse,
        ]);

        return array_map(fn($row) => array_merge($row, [
            'dutch_links' => json_decode($row['dutch_links'], true),
        ]), $rows);
    }

    private function fetchGreekWords(int $bookId, int $chapter, int $verse): array
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
                COALESCE(
                    json_agg(
                        json_build_object(
                            'tw_id',       tw.id,
                            'word_text',   tw.word_text,
                            'word_pos',    tw.word_position,
                            'method',      lc.method,
                            'score',       lc.score
                        )
                        ORDER BY lc.score DESC
                    ) FILTER (WHERE tw.id IS NOT NULL),
                    '[]'
                ) AS dutch_links
            FROM greek_words gw
            LEFT JOIN word_links wl        ON wl.greek_word_id = gw.id
            LEFT JOIN translation_words tw ON tw.id = wl.translation_word_id
            LEFT JOIN link_confidence lc   ON lc.link_id = wl.id
            WHERE gw.book_id = :book_id
              AND gw.chapter = :chapter
              AND gw.verse   = :verse
            GROUP BY gw.id
            ORDER BY gw.word_position
        SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'book_id' => $bookId,
            'chapter' => $chapter,
            'verse'   => $verse,
        ]);

        return array_map(fn($row) => array_merge($row, [
            'dutch_links' => json_decode($row['dutch_links'], true),
        ]), $rows);
    }

    private function fetchDutchVerse(int $bookId, int $chapter, int $verse): array
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
                -- Best link info for colour-coding
                (
                    SELECT lc2.method
                    FROM word_links wl2
                    JOIN link_confidence lc2 ON lc2.link_id = wl2.id
                    WHERE wl2.translation_word_id = tw.id
                    ORDER BY lc2.score DESC
                    LIMIT 1
                ) AS best_method,
                (
                    SELECT lc2.score
                    FROM word_links wl2
                    JOIN link_confidence lc2 ON lc2.link_id = wl2.id
                    WHERE wl2.translation_word_id = tw.id
                    ORDER BY lc2.score DESC
                    LIMIT 1
                ) AS best_score
            FROM translation_verses tv
            JOIN translation_words tw ON tw.verse_id = tv.id
            WHERE tv.translation_id = 1
              AND tv.book_id = :book_id
              AND tv.chapter = :chapter
              AND tv.verse   = :verse
            ORDER BY tw.word_position
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            'book_id' => $bookId,
            'chapter' => $chapter,
            'verse'   => $verse,
        ]);
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

    /** Coverage statistics: how many source words have at least one link. */
    public function getCoverageStats(): array
    {
        $sql = <<<SQL
            SELECT
                'OT' AS testament,
                COUNT(DISTINCT hw.id)                                         AS total_words,
                COUNT(DISTINCT wl.hebrew_word_id)                             AS linked_words,
                COUNT(DISTINCT CASE WHEN lc.method = 'manual'    THEN wl.id END) AS manual_links,
                COUNT(DISTINCT CASE WHEN lc.method = 'pivot'     THEN wl.id END) AS pivot_links,
                COUNT(DISTINCT CASE WHEN lc.method = 'heuristic' THEN wl.id END) AS heuristic_links
            FROM hebrew_words hw
            LEFT JOIN word_links wl    ON wl.hebrew_word_id = hw.id
            LEFT JOIN link_confidence lc ON lc.link_id = wl.id
            UNION ALL
            SELECT
                'NT',
                COUNT(DISTINCT gw.id),
                COUNT(DISTINCT wl.greek_word_id),
                COUNT(DISTINCT CASE WHEN lc.method = 'manual'    THEN wl.id END),
                COUNT(DISTINCT CASE WHEN lc.method = 'pivot'     THEN wl.id END),
                COUNT(DISTINCT CASE WHEN lc.method = 'heuristic' THEN wl.id END)
            FROM greek_words gw
            LEFT JOIN word_links wl    ON wl.greek_word_id = gw.id
            LEFT JOIN link_confidence lc ON lc.link_id = wl.id
        SQL;

        return $this->connection->fetchAllAssociative($sql);
    }
}
