<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Pure cross-references ("kruisverwijzingen"), anchored to a specific word
 * within a verse and rendered inline as a small letter marker (a, b, c, ...)
 * -- gescraped uit de kanttekeningen/verwijsteksten-apparatuur van HSV en
 * SV(GBS) (zie ingest/parse_hsv_cross_references.py en
 * ingest/parse_svgbs_cross_references.py). Niet gekoppeld aan een
 * translation_id -- SV Jongbloed heeft geen eigen apparatuur en hergebruikt
 * daarom de SVGBS-rijen (near-identieke brontekst).
 *
 * word_position 0 means "before the first word of the verse" -- there is no
 * word at that position to attach to, so callers render it as a verse-level
 * prefix instead of enriching a specific word (see fetchForChapter/
 * fetchForVerse return shape).
 */
class CrossReferenceRepository
{
    private const SOURCE_BY_TRANSLATION_CODE = [
        'HSV'    => 'HSV',
        'SV'     => 'SVGBS',
        'SVGBS'  => 'SVGBS',
        'SV1657' => 'SV1657',
    ];

    public function __construct(private readonly Connection $connection) {}

    public function sourceForTranslationCode(string $code): ?string
    {
        return self::SOURCE_BY_TRANSLATION_CODE[$code] ?? null;
    }

    /**
     * @return array<int, array<int, list<array{letter: string, targets: list<array>}>>>
     *         verse number => word_position => list of {letter, targets}
     */
    public function fetchForChapter(int $bookId, int $chapter, string $source): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT cr.verse, cr.letter, cr.word_position, cr.ordinal, cr.label,
                    tb.usfm_code AS target_usfm, tb.name_nl AS target_name,
                    cr.target_chapter, cr.target_verse
             FROM cross_references cr
             JOIN books tb ON tb.id = cr.target_book_id
             WHERE cr.source = :source AND cr.book_id = :book_id AND cr.chapter = :chapter
             ORDER BY cr.verse, cr.word_position, cr.letter, cr.ordinal",
            ['source' => $source, 'book_id' => $bookId, 'chapter' => $chapter]
        );

        $byVerse = [];
        foreach ($rows as $row) {
            $verse = (int) $row['verse'];
            $byVerse[$verse] ??= [];
            self::appendRow($byVerse[$verse], $row);
        }
        return $byVerse;
    }

    /** @return array<int, list<array{letter: string, targets: list<array>}>> word_position => list of {letter, targets} */
    public function fetchForVerse(int $bookId, int $chapter, int $verse, string $source): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT cr.letter, cr.word_position, cr.ordinal, cr.label,
                    tb.usfm_code AS target_usfm, tb.name_nl AS target_name,
                    cr.target_chapter, cr.target_verse
             FROM cross_references cr
             JOIN books tb ON tb.id = cr.target_book_id
             WHERE cr.source = :source AND cr.book_id = :book_id AND cr.chapter = :chapter AND cr.verse = :verse
             ORDER BY cr.word_position, cr.letter, cr.ordinal",
            ['source' => $source, 'book_id' => $bookId, 'chapter' => $chapter, 'verse' => $verse]
        );

        $byPosition = [];
        foreach ($rows as $row) {
            self::appendRow($byPosition, $row);
        }
        return $byPosition;
    }

    /** @param array<int, list<array{letter: string, targets: list<array>}>> $byPosition */
    private static function appendRow(array &$byPosition, array $row): void
    {
        $pos    = (int) $row['word_position'];
        $letter = $row['letter'];

        $groups = &$byPosition[$pos];
        $groups ??= [];

        $idx = null;
        foreach ($groups as $i => $g) {
            if ($g['letter'] === $letter) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            $groups[] = ['letter' => $letter, 'targets' => []];
            $idx = array_key_last($groups);
        }

        $groups[$idx]['targets'][] = [
            'label'          => $row['label'],
            'target_usfm'    => $row['target_usfm'],
            'target_name'    => $row['target_name'],
            'target_chapter' => (int) $row['target_chapter'],
            'target_verse'   => (int) $row['target_verse'],
        ];
        unset($groups);
    }
}
