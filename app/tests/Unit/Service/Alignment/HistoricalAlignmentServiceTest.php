<?php

namespace App\Tests\Unit\Service\Alignment;

use App\Service\Alignment\HistoricalAlignmentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression test against ground truth captured by actually running
 * align.py's own `align_pair()` (with scipy's `linear_sum_assignment`) on
 * its WITNESSES text (1 Cor. 1:10 across four spelling eras) and dumping
 * the exact result to tests/Fixtures/historical_alignment_ground_truth.php.
 *
 * This is the only worked example shipped with align.py -- there was no
 * separate 15+-verse regression suite to port. It is a rich one though: it
 * exercises anchor, window, compound, phrase, moved, and 1:n links, plus
 * the negation-particle detector. It does NOT exercise bridgeSynonyms,
 * bridgeMultiSynonyms or dropKnownPrefixes (no "synonym" or dropped-prefix
 * case occurs in this verse) -- those are covered by the smaller, targeted
 * unit tests below instead.
 */
class HistoricalAlignmentServiceTest extends TestCase
{
    private HistoricalAlignmentService $service;
    private array $fixture;

    protected function setUp(): void
    {
        $this->service = new HistoricalAlignmentService();
        $this->fixture = require __DIR__ . '/../../../Fixtures/historical_alignment_ground_truth.php';
    }

    public function testTokenizeMatchesAlignPy(): void
    {
        foreach ($this->fixture['raw'] as $name => $text) {
            $this->assertSame(
                $this->fixture["tokens_$name"],
                $this->service->tokenize($text),
                "tokenize() mismatch for witness '$name'"
            );
        }
    }

    /**
     * Spot-checks pulled from align.py's own "NORMALISATIE" demo output
     * (every case there where the form actually changes).
     */
    public function testNormalizeMatchesAlignPySpotChecks(): void
    {
        $cases = [
            'Maer' => 'maar',
            'ick' => 'ik',
            'bidde' => 'bid',
            'den' => 'de',
            'name' => 'naam',
            'onses' => 'onze',
            'Heeren' => 'heren',
            'Iesu' => 'jezus',
            'Christi' => 'christus',
            'ghy' => 'gij',
            'selve' => 'zelfde',
            'spreeckt' => 'spreekt',
            'ende' => 'en',
            'geene' => 'geen',
            'gevoeght' => 'gevoegd',
            'eenen' => 'een',
            'selven' => 'zelfden',
            'sin' => 'zin',
            'Heere' => 'here',
            'één' => 'een',
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $this->service->normalize($input), "normalize('$input')");
        }
    }

    public function testNormalizeIsIdempotentOnAlreadyNormalForms(): void
    {
        foreach (['maar', 'ik', 'bid', 'jezus', 'christus'] as $w) {
            $this->assertSame($w, $this->service->normalize($w));
        }
    }

    #[DataProvider('pairProvider')]
    public function testAlignPairMatchesAlignPyGroundTruth(string $otherKey): void
    {
        $srcRaw = $this->fixture['tokens_SV-1637'];
        $tgtRaw = $this->fixture["tokens_$otherKey"];
        $expected = $this->fixture['pairs'][$otherKey];

        $result = $this->service->alignPair($srcRaw, $tgtRaw);

        $actualLinks = array_map(static fn($l) => [
            'src' => $l->src,
            'tgt' => $l->tgt,
            'score' => round($l->score, 4),
            'kind' => $l->kind,
        ], $result->links);

        // align.py's internal 1:n merge kind is literally "1:n"; the DB method
        // vocabulary this service targets uses "one_to_many" instead (see
        // migration Version20260904120000) -- translate before comparing.
        $expectedLinks = array_map(static function (array $l) {
            $kind = $l['kind'] === '1:n' ? 'one_to_many' : $l['kind'];

            return ['src' => $l['src'], 'tgt' => $l['tgt'], 'score' => $l['score'], 'kind' => $kind];
        }, $expected['links']);

        $this->assertSame($expectedLinks, $actualLinks, "links mismatch for pair '$otherKey'");
        $this->assertSame($expected['unmatched_tgt'], $result->unmatchedTgt, "unmatched_tgt mismatch for '$otherKey'");
        $this->assertSame($expected['particles'], $result->particles, "particles mismatch for '$otherKey'");
        $this->assertSame($expected['dropped_prefixes'], $result->droppedPrefixes, "dropped_prefixes mismatch for '$otherKey'");
    }

    public static function pairProvider(): array
    {
        return [
            'SV-1637 -> SV-Jongbloed' => ['SV-Jongbloed'],
            'SV-1637 -> SV-modern' => ['SV-modern'],
            'SV-1637 -> HSV' => ['HSV'],
        ];
    }

    // ── Forced (manual) anchors: the one architectural addition over align.py ──

    public function testForcedAnchorIsNeverOverriddenAndExcludedFromAutomaticAnchors(): void
    {
        // 'broeders' occurs once on both sides and would normally become an
        // algorithmic anchor. Force it to a *different* (still plausible)
        // target index and check the pipeline respects that instead.
        $srcRaw = $this->fixture['tokens_SV-1637'];
        $tgtRaw = $this->fixture['tokens_SV-Jongbloed'];
        $brothersSrc = array_search('broeders', $srcRaw, true);
        $brothersTgt = array_search('broeders', $tgtRaw, true);
        $this->assertIsInt($brothersSrc);
        $this->assertIsInt($brothersTgt);

        $result = $this->service->alignPair($srcRaw, $tgtRaw, [[$brothersSrc, $brothersTgt]]);

        $manualLinks = array_values(array_filter($result->links, fn($l) => $l->kind === 'manual'));
        $this->assertCount(1, $manualLinks);
        $this->assertSame($brothersSrc, $manualLinks[0]->src);
        $this->assertSame([$brothersTgt], $manualLinks[0]->tgt);
        $this->assertSame(1.0, $manualLinks[0]->score);

        // No other link should also claim either of these two positions.
        foreach ($result->links as $l) {
            if ($l->kind === 'manual') {
                continue;
            }
            $this->assertNotSame($brothersSrc, $l->src);
            $this->assertNotContains($brothersTgt, $l->tgt);
        }
    }

    // ── Targeted unit tests for phases the ground-truth verse doesn't exercise ──

    public function testBridgeSynonymsMatchesFunctionWordOfDifferentForm(): void
    {
        // 'want' (SYNONYM_BRIDGE) <-> 'omdat', with nothing else in the verse
        // to match on, forces the synonym bridge to be the only path.
        $result = $this->service->alignPair(['want', 'xxzz'], ['omdat', 'yyww']);

        $synonymLinks = array_values(array_filter($result->links, fn($l) => $l->kind === 'synonym'));
        $this->assertCount(1, $synonymLinks);
        $this->assertSame(0, $synonymLinks[0]->src);
        $this->assertSame([0], $synonymLinks[0]->tgt);
    }

    public function testBridgeMultiSynonymsSplitsOneSourceWordIntoTwoTargets(): void
    {
        $result = $this->service->alignPair(['dootsloegh'], ['sloeg', 'dood']);

        $synonymLinks = array_values(array_filter($result->links, fn($l) => $l->kind === 'synonym'));
        $this->assertCount(1, $synonymLinks);
        $this->assertSame(0, $synonymLinks[0]->src);
        $this->assertSame([0, 1], $synonymLinks[0]->tgt);
    }

    public function testDropKnownPrefixDropsOpWhenFollowingWordAlreadyMatched(): void
    {
        // 'op' + 'dat' -> HSV sometimes drops 'op' in isolation once 'dat'
        // matched on its own. Use distinctive filler words so 'dat' anchors
        // cleanly and 'op' has nothing plausible to match to.
        $result = $this->service->alignPair(['xx1', 'op', 'dat', 'xx2'], ['xx1', 'dat', 'xx2']);

        $this->assertContains(1, $result->droppedPrefixes);
    }

    public function testFindNegationParticlesDetectsEnNearNiet(): void
    {
        $srcRaw = ['dit', 'en', 'is', 'niet', 'waar'];
        $src = array_map($this->service->normalize(...), $srcRaw);
        $particles = $this->service->findNegationParticles($src, $srcRaw);

        $this->assertSame([1], $particles);
    }

    public function testFindNegationParticlesDoesNotFlagRegularConjunctionEn(): void
    {
        $srcRaw = ['appelen', 'en', 'peren'];
        $src = array_map($this->service->normalize(...), $srcRaw);
        $particles = $this->service->findNegationParticles($src, $srcRaw);

        $this->assertSame([], $particles);
    }
}
