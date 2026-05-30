<?php

namespace App\Tests\Unit\Repository;

use App\Repository\LinkingRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LinkingRepository.
 *
 * The DBAL Connection is fully mocked so no database is required.
 */
class LinkingRepositoryTest extends TestCase
{
    private Connection&MockObject $conn;
    private LinkingRepository $repo;

    protected function setUp(): void
    {
        $this->conn = $this->createMock(Connection::class);
        $this->repo = new LinkingRepository($this->conn);
    }

    // ── findTranslationCodeByLinkId ───────────────────────────────────────────

    public function testFindTranslationCodeByLinkIdReturnsSvForKnownLink(): void
    {
        $this->conn
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('word_links'),
                $this->identicalTo(['link_id' => 7])
            )
            ->willReturn('SV');

        $this->assertSame('SV', $this->repo->findTranslationCodeByLinkId(7));
    }

    public function testFindTranslationCodeByLinkIdReturnsHsvForHsvLink(): void
    {
        $this->conn->method('fetchOne')->willReturn('HSV');

        $this->assertSame('HSV', $this->repo->findTranslationCodeByLinkId(42));
    }

    public function testFindTranslationCodeByLinkIdReturnsNullForMissingLink(): void
    {
        $this->conn->method('fetchOne')->willReturn(false);

        $this->assertNull($this->repo->findTranslationCodeByLinkId(999));
    }

    // ── saveManualLinks — empty path (no translation) ────────────────────────

    public function testSaveManualLinksEmptyDeletesExistingLinksAndInsertsEmptyRecord(): void
    {
        $executeCalls = [];
        $this->conn
            ->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$executeCalls) {
                $executeCalls[] = ['sql' => $sql, 'params' => $params];
                return 1;
            });

        $this->repo->saveManualLinks('HE', 5, [], 1);

        // First call must DELETE word_links for this source word + translation
        $this->assertStringContainsString('DELETE FROM word_links', $executeCalls[0]['sql']);
        $this->assertSame(5, $executeCalls[0]['params']['src_id']);
        $this->assertSame(1, $executeCalls[0]['params']['translation_id']);

        // Second call must INSERT / upsert manual_empty_links
        $this->assertStringContainsString('manual_empty_links', $executeCalls[1]['sql']);
        $this->assertSame('HE', $executeCalls[1]['params']['lang']);
        $this->assertSame(5, $executeCalls[1]['params']['src_id']);
        $this->assertSame(1, $executeCalls[1]['params']['translation_id']);
    }

    // ── saveManualLinks — normal path (one Dutch word) ────────────────────────

    public function testSaveManualLinksWithTwIdDeletesEmptyLinkAndInsertsWordLink(): void
    {
        $executeCalls = [];
        $this->conn
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$executeCalls) {
                $executeCalls[] = ['sql' => $sql, 'params' => $params];
                return 1;
            });

        // fetchOne is used to get the newly inserted link id (RETURNING id)
        $this->conn
            ->method('fetchOne')
            ->willReturn(99);

        $this->repo->saveManualLinks('HE', 5, [101], 1);

        // 1st call: DELETE existing word_links
        $this->assertStringContainsString('DELETE FROM word_links', $executeCalls[0]['sql']);

        // 2nd call: DELETE manual_empty_links
        $this->assertStringContainsString('DELETE FROM manual_empty_links', $executeCalls[1]['sql']);

        // 3rd call: INSERT link_confidence (after fetchOne returns the link id)
        $this->assertStringContainsString('link_confidence', $executeCalls[2]['sql']);
        $this->assertSame(99, $executeCalls[2]['params']['link_id']);
    }

    public function testSaveManualLinksWithMultipleTwIdsInsertsOneConfidenceRowEach(): void
    {
        $confidenceInserts = 0;
        $this->conn
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$confidenceInserts) {
                if (str_contains($sql, 'link_confidence')) {
                    $confidenceInserts++;
                }
                return 1;
            });

        $this->conn->method('fetchOne')->willReturn(1);

        $this->repo->saveManualLinks('GR', 10, [201, 202, 203], 2);

        $this->assertSame(3, $confidenceInserts);
    }

    // ── saveManualLinks — ON CONFLICT idempotency ────────────────────────────

    public function testSaveManualLinksEmptyPathUsesOnConflictUpsert(): void
    {
        $capturedSql = '';
        $this->conn
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                if (str_contains($sql, 'manual_empty_links')) {
                    $capturedSql = $sql;
                }
                return 1;
            });

        $this->repo->saveManualLinks('GR', 3, [], 2);

        $this->assertStringContainsString('ON CONFLICT', $capturedSql);
        $this->assertStringContainsString('DO UPDATE', $capturedSql);
    }

    // ── word_links ON CONFLICT for duplicate prevention ───────────────────────

    public function testSaveManualLinksNormalPathUsesOnConflictReturning(): void
    {
        $insertSql = '';
        $this->conn
            ->method('fetchOne')
            ->willReturnCallback(function (string $sql) use (&$insertSql) {
                $insertSql = $sql;
                return 55;
            });
        $this->conn->method('executeStatement')->willReturn(1);

        $this->repo->saveManualLinks('HE', 1, [50], 1);

        $this->assertStringContainsString('ON CONFLICT', $insertSql);
        $this->assertStringContainsString('RETURNING id', $insertSql);
    }

    // ── deleteLink ───────────────────────────────────────────────────────────

    public function testDeleteLinkExecutesDeleteWithCorrectId(): void
    {
        $this->conn
            ->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE FROM word_links'),
                $this->identicalTo(['id' => 13])
            );

        $this->repo->deleteLink(13);
    }
}
