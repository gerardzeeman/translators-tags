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
                    regexp_replace(hw.strongs, '[A-Za-z]+$', ''),
                    '^([HG])0+(\d)', '\1\2'
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
                    regexp_replace(gw.strongs, '[A-Za-z]+$', ''),
                    '^([HG])0+(\d)', '\1\2'
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
            WHERE regexp_replace(strongs, '[A-Za-z]+$', '') = :strongs
              AND transliteration IS NOT NULL
              AND transliteration <> ''
            GROUP BY transliteration
            ORDER BY occurrence_count DESC, transliteration
        SQL;

        return $this->connection->fetchAllAssociative($sql, ['strongs' => $this->padStrongsId($strongs)]);
    }

    /**
     * All verses containing a Strong's number, with source + Dutch words for linking.
     */
    public function fetchStrongsVerses(string $strongs, int $translationId): array
    {
        $testament = str_starts_with($strongs, 'H') ? 'OT' : 'NT';
        $table     = $testament === 'OT' ? 'hebrew_words' : 'greek_words';

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
            WHERE regexp_replace(sw.strongs, '[A-Za-z]+$', '') = :strongs
            ORDER BY sw.book_id, sw.chapter, sw.verse
        SQL;

        $verses = $this->connection->fetchAllAssociative($sql, ['strongs' => $this->padStrongsId($strongs)]);

        // For each verse, fetch the full linking data
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

    /**
     * Return the translation code (e.g. 'SV', 'HSV') for a given word_links row.
     * Used to authorise delete requests.
     */
    public function findTranslationCodeByLinkId(int $linkId): ?string
    {
        $code = $this->connection->fetchOne(
            "SELECT t.code
             FROM word_links wl
             JOIN translation_words tw  ON tw.id  = wl.translation_word_id
             JOIN translation_verses tv ON tv.id  = tw.verse_id
             JOIN translations t        ON t.id   = tv.translation_id
             WHERE wl.id = :link_id",
            ['link_id' => $linkId]
        );

        return $code ?: null;
    }
}
