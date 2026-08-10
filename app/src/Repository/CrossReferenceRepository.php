<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Verse-level "zie ook"-verwijzingen, gescraped uit de kanttekeningen/
 * verwijsteksten-apparatuur van HSV en SV(GBS) (zie ingest/parse_hsv_cross_references.py
 * en ingest/parse_svgbs_cross_references.py). Niet per-woord en niet gekoppeld
 * aan een translation_id -- SV Jongbloed heeft geen eigen apparatuur en
 * hergebruikt daarom de SVGBS-rijen (near-identieke brontekst).
 */
class CrossReferenceRepository
{
    private const SOURCE_BY_TRANSLATION_CODE = [
        'HSV'   => 'HSV',
        'SV'    => 'SVGBS',
        'SVGBS' => 'SVGBS',
    ];

    public function __construct(private readonly Connection $connection) {}

    public function sourceForTranslationCode(string $code): ?string
    {
        return self::SOURCE_BY_TRANSLATION_CODE[$code] ?? null;
    }

    /**
     * @return array<int, list<array{label: string, target_usfm: string, target_name: string, target_chapter: int, target_verse: int}>>
     *         verse number => ordered list of references
     */
    public function fetchForChapter(int $bookId, int $chapter, string $source): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT cr.verse, cr.label, tb.usfm_code AS target_usfm, tb.name_nl AS target_name,
                    cr.target_chapter, cr.target_verse
             FROM cross_references cr
             JOIN books tb ON tb.id = cr.target_book_id
             WHERE cr.source = :source AND cr.book_id = :book_id AND cr.chapter = :chapter
             ORDER BY cr.verse, cr.ordinal",
            ['source' => $source, 'book_id' => $bookId, 'chapter' => $chapter]
        );

        $byVerse = [];
        foreach ($rows as $row) {
            $byVerse[(int) $row['verse']][] = $row;
        }
        return $byVerse;
    }

    /** @return list<array{label: string, target_usfm: string, target_name: string, target_chapter: int, target_verse: int}> */
    public function fetchForVerse(int $bookId, int $chapter, int $verse, string $source): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT cr.label, tb.usfm_code AS target_usfm, tb.name_nl AS target_name,
                    cr.target_chapter, cr.target_verse
             FROM cross_references cr
             JOIN books tb ON tb.id = cr.target_book_id
             WHERE cr.source = :source AND cr.book_id = :book_id AND cr.chapter = :chapter AND cr.verse = :verse
             ORDER BY cr.ordinal",
            ['source' => $source, 'book_id' => $bookId, 'chapter' => $chapter, 'verse' => $verse]
        );
    }
}
