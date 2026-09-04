<?php

namespace App\Service\Alignment;

/**
 * Implements the plan sectie 4 scoring formula for the 4-way historical
 * alignment review UI.
 *
 *   woord_score = 1.0  if manual
 *               = 0.0  if unlinked (including a word whose only relation is
 *                       a manual_empty row -- a negative assertion that two
 *                       specific words are confirmed unrelated, not a
 *                       positive link; see bestScoreAgainst())
 *               = link.score if automatic (0-1)
 *
 *   "systematisch weggelaten" (translation_words.alignment_note:
 *   particle_drop / prefix_drop) counts NOWHERE -- not in the numerator,
 *   not in the denominator.
 *
 *   Every word position in every translation contributes separately: an
 *   SV1657 word appears as the source word in three pairs (SV1657-SV,
 *   SV1657-SVGBS, SV1657-HSV) and so yields three separate scores, one per
 *   pair -- not one averaged score. A target-translation word (SV, SVGBS,
 *   HSV) appears in exactly one pair (its own with SV1657) and so yields
 *   exactly one score.
 *
 *   vers_score (%) = sum(all word_scores, across all four texts and all
 *                    three pairs, excluding systematically-excluded) /
 *                    (counted word positions x 1.0) x 100
 */
class HistoricalAlignmentScoreService
{
    /**
     * @param array<string, list<array{id:int, word_position:int, word_text:string, alignment_note?: ?string}>> $wordsByCode
     *   keyed by translation code (must include $pivotCode and at least one other)
     * @param list<array{word_a_id:int, word_b_id:int, method:string, score: float|string|null}> $links
     *   every inter_translation_links row touching any word in $wordsByCode
     * @return array{
     *   percent: float,
     *   counted_positions: int,
     *   total_score: float,
     *   pivot_word_scores: array<int, array<string, float>>,
     *   target_word_scores: array<int, float>,
     *   unlinked_word_ids: array<int, true>,
     * }
     */
    public function computeVerseScore(array $wordsByCode, array $links, string $pivotCode): array
    {
        $pivotWords = $wordsByCode[$pivotCode] ?? [];
        $targetCodes = array_values(array_filter(array_keys($wordsByCode), fn($c) => $c !== $pivotCode));

        $linksByWordId = $this->indexLinksByWordId($links);

        $pivotWordScores = [];
        $targetWordScores = [];
        $unlinkedWordIds = [];
        $totalScore = 0.0;
        $countedPositions = 0;

        $pivotWordIds = array_map(static fn($w) => (int) $w['id'], $pivotWords);
        $pivotWordIdSet = array_flip($pivotWordIds);

        $targetWordIdSetByCode = [];
        foreach ($targetCodes as $targetCode) {
            $targetWordIdSetByCode[$targetCode] = array_flip(
                array_map(static fn($w) => (int) $w['id'], $wordsByCode[$targetCode] ?? [])
            );
        }

        // Pivot words: a word is only "unlinked" if it has no link in ANY of
        // its three pairs -- a real gap against just one target (e.g. SVGBS)
        // while linked fine against the other two is normal, not a gap.
        foreach ($pivotWords as $pw) {
            if (!empty($pw['alignment_note'])) {
                continue;
            }
            $pid = (int) $pw['id'];
            $pivotHasAnyLink = false;
            foreach ($targetCodes as $targetCode) {
                [$score, $linked] = $this->bestScoreAgainst($linksByWordId[$pid] ?? [], $targetWordIdSetByCode[$targetCode]);
                $pivotWordScores[$pid][$targetCode] = $score;
                $totalScore += $score;
                $countedPositions++;
                $pivotHasAnyLink = $pivotHasAnyLink || $linked;
            }
            if (!$pivotHasAnyLink) {
                $unlinkedWordIds[$pid] = true;
            }
        }

        foreach ($targetCodes as $targetCode) {
            $targetWords = $wordsByCode[$targetCode] ?? [];

            foreach ($targetWords as $tw) {
                $tid = (int) $tw['id'];
                [$score, $linked] = $this->bestScoreAgainst($linksByWordId[$tid] ?? [], $pivotWordIdSet);
                $targetWordScores[$tid] = $score;
                $totalScore += $score;
                $countedPositions++;
                if (!$linked) {
                    $unlinkedWordIds[$tid] = true;
                }
            }
        }

        $percent = $countedPositions > 0 ? ($totalScore / $countedPositions) * 100 : 100.0;

        return [
            'percent' => $percent,
            'counted_positions' => $countedPositions,
            'total_score' => $totalScore,
            'pivot_word_scores' => $pivotWordScores,
            'target_word_scores' => $targetWordScores,
            'unlinked_word_ids' => $unlinkedWordIds,
        ];
    }

    /**
     * @param list<array{word_a_id:int, word_b_id:int, method:string, score: float|string|null}> $links
     * @return array<int, list<array{other: int, method: string, score: ?float}>>
     */
    private function indexLinksByWordId(array $links): array
    {
        $byWordId = [];
        foreach ($links as $l) {
            $a = (int) $l['word_a_id'];
            $b = (int) $l['word_b_id'];
            $method = $l['method'];
            $score = $l['score'] !== null ? (float) $l['score'] : null;
            $byWordId[$a][] = ['other' => $b, 'method' => $method, 'score' => $score];
            $byWordId[$b][] = ['other' => $a, 'method' => $method, 'score' => $score];
        }

        return $byWordId;
    }

    /**
     * @param list<array{other: int, method: string, score: ?float}> $entries
     * @param array<int, int> $counterpartIdSet
     * @return array{0: float, 1: bool}
     */
    private function bestScoreAgainst(array $entries, array $counterpartIdSet): array
    {
        $best = 0.0;
        $linked = false;
        foreach ($entries as $entry) {
            // manual_empty is a negative assertion -- "these two specific
            // words are confirmed NOT related" (e.g. to stop the auto-linker
            // re-suggesting a superficially-similar pair) -- never a positive
            // match. A word with only a manual_empty entry correctly stays
            // "unlinked" (0.0, still counted): the gap is real and confirmed,
            // not systematically excluded like particle_drop/prefix_drop.
            if ($entry['method'] === 'manual_empty') {
                continue;
            }
            if (!isset($counterpartIdSet[$entry['other']])) {
                continue;
            }
            $linked = true;
            $score = $entry['method'] === 'manual' ? 1.0 : ($entry['score'] ?? 0.0);
            $best = max($best, $score);
        }

        return [$linked ? $best : 0.0, $linked];
    }
}
