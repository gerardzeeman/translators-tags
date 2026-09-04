<?php

namespace App\Tests\Unit\Service\Alignment;

use App\Service\Alignment\HistoricalAlignmentScoreService;
use PHPUnit\Framework\TestCase;

class HistoricalAlignmentScoreServiceTest extends TestCase
{
    private HistoricalAlignmentScoreService $service;

    protected function setUp(): void
    {
        $this->service = new HistoricalAlignmentScoreService();
    }

    /**
     * Hand-worked example exercising every rule in plan sectie 4:
     *  - SV1657 word 1 (w1) links to all three targets with different
     *    scores -- it must contribute THREE separate scores, once per pair.
     *  - SV1657 word 2 (w2) is a particle_drop -- must count NOWHERE
     *    (neither numerator nor denominator), in any of the three pairs.
     *  - SVGBS's word (g1) has no link at all -- scores 0.0, still counted.
     *  - manual links score 1.0 regardless of any stored `score` value.
     */
    public function testVerseScoreMatchesHandWorkedExample(): void
    {
        $wordsByCode = [
            'SV1657' => [
                ['id' => 1, 'word_position' => 1, 'word_text' => 'w1'],
                ['id' => 2, 'word_position' => 2, 'word_text' => 'w2', 'alignment_note' => 'particle_drop'],
            ],
            'SV' => [
                ['id' => 10, 'word_position' => 1, 'word_text' => 't1'],
            ],
            'SVGBS' => [
                ['id' => 20, 'word_position' => 1, 'word_text' => 'g1'],
            ],
            'HSV' => [
                ['id' => 30, 'word_position' => 1, 'word_text' => 'h1'],
            ],
        ];

        $links = [
            ['word_a_id' => 1, 'word_b_id' => 10, 'method' => 'manual', 'score' => 0.42], // score ignored: manual = 1.0
            ['word_a_id' => 1, 'word_b_id' => 30, 'method' => 'window', 'score' => 0.7],
            // no link at all between w1/w2 and g1, and none for g1 at all
        ];

        $result = $this->service->computeVerseScore($wordsByCode, $links, 'SV1657');

        // 6 counted positions: (w1,t1) + (w1,g1) + (w1,h1) + t1 + g1 + h1.
        // w2 excluded entirely (particle_drop) -- would otherwise add 3 more.
        $this->assertSame(6, $result['counted_positions']);
        $this->assertEqualsWithDelta(1.0 + 1.0 + 0.0 + 0.0 + 0.7 + 0.7, $result['total_score'], 1e-9);
        $this->assertEqualsWithDelta((3.4 / 6) * 100, $result['percent'], 1e-6);

        $this->assertSame(1.0, $result['pivot_word_scores'][1]['SV']);
        $this->assertSame(0.0, $result['pivot_word_scores'][1]['SVGBS']);
        $this->assertSame(0.7, $result['pivot_word_scores'][1]['HSV']);
        $this->assertArrayNotHasKey(2, $result['pivot_word_scores'], 'particle_drop word must not appear at all');

        $this->assertSame(1.0, $result['target_word_scores'][10]);
        $this->assertSame(0.0, $result['target_word_scores'][20]);
        $this->assertSame(0.7, $result['target_word_scores'][30]);

        $this->assertArrayHasKey(20, $result['unlinked_word_ids']);
        $this->assertArrayNotHasKey(10, $result['unlinked_word_ids']);
        $this->assertArrayNotHasKey(30, $result['unlinked_word_ids']);
        // w1 has a link in at least one pair but is fully unlinked in the
        // SVGBS pair specifically -- unlinked_word_ids is word-id-keyed
        // (not per-pair), so it correctly stays OUT since it IS linked
        // overall (SV and HSV pairs).
        $this->assertArrayNotHasKey(1, $result['unlinked_word_ids']);
    }

    public function testEmptyVerseScoresFullPercent(): void
    {
        $wordsByCode = ['SV1657' => [], 'SV' => [], 'SVGBS' => [], 'HSV' => []];
        $result = $this->service->computeVerseScore($wordsByCode, [], 'SV1657');

        $this->assertSame(0, $result['counted_positions']);
        $this->assertSame(100.0, $result['percent']);
    }

    public function testManualEmptyIsANegativeAssertionNotAPositiveLink(): void
    {
        $wordsByCode = [
            'SV1657' => [['id' => 1, 'word_position' => 1, 'word_text' => 'w1']],
            'SV' => [['id' => 10, 'word_position' => 1, 'word_text' => 't1']],
        ];
        $links = [['word_a_id' => 1, 'word_b_id' => 10, 'method' => 'manual_empty', 'score' => null]];

        $result = $this->service->computeVerseScore($wordsByCode, $links, 'SV1657');

        // Confirmed-unrelated still scores as an (accurately) unlinked gap --
        // it's a real, reviewed gap, so it's counted but contributes 0.0.
        $this->assertSame(0.0, $result['pivot_word_scores'][1]['SV']);
        $this->assertSame(0.0, $result['target_word_scores'][10]);
        $this->assertSame(2, $result['counted_positions']);
        $this->assertArrayHasKey(1, $result['unlinked_word_ids']);
        $this->assertArrayHasKey(10, $result['unlinked_word_ids']);
    }
}
