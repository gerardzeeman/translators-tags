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

    public function testCompoundTargetAttachesToFirstSourceWordsRow(): void
    {
        // 3 SV1657 words -> 1 SV word (compound), like 'te samen gevoeght' -> 'samengevoegd'.
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

        $this->assertCount(3, $rows);
        $this->assertSame([10], array_column($rows[0]['SV'], 'id'), 'compound target sits with the FIRST source word');
        $this->assertSame([], $rows[1]['SV']);
        $this->assertSame([], $rows[2]['SV']);
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
}
