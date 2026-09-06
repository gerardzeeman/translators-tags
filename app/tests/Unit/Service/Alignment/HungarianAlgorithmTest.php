<?php

namespace App\Tests\Unit\Service\Alignment;

use App\Service\Alignment\HungarianAlgorithm;
use PHPUnit\Framework\TestCase;

class HungarianAlgorithmTest extends TestCase
{
    private HungarianAlgorithm $solver;

    protected function setUp(): void
    {
        $this->solver = new HungarianAlgorithm();
    }

    public function testEmptyMatrixReturnsNoPairs(): void
    {
        $this->assertSame([], $this->solver->solve([]));
        $this->assertSame([], $this->solver->solve([[]]));
    }

    public function testSquareMatrixFindsOptimalAssignment(): void
    {
        // Textbook 3x3 example, known optimal assignment cost = 5:
        // (0,1)=2 + (1,0)=2 + (2,2)=1 = 5
        $cost = [
            [4, 2, 8],
            [2, 4, 7],
            [3, 8, 1],
        ];
        $pairs = $this->solver->solve($cost);
        $this->assertCount(3, $pairs);

        $total = 0.0;
        $rows = [];
        $cols = [];
        foreach ($pairs as [$r, $c]) {
            $total += $cost[$r][$c];
            $rows[] = $r;
            $cols[] = $c;
        }
        $this->assertSame(5.0, $total);
        $this->assertSame([0, 1, 2], $rows, 'every row must be assigned exactly once');
        sort($cols);
        $this->assertSame([0, 1, 2], $cols, 'every column must be used exactly once');
    }

    public function testFewerRowsThanColumnsAssignsEveryRow(): void
    {
        // 2 rows, 3 columns: every row MUST be assigned (mandatory), unlike
        // an "optional, pad with 0-cost dummy" approach would allow.
        $cost = [
            [1.0, 5.0, 5.0],
            [5.0, 1.0, 5.0],
        ];
        $pairs = $this->solver->solve($cost);
        $this->assertCount(2, $pairs);

        $rows = array_column($pairs, 0);
        sort($rows);
        $this->assertSame([0, 1], $rows);

        $total = array_sum(array_map(fn($p) => $cost[$p[0]][$p[1]], $pairs));
        $this->assertSame(2.0, $total);
    }

    public function testMoreRowsThanColumnsIsSolvedViaTranspose(): void
    {
        // 3 rows, 2 columns: min(3,2)=2 pairs, every column must be used.
        $cost = [
            [1.0, 9.0],
            [9.0, 1.0],
            [9.0, 9.0],
        ];
        $pairs = $this->solver->solve($cost);
        $this->assertCount(2, $pairs);

        $cols = array_column($pairs, 1);
        sort($cols);
        $this->assertSame([0, 1], $cols);

        $total = array_sum(array_map(fn($p) => $cost[$p[0]][$p[1]], $pairs));
        $this->assertSame(2.0, $total);
    }

    public function testDistinctRowsAndColumnsInResult(): void
    {
        $cost = [
            [0.9, 0.1, 0.4],
            [0.2, 0.8, 0.3],
            [0.5, 0.6, 0.2],
        ];
        $pairs = $this->solver->solve($cost);
        $rows = array_column($pairs, 0);
        $cols = array_column($pairs, 1);
        $this->assertSame($rows, array_unique($rows));
        $this->assertSame($cols, array_unique($cols));
    }
}
