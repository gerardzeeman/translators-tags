<?php

namespace App\Service\Alignment;

/**
 * Implements the plan sectie 4 scoring formula for the 4-way historical
 * alignment review UI. Topology-agnostic: it takes the actual list of pairs
 * that were aligned (star: pivot-X for every other translation X; chain:
 * adjacent editions) and scores each pair independently, so a word's total
 * weight is simply "how many pairs does it appear in" -- 3 for a star pivot,
 * 1 for a star target, 2 for a middle-of-chain translation, 1 for a
 * chain end. No topology-specific logic lives here at all.
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
 *   not in the denominator -- regardless of which pair(s) that word
 *   belongs to.
 *
 *   Every word position contributes once PER PAIR it appears in: a word
 *   shared by two pairs (a star pivot relative to two targets, or a
 *   middle-of-chain translation relative to its two neighbours) yields one
 *   score per pair, not one averaged score.
 *
 *   vers_score (%) = sum(all word_scores, across every pair) /
 *                    (counted word-position-per-pair instances x 1.0) x 100
 */
class HistoricalAlignmentScoreService
{
    /**
     * @param array<string, list<array{id:int, word_position:int, word_text:string, alignment_note?: ?string}>> $wordsByCode
     *   keyed by translation code
     * @param list<array{word_a_id:int, word_b_id:int, method:string, score: float|string|null}> $links
     *   every inter_translation_links row touching any word in $wordsByCode
     * @param list<array{0: string, 1: string}> $pairs the translation-code pairs that were
     *   actually aligned (e.g. [['SV','SV1657'],['SV','SVGBS'],['SV','HSV']] for a star
     *   pivoted on SV, or [['SV1657','SV'],['SV','SVGBS'],['SVGBS','HSV']] for a chain)
     * @return array{
     *   percent: float,
     *   counted_positions: int,
     *   total_score: float,
     *   word_scores: array<int, array<string, float>>,
     *   unlinked_word_ids: array<int, true>,
     * }
     */
    public function computeVerseScore(array $wordsByCode, array $links, array $pairs): array
    {
        $linksByWordId = $this->indexLinksByWordId($links);

        $wordScores = [];
        $everLinked = [];
        $everAppeared = [];
        $totalScore = 0.0;
        $countedPositions = 0;

        $idSetByCode = [];
        foreach ($wordsByCode as $code => $words) {
            $idSetByCode[$code] = array_flip(array_map(static fn($w) => (int) $w['id'], $words));
        }

        foreach ($pairs as [$codeA, $codeB]) {
            $this->scoreOneSide(
                $wordsByCode[$codeA] ?? [], $codeB, $idSetByCode[$codeB] ?? [], $linksByWordId,
                $wordScores, $everLinked, $everAppeared, $totalScore, $countedPositions,
            );
            $this->scoreOneSide(
                $wordsByCode[$codeB] ?? [], $codeA, $idSetByCode[$codeA] ?? [], $linksByWordId,
                $wordScores, $everLinked, $everAppeared, $totalScore, $countedPositions,
            );
        }

        $unlinkedWordIds = [];
        foreach ($everAppeared as $id => $true) {
            if (!isset($everLinked[$id])) {
                $unlinkedWordIds[$id] = true;
            }
        }

        $percent = $countedPositions > 0 ? ($totalScore / $countedPositions) * 100 : 100.0;

        return [
            'percent' => $percent,
            'counted_positions' => $countedPositions,
            'total_score' => $totalScore,
            'word_scores' => $wordScores,
            'unlinked_word_ids' => $unlinkedWordIds,
        ];
    }

    /**
     * Scores every (non-excluded) word in $words against the counterpart
     * set for ONE pair-relationship, accumulating into the by-reference
     * totals. Called twice per pair (once per direction) by
     * computeVerseScore().
     *
     * @param list<array{id:int, alignment_note?: ?string}> $words
     * @param array<int, int> $counterpartIdSet
     * @param array<int, list<array{other:int, method:string, score:?float}>> $linksByWordId
     * @param array<int, array<string, float>> $wordScores
     * @param array<int, true> $everLinked
     * @param array<int, true> $everAppeared
     */
    private function scoreOneSide(
        array $words,
        string $counterpartCode,
        array $counterpartIdSet,
        array $linksByWordId,
        array &$wordScores,
        array &$everLinked,
        array &$everAppeared,
        float &$totalScore,
        int &$countedPositions,
    ): void {
        foreach ($words as $w) {
            if (!empty($w['alignment_note'])) {
                continue;
            }
            $id = (int) $w['id'];
            [$score, $linked] = $this->bestScoreAgainst($linksByWordId[$id] ?? [], $counterpartIdSet);
            $wordScores[$id][$counterpartCode] = $score;
            $everAppeared[$id] = true;
            if ($linked) {
                $everLinked[$id] = true;
            }
            $totalScore += $score;
            $countedPositions++;
        }
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
