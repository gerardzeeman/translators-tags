<?php

namespace App\Tests\Unit\Repository;

use App\Repository\PassageRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PassageRepository.
 *
 * Verifies that the correct SQL parameters are passed for each translation
 * and that the JSON dutch_links column is decoded properly.
 */
class PassageRepositoryTest extends TestCase
{
    private Connection&MockObject $conn;
    private PassageRepository $repo;

    protected function setUp(): void
    {
        $this->conn = $this->createMock(Connection::class);
        $this->repo = new PassageRepository($this->conn);
    }

    // ── fetchPassage: testament detection ────────────────────────────────────

    public function testFetchPassageDetectsOldTestamentForBookId1(): void
    {
        $this->conn->method('fetchAllAssociative')->willReturn([]);

        $result = $this->repo->fetchPassage(1, 1, 1, 1);

        $this->assertSame('OT', $result['testament']);
    }

    public function testFetchPassageDetectsNewTestamentForBookId40(): void
    {
        $this->conn->method('fetchAllAssociative')->willReturn([]);

        $result = $this->repo->fetchPassage(40, 1, 1, 1);

        $this->assertSame('NT', $result['testament']);
    }

    public function testFetchPassageDetectsOldTestamentForBookId39(): void
    {
        $this->conn->method('fetchAllAssociative')->willReturn([]);

        $result = $this->repo->fetchPassage(39, 1, 1, 1);

        $this->assertSame('OT', $result['testament']);
    }

    // ── fetchPassage: translation_id scoping ─────────────────────────────────

    public function testFetchPassagePassesTranslationIdToAllQueries(): void
    {
        $capturedParams = [];
        $this->conn
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
                $capturedParams[] = $params;
                return [];
            });

        $this->repo->fetchPassage(1, 1, 1, 2);

        // Both the source-words query and the dutch-verse query must carry translation_id=2
        foreach ($capturedParams as $params) {
            $this->assertArrayHasKey('translation_id', $params);
            $this->assertSame(2, $params['translation_id']);
        }
    }

    public function testFetchPassagePassesCorrectBookChapterVerse(): void
    {
        $capturedParams = [];
        $this->conn
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params) use (&$capturedParams) {
                $capturedParams[] = $params;
                return [];
            });

        $this->repo->fetchPassage(3, 7, 14, 1);

        foreach ($capturedParams as $params) {
            $this->assertSame(3,  $params['book_id']);
            $this->assertSame(7,  $params['chapter']);
            $this->assertSame(14, $params['verse']);
        }
    }

    // ── fetchPassage: dutch_links JSON decoding ───────────────────────────────

    public function testFetchPassageDecodesJsonDutchLinksForHebrewWords(): void
    {
        $hebrewRow = [
            'id'            => 1,
            'word_position' => 1,
            'word_text'     => 'בְּרֵאשִׁית',
            'transliteration' => 'bereshit',
            'lemma'         => null,
            'strongs'       => 'H7225',
            'morph_code'    => 'HR/Ncfsa',
            'is_ketiv'      => false,
            'has_qere'      => false,
            'dutch_links'   => '[{"tw_id":10,"word_text":"In","word_pos":1,"method":"manual","score":1}]',
        ];

        $dutchRow = [
            'verse_id'    => 5,
            'verse_text'  => 'In den beginne…',
            'word_id'     => 10,
            'word_position' => 1,
            'word_text'   => 'In',
            'char_start'  => 0,
            'char_end'    => 2,
            'best_method' => 'manual',
            'best_score'  => '1.000',
        ];

        $this->conn
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [$hebrewRow],  // source words query
                [$dutchRow],   // dutch verse query
            );

        $result = $this->repo->fetchPassage(1, 1, 1, 1);

        $this->assertIsArray($result['source_words'][0]['dutch_links']);
        $this->assertSame(10, $result['source_words'][0]['dutch_links'][0]['tw_id']);
        $this->assertSame('manual', $result['source_words'][0]['dutch_links'][0]['method']);
    }

    public function testFetchPassageDecodesEmptyJsonDutchLinksAsEmptyArray(): void
    {
        $hebrewRow = [
            'id' => 1, 'word_position' => 1, 'word_text' => 'test',
            'transliteration' => null, 'lemma' => null, 'strongs' => null,
            'morph_code' => null, 'is_ketiv' => false, 'has_qere' => false,
            'dutch_links' => '[]',
        ];

        $this->conn
            ->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls([$hebrewRow], []);

        $result = $this->repo->fetchPassage(1, 1, 1, 1);

        $this->assertSame([], $result['source_words'][0]['dutch_links']);
    }

    // ── getChapterVerseCounts ─────────────────────────────────────────────────

    public function testGetChapterVerseCountsUsesHebrewWordsForOT(): void
    {
        $capturedSql = '';
        $this->conn
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return [];
            });

        $this->repo->getChapterVerseCounts(1);

        $this->assertStringContainsString('hebrew_words', $capturedSql);
        $this->assertStringNotContainsString('greek_words', $capturedSql);
    }

    public function testGetChapterVerseCountsUsesGreekWordsForNT(): void
    {
        $capturedSql = '';
        $this->conn
            ->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return [];
            });

        $this->repo->getChapterVerseCounts(40);

        $this->assertStringContainsString('greek_words', $capturedSql);
        $this->assertStringNotContainsString('hebrew_words', $capturedSql);
    }
}
