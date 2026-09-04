<?php

namespace App\Service\Alignment;

/**
 * Turns the flat (words-per-translation, links) data for one verse into a
 * row-based layout for the review UI: one row per pivot (SV1657) word, with
 * each target translation's linked word(s) placed in that same row -- so
 * corresponding words line up underneath each other instead of each
 * column flowing independently (which made the alignment hard to read at a
 * glance and pushed all of the work onto the SVG connector lines).
 *
 * Placement rule per target word:
 *   - if linked to one or more pivot words, its row = the LOWEST pivot row
 *     among those links (a compound/phrase target attaches to the row of
 *     the first source word of the group);
 *   - if not linked at all (a genuine addition/gap in that translation),
 *     it attaches to the nearest PRECEDING linked neighbour's row in its
 *     OWN column's reading order (falling back to the nearest FOLLOWING
 *     one if nothing precedes it, or row 0 if the whole column is
 *     unlinked). This keeps every word visible without needing fractional
 *     "inserted row" bookkeeping -- a cell can hold more than one word,
 *     rendered in that column's own reading order.
 *
 * This is a display transform only: it never changes which words are
 * linked, only where they're drawn. The SVG connector lines (still drawn
 * separately) remain the precise, authoritative picture -- rows are there
 * to make the common case (things line up) readable without tracing a
 * line, while a "moved" link crossing rows stays visually obvious.
 */
class HistoricalAlignmentRowBuilder
{
    /**
     * @param array<string, list<array{id:int, word_position:int, word_text:string, is_filler?:bool, alignment_note?: ?string}>> $wordsByCode
     * @param list<array{word_a_id:int, word_b_id:int, method:string, score: float|string|null}> $links
     * @return list<array<string, list<array>>> one entry per row; each row maps every
     *   translation code (including $pivotCode) to a list of words placed in that row
     */
    public function buildRows(array $wordsByCode, array $links, string $pivotCode): array
    {
        $pivotWords = $wordsByCode[$pivotCode] ?? [];
        $targetCodes = array_values(array_filter(array_keys($wordsByCode), static fn($c) => $c !== $pivotCode));

        $pivotRowByWordId = [];
        foreach ($pivotWords as $idx => $w) {
            $pivotRowByWordId[(int) $w['id']] = $idx;
        }

        $adjacency = [];
        foreach ($links as $l) {
            $a = (int) $l['word_a_id'];
            $b = (int) $l['word_b_id'];
            $adjacency[$a][] = $b;
            $adjacency[$b][] = $a;
        }

        $rows = [];
        foreach ($pivotWords as $idx => $w) {
            $rows[$idx] = [$pivotCode => [$w]];
            foreach ($targetCodes as $code) {
                $rows[$idx][$code] = [];
            }
        }

        foreach ($targetCodes as $code) {
            $targetWords = $wordsByCode[$code] ?? [];
            $rowByTargetWordId = [];

            // Pass 1: directly linked words -> lowest linked pivot row.
            foreach ($targetWords as $tw) {
                $tid = (int) $tw['id'];
                $minRow = null;
                foreach ($adjacency[$tid] ?? [] as $otherId) {
                    if (isset($pivotRowByWordId[$otherId])) {
                        $r = $pivotRowByWordId[$otherId];
                        if ($minRow === null || $r < $minRow) {
                            $minRow = $r;
                        }
                    }
                }
                if ($minRow !== null) {
                    $rowByTargetWordId[$tid] = $minRow;
                }
            }

            // Pass 2: unlinked words -> nearest linked neighbour's row, in
            // this column's own reading order (backward, then forward).
            $n = count($targetWords);
            for ($i = 0; $i < $n; $i++) {
                $tid = (int) $targetWords[$i]['id'];
                if (isset($rowByTargetWordId[$tid])) {
                    continue;
                }
                $row = null;
                for ($j = $i - 1; $j >= 0; $j--) {
                    $pid = (int) $targetWords[$j]['id'];
                    if (isset($rowByTargetWordId[$pid])) {
                        $row = $rowByTargetWordId[$pid];
                        break;
                    }
                }
                if ($row === null) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        $pid = (int) $targetWords[$j]['id'];
                        if (isset($rowByTargetWordId[$pid])) {
                            $row = $rowByTargetWordId[$pid];
                            break;
                        }
                    }
                }
                $rowByTargetWordId[$tid] = $row ?? 0;
            }

            foreach ($targetWords as $tw) {
                $tid = (int) $tw['id'];
                $rowIdx = $rowByTargetWordId[$tid] ?? 0;
                if (!isset($rows[$rowIdx])) {
                    // Only possible if the pivot column is empty (no rows
                    // defined at all); guard so a malformed verse doesn't fatal.
                    $rows[$rowIdx] = [$pivotCode => []];
                    foreach ($targetCodes as $c) {
                        $rows[$rowIdx][$c] = [];
                    }
                }
                $rows[$rowIdx][$code][] = $tw;
            }
        }

        ksort($rows, SORT_NUMERIC);

        return array_values($rows);
    }
}
