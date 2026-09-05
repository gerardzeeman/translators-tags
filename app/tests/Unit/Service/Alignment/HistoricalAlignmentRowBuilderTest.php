<?php

namespace App\Tests\Unit\Service\Alignment;

use App\Service\Alignment\HistoricalAlignmentRowBuilder;
use PHPUnit\Framework\TestCase;

class HistoricalAlignmentRowBuilderTest extends TestCase
{
    private HistoricalAlignmentRowBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HistoricalAlignmentRowBuilder();
    }

    public function testOneRowPerPivotWordWithSimpleOneToOneLinks(): void
    {
        $wordsByCode = [
            'SV1657' => [
                ['id' => 1, 'word_position' => 1, 'word_text' => 'a'],
                ['id' => 2, 'word_position' => 2, 'word_text' => 'b'],
            ],
            'SV' => [
                ['id' => 10, 'word_position' => 1, 'word_text' => 'a2'],
                ['id' => 11, 'word_position' => 2, 'word_text' => 'b2'],
            ],
        ];
        $links = [
            ['word_a_id' => 1, 'word_b_id' => 10, 'method' => 'anchor', 'score' => 1.0],
            ['word_a_id' => 2, 'word_b_id' => 11, 'method' => 'anchor', 'score' => 1.0],
        ];

        $rows = $this->builder->buildRows($wordsByCode, $links, 'SV1657');

        $this->assertCount(2, $rows);
        $this->assertSame([1], array_column($rows[0]['SV1657'], 'id'));
        $this->assertSame([10], array_column($rows[0]['SV'], 'id'));
        $this->assertSame([2], array_column($rows[1]['SV1657'], 'id'));
        $this->assertSame([11], array_column($rows[1]['SV'], 'id'));
    }

    public function testCompoundGroupSharesOneRow(): void
    {
        // 3 SV1657 words -> 1 SV word (compound), like 'te samen gevoeght' -> 'samengevoegd'.
        // All 4 words are one connected component (linked, directly or
        // transitively, to each other), so they all land in ONE row --
        // that's the whole point of grouping by connected component rather
        // than "attach to the first source word's row only".
        $wordsByCode = [
            'SV1657' => [
                ['id' => 1, 'word_position' => 1, 'word_text' => 'te'],
                ['id' => 2, 'word_position' => 2, 'word_text' => 'samen'],
                ['id' => 3, 'word_position' => 3, 'word_text' => 'gevoeght'],
            ],
            'SV' => [
                ['id' => 10, 'word_position' => 1, 'word_text' => 'samengevoegd'],
            ],
        ];
        $links = [
            ['word_a_id' => 1, 'word_b_id' => 10, 'method' => 'compound', 'score' => 1.0],
            ['word_a_id' => 2, 'word_b_id' => 10, 'method' => 'compound', 'score' => 1.0],
            ['word_a_id' => 3, 'word_b_id' => 10, 'method' => 'compound', 'score' => 1.0],
        ];

        $rows = $this->builder->buildRows($wordsByCode, $links, 'SV1657');

        $this->assertCount(1, $rows);
        $this->assertSame([1, 2, 3], array_column($rows[0]['SV1657'], 'id'), 'own-column reading order preserved within the shared row');
        $this->assertSame([10], array_column($rows[0]['SV'], 'id'));
    }

    public function testUnlinkedAdditionAttachesToNearestPrecedingLinkedNeighbour(): void
    {
        // HSV 'roep ertoe op' has no SV1657 counterpart at all; should attach
        // to the row of the nearest EARLIER linked HSV word ('ik').
        $wordsByCode = [
            'SV1657' => [
                ['id' => 1, 'word_position' => 1, 'word_text' => 'ick'],
                ['id' => 2, 'word_position' => 2, 'word_text' => 'bidde'],
            ],
            'HSV' => [
                ['id' => 20, 'word_position' => 1, 'word_text' => 'ik'],
                ['id' => 21, 'word_position' => 2, 'word_text' => 'roep'],
                ['id' => 22, 'word_position' => 3, 'word_text' => 'ertoe'],
                ['id' => 23, 'word_position' => 4, 'word_text' => 'op'],
            ],
        ];
        $links = [
            ['word_a_id' => 1, 'word_b_id' => 20, 'method' => 'anchor', 'score' => 1.0],
        ];

        $rows = $this->builder->buildRows($wordsByCode, $links, 'SV1657');

        $this->assertSame([20, 21, 22, 23], array_column($rows[0]['HSV'], 'id'), 'ik + the three unlinked additions all land in row 0');
        $this->assertSame([], $rows[1]['HSV']);
    }

    public function testUnlinkedAdditionBeforeAnyLinkFallsBackToFollowingNeighbour(): void
    {
        $wordsByCode = [
            'SV1657' => [
                ['id' => 1, 'word_position' => 1, 'word_text' => 'a'],
            ],
            'HSV' => [
                ['id' => 20, 'word_position' => 1, 'word_text' => 'lead-in'],
                ['id' => 21, 'word_position' => 2, 'word_text' => 'a2'],
            ],
        ];
        $links = [
            ['word_a_id' => 1, 'word_b_id' => 21, 'method' => 'anchor', 'score' => 1.0],
        ];

        $rows = $this->builder->buildRows($wordsByCode, $links, 'SV1657');

        $this->assertSame([20, 21], array_column($rows[0]['HSV'], 'id'));
    }

    public function testMovedLinkPlacesTargetWordAtItsPivotRowNotItsOwnColumnPosition(): void
    {
        $wordsByCode = [
            'SV1657' => [
                ['id' => 1, 'word_position' => 1, 'word_text' => 'a'],
                ['id' => 2, 'word_position' => 2, 'word_text' => 'b'],
                ['id' => 3, 'word_position' => 3, 'word_text' => 'c'],
            ],
            'HSV' => [
                ['id' => 20, 'word_position' => 1, 'word_text' => 'c2'],
                ['id' => 21, 'word_position' => 2, 'word_text' => 'a2'],
                ['id' => 22, 'word_position' => 3, 'word_text' => 'b2'],
            ],
        ];
        // c2 (HSV pos 1) is really c's counterpart (SV1657 pos 3) -- moved earlier in HSV.
        $links = [
            ['word_a_id' => 1, 'word_b_id' => 21, 'method' => 'anchor', 'score' => 1.0],
            ['word_a_id' => 2, 'word_b_id' => 22, 'method' => 'anchor', 'score' => 1.0],
            ['word_a_id' => 3, 'word_b_id' => 20, 'method' => 'moved', 'score' => 1.0],
        ];

        $rows = $this->builder->buildRows($wordsByCode, $links, 'SV1657');

        $this->assertSame([21], array_column($rows[0]['HSV'], 'id'));
        $this->assertSame([22], array_column($rows[1]['HSV'], 'id'));
        $this->assertSame([20], array_column($rows[2]['HSV'], 'id'), "'c2' follows its link to row 2 (SV1657 'c'), not its own reading position");
    }

    public function testFullyUnlinkedColumnPlacesEverythingInRowZero(): void
    {
        $wordsByCode = [
            'SV1657' => [['id' => 1, 'word_position' => 1, 'word_text' => 'a']],
            'HSV' => [
                ['id' => 20, 'word_position' => 1, 'word_text' => 'x'],
                ['id' => 21, 'word_position' => 2, 'word_text' => 'y'],
            ],
        ];

        $rows = $this->builder->buildRows($wordsByCode, [], 'SV1657');

        $this->assertSame([20, 21], array_column($rows[0]['HSV'], 'id'));
    }

    public function testEmptyVerseReturnsNoRows(): void
    {
        $rows = $this->builder->buildRows(['SV1657' => [], 'SV' => []], [], 'SV1657');
        $this->assertSame([], $rows);
    }

    /**
     * Chain topology (SV1657<->SV<->SVGBS<->HSV): a word transitively
     * linked across all three hops lands in ONE row spanning all four
     * columns, with SV1657 (position 1 in the chain) as backbone -- the
     * row-builder has no topology-specific logic, this just falls out of
     * connected-component grouping over whichever links it's given.
     */
    public function testChainTopologyGroupsAllFourColumnsIntoOneRowViaTransitiveLinks(): void
    {
        $wordsByCode = [
            'SV1657' => [['id' => 1, 'word_position' => 1, 'word_text' => 'godt']],
            'SV' => [['id' => 10, 'word_position' => 1, 'word_text' => 'god']],
            'SVGBS' => [['id' => 20, 'word_position' => 1, 'word_text' => 'God']],
            'HSV' => [['id' => 30, 'word_position' => 1, 'word_text' => 'God']],
        ];
        // Each pair only ever links to its chain neighbour -- no direct
        // SV1657<->SVGBS or SV1657<->HSV row exists, yet they must still
        // end up together via the SV<->SVGBS and SVGBS<->HSV hops.
        $links = [
            ['word_a_id' => 1, 'word_b_id' => 10, 'method' => 'anchor', 'score' => 1.0],
            ['word_a_id' => 10, 'word_b_id' => 20, 'method' => 'anchor', 'score' => 1.0],
            ['word_a_id' => 20, 'word_b_id' => 30, 'method' => 'anchor', 'score' => 1.0],
        ];

        $rows = $this->builder->buildRows($wordsByCode, $links, 'SV1657');

        $this->assertCount(1, $rows);
        $this->assertSame([1], array_column($rows[0]['SV1657'], 'id'));
        $this->assertSame([10], array_column($rows[0]['SV'], 'id'));
        $this->assertSame([20], array_column($rows[0]['SVGBS'], 'id'));
        $this->assertSame([30], array_column($rows[0]['HSV'], 'id'));
    }

    /**
     * A break in the chain before it reaches the backbone (SV1657): SVGBS
     * and HSV are linked to each other but that segment never connects back
     * to SV1657/SV, so their component has no backbone word at all. It must
     * still get placed (via the nearest-neighbour fallback), not dropped or
     * crash.
     */
    public function testChainGapNotReachingBackboneFallsBackToNearestNeighbourInAnyMemberColumn(): void
    {
        $wordsByCode = [
            'SV1657' => [
                ['id' => 1, 'word_position' => 1, 'word_text' => 'een'],
                ['id' => 2, 'word_position' => 2, 'word_text' => 'twee'],
            ],
            'SV' => [
                ['id' => 10, 'word_position' => 1, 'word_text' => 'een'],
                ['id' => 11, 'word_position' => 2, 'word_text' => 'twee'],
            ],
            'SVGBS' => [
                ['id' => 20, 'word_position' => 1, 'word_text' => 'een'],
                // 'twee' has no SVGBS counterpart at all in this made-up example
            ],
            'HSV' => [
                ['id' => 30, 'word_position' => 1, 'word_text' => 'een'],
                ['id' => 31, 'word_position' => 2, 'word_text' => 'apart'], // linked only to SVGBS gap-filler below
            ],
        ];
        $links = [
            ['word_a_id' => 1, 'word_b_id' => 10, 'method' => 'anchor', 'score' => 1.0],
            ['word_a_id' => 10, 'word_b_id' => 20, 'method' => 'anchor', 'score' => 1.0],
            ['word_a_id' => 20, 'word_b_id' => 30, 'method' => 'anchor', 'score' => 1.0],
            ['word_a_id' => 2, 'word_b_id' => 11, 'method' => 'anchor', 'score' => 1.0],
            // SVGBS has nothing for 'twee', but HSV's extra word 31 links
            // only to... nothing on the backbone side. It's an isolated
            // SVGBS-less, SV1657-less addition -- must not crash, must land
            // somewhere sane (nearest neighbour in its own HSV column: word 30).
        ];

        $rows = $this->builder->buildRows($wordsByCode, $links, 'SV1657');

        $this->assertCount(2, $rows);
        $this->assertSame([1], array_column($rows[0]['SV1657'], 'id'));
        $this->assertSame([20], array_column($rows[0]['SVGBS'], 'id'));
        $this->assertSame([30, 31], array_column($rows[0]['HSV'], 'id'), 'the isolated HSV addition attaches to its nearest HSV neighbour');
        $this->assertSame([2], array_column($rows[1]['SV1657'], 'id'));
        $this->assertSame([], $rows[1]['SVGBS'], 'no SVGBS word for this SV1657/SV pair at all');
    }
}
