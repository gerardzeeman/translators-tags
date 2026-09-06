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

    /**
     * rawForm() is the pre-lexicon/pre-char-rule half of normalize() --
     * AlignmentLibraryService stores new lexicon entries under this exact
     * key (see its addLexicon()), so it must stay in lock-step with
     * normalize()'s own first step.
     */
    public function testRawFormIsThePreLexiconPreCharRuleStep(): void
    {
        $this->assertSame('oogen', $this->service->rawForm('Oógen!'));
        $this->assertSame('oog', $this->service->normalize('oogen')); // lexicon then kicks in
        $this->assertSame('den', $this->service->rawForm('den'));
        $this->assertSame('de', $this->service->normalize('den'));
    }

    /**
     * Without a library repository (every other test in this class, and
     * every construction site that predates the alignment-library feature),
     * behaviour is 100% unchanged: only the hardcoded DEFAULT_* tables apply.
     */
    public function testWithoutLibraryRepositoryOnlyDefaultsApply(): void
    {
        $service = new HistoricalAlignmentService();
        $this->assertSame('oog', $service->normalize('oogen'));
        $this->assertSame('nietbestaandwoord', $service->normalize('nietbestaandwoord'));
    }

    /**
     * With a library repository, DB-loaded rows extend (not replace) the
     * hardcoded defaults -- a new lexicon entry for a brand-new historical
     * spelling takes effect, while existing default entries keep working.
     */
    public function testLibraryRepositoryEntriesExtendTheDefaults(): void
    {
        $repo = $this->createMock(\App\Repository\AlignmentLibraryRepository::class);
        $repo->method('loadLexicon')->willReturn(['nieuwespelling' => 'modernwoord']);
        $repo->method('loadSynonymBridge')->willReturn(['nieuwesyn' => ['anderwoord']]);
        $repo->method('loadMultiSynonymBridge')->willReturn([]);
        $repo->method('loadPhraseBridge')->willReturn([]);

        $service = new HistoricalAlignmentService(libraryRepo: $repo);

        $this->assertSame('modernwoord', $service->normalize('nieuwespelling'));
        $this->assertSame('oog', $service->normalize('oogen')); // default still works
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

    /**
     * Regression: bridgePhrases() only ever linked the FIRST occurrence of a
     * phrase pattern in the verse, silently leaving later occurrences of the
     * exact same phrase unlinked (found via a real case: "het gene" ->
     * "hetgeen" appears 3 times in 1 John 1:1, and only the first was ever
     * matched). It now keeps finding occurrences until none are left.
     */
    public function testBridgePhrasesLinksEveryOccurrenceNotJustTheFirst(): void
    {
        // DEFAULT_PHRASE_BRIDGE already has ['de','beginne'] -> ['het','begin'];
        // repeat it twice in the same sentence, separated by an anchor word.
        $result = $this->service->alignPair(
            ['de', 'beginne', 'xx1', 'de', 'beginne'],
            ['het', 'begin', 'xx1', 'het', 'begin'],
        );

        $phraseLinks = array_values(array_filter($result->links, fn($l) => $l->kind === 'phrase'));
        $bySrc = [];
        foreach ($phraseLinks as $l) {
            $bySrc[$l->src] = $l->tgt;
        }
        $this->assertSame([0, 1], $bySrc[0] ?? null, 'first occurrence: "de" links to the first "het"+"begin"');
        $this->assertSame([0, 1], $bySrc[1] ?? null, 'first occurrence: "beginne" links to the first "het"+"begin"');
        $this->assertSame([3, 4], $bySrc[3] ?? null, 'second occurrence must also be linked, not silently dropped');
        $this->assertSame([3, 4], $bySrc[4] ?? null, 'second occurrence must also be linked, not silently dropped');
    }

    /**
     * Regression: bridgeMultiSynonyms() picked the FIRST unclaimed
     * occurrence of a needed target word anywhere in the sentence, not the
     * one actually next to the source word -- found via a real case where
     * "der" (needing "van"+"de") grabbed a "de" many words away instead of
     * the one sitting right next to its correct partner, because a
     * different, closer "de" happened to come first in the array.
     */
    public function testBridgeMultiSynonymsPicksClosestOccurrenceNotFirst(): void
    {
        // 'openbaar' -> ['te', 'herkennen'] (DEFAULT_MULTI_SYNONYM_BRIDGE).
        // A "te" far from 'openbaar' comes first in the array; the "te"
        // right next to "herkennen" (its real partner) comes later but is
        // the one that must be picked.
        $src = ['xx', 'xx', 'xx', 'openbaar'];
        $tgt = ['te', 'xx', 'xx', 'xx', 'te', 'herkennen'];
        $links = [];

        $this->service->bridgeMultiSynonyms($links, $src, $tgt);

        $this->assertCount(1, $links);
        $this->assertSame(3, $links[0]->src);
        $this->assertSame([4, 5], $links[0]->tgt);
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

    /**
     * Regression for a real report: recomputing 1 John 2:10 failed to link
     * the "ende"/"en"/"en"/"en" chain (all four editions' conjunction "and")
     * at all. Root cause: 'geen'/'niet' immediately following 'en' with zero
     * words in between ("en geen ergernis is" = ordinary "and no offense")
     * was wrongly treated the same as genuine archaic "geen ... en is"
     * doubled negation, wiping out the ordinary conjunction on every
     * edition simultaneously so nothing was left to anchor.
     */
    public function testFindNegationParticlesDoesNotFlagOrdinaryEnImmediatelyBeforeGeen(): void
    {
        // SV1657: "...licht, ende geen ergernisse en is in hem" -- 'ende'
        // (index 4, ordinary "and") must NOT be flagged; 'en' (index 7,
        // genuine "geen ... en is" doubling) still must be.
        $srcRaw = ['blijft', 'in', 'het', 'licht', 'ende', 'geen', 'ergernisse', 'en', 'is', 'in', 'hem'];
        $src = array_map($this->service->normalize(...), $srcRaw);
        $particles = $this->service->findNegationParticles($src, $srcRaw);

        $this->assertSame([7], $particles, 'only the genuine "geen ... en is" doubling (index 7) should be flagged');
    }

    public function testFindNegationParticlesDoesNotFlagOrdinaryEnImmediatelyBeforeNiet(): void
    {
        $srcRaw = ['hij', 'zong', 'en', 'niet', 'danste'];
        $src = array_map($this->service->normalize(...), $srcRaw);
        $particles = $this->service->findNegationParticles($src, $srcRaw);

        $this->assertSame([], $particles);
    }
}
