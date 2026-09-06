<?php

namespace App\Service\Alignment;

/**
 * Turns the flat (words-per-translation, links) data for one verse into a
 * row-based layout for the review UI: words that are linked to each other
 * -- directly or transitively, across any number of translations and any
 * number of hops -- land in the same row, so corresponding words line up
 * underneath each other instead of each column flowing independently.
 *
 * Topology-agnostic by construction: it doesn't know or care whether the
 * links form a star (one pivot linked to three targets) or a chain
 * (SV1657<->SV<->SVGBS<->HSV, each translation linked only to its
 * neighbour) -- it just finds connected components over the whole link
 * graph. A star pivot's compound link (3 source words -> 1 target word)
 * and a chain's 3-hop correspondence (SV1657 -> SV -> SVGBS -> HSV) both
 * fall out of the same mechanism: they're each one connected component,
 * so they each become one row.
 *
 * $backboneCode picks which translation's reading order anchors row
 * position (row = that translation's word index). Every other component
 * -- one not connected to any backbone word, which can only happen with
 * gaps in a chain topology (e.g. SVGBS<->HSV linked but that segment never
 * reaches back to the SV1657/SV backbone) -- attaches to the nearest
 * already-placed neighbour in any of its member words' own column reading
 * order, cascading pass by pass so a run of several such gaps in a row
 * still resolves correctly.
 */
class HistoricalAlignmentRowBuilder
{
    /**
     * @param array<string, list<array{id:int, word_position:int, word_text:string, alignment_note?: ?string}>> $wordsByCode
     * @param list<array{word_a_id:int, word_b_id:int, method:string, score: float|string|null}> $links
     * @return list<array<string, list<array>>> one entry per row; each row maps every
     *   translation code to a list of words placed in that row, in that column's own
     *   reading order
     */
    public function buildRows(array $wordsByCode, array $links, string $backboneCode): array
    {
        $codes = array_keys($wordsByCode);

        // id -> ['code' => ..., 'idx' => ...]; code => [idx => id]
        $wordMeta = [];
        $idByCodeIdx = [];
        foreach ($wordsByCode as $code => $words) {
            foreach ($words as $idx => $w) {
                $id = (int) $w['id'];
                $wordMeta[$id] = ['code' => $code, 'idx' => $idx];
                $idByCodeIdx[$code][$idx] = $id;
            }
        }
        if (!$wordMeta) {
            return [];
        }

        $rootOf = $this->groupByConnectedComponent($wordMeta, $links);

        $components = [];
        foreach ($rootOf as $id => $root) {
            $components[$root][] = $id;
        }

        [$rowByRoot, $unresolvedRoots] = $this->assignBackboneRows($components, $wordMeta, $backboneCode);
        $this->resolveGapRows($unresolvedRoots, $components, $wordMeta, $idByCodeIdx, $rootOf, $rowByRoot);

        return $this->assembleRows($components, $wordMeta, $wordsByCode, $codes, $rowByRoot);
    }

    /**
     * Union-Find over every word (nodes) with inter_translation_links as
     * edges. Returns id => component-root id.
     *
     * @param array<int, array{code:string, idx:int}> $wordMeta
     * @param list<array{word_a_id:int, word_b_id:int}> $links
     * @return array<int, int>
     */
    private function groupByConnectedComponent(array $wordMeta, array $links): array
    {
        $parent = [];
        foreach (array_keys($wordMeta) as $id) {
            $parent[$id] = $id;
        }
        $find = function (int $x) use (&$parent, &$find): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        foreach ($links as $l) {
            $a = (int) $l['word_a_id'];
            $b = (int) $l['word_b_id'];
            if (!isset($wordMeta[$a], $wordMeta[$b])) {
                continue;
            }
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        }

        $rootOf = [];
        foreach (array_keys($wordMeta) as $id) {
            $rootOf[$id] = $find($id);
        }

        return $rootOf;
    }

    /**
     * Row index = the lowest backbone-word index present in the component,
     * for every component that contains one.
     *
     * @param array<int, list<int>> $components
     * @param array<int, array{code:string, idx:int}> $wordMeta
     * @return array{0: array<int, int>, 1: list<int>} [rowByRoot, unresolvedRoots]
     */
    private function assignBackboneRows(array $components, array $wordMeta, string $backboneCode): array
    {
        $rowByRoot = [];
        $unresolvedRoots = [];
        foreach ($components as $root => $ids) {
            $minIdx = null;
            foreach ($ids as $id) {
                if ($wordMeta[$id]['code'] === $backboneCode) {
                    $minIdx = $minIdx === null ? $wordMeta[$id]['idx'] : min($minIdx, $wordMeta[$id]['idx']);
                }
            }
            if ($minIdx !== null) {
                $rowByRoot[$root] = $minIdx;
            } else {
                $unresolvedRoots[] = $root;
            }
        }

        return [$rowByRoot, $unresolvedRoots];
    }

    /**
     * Components with no backbone word (only possible with chain-topology
     * gaps) attach to the nearest already-placed neighbour in any member
     * word's own column reading order. Runs in passes -- at most
     * count($unresolvedRoots) + 1 -- so a run of N consecutive gaps
     * cascades off a single resolved anchor correctly regardless of order.
     * Anything still unresolved after that (fully isolated, no path to the
     * backbone anywhere) falls back to row 0.
     *
     * @param list<int> $unresolvedRoots
     * @param array<int, list<int>> $components
     * @param array<int, array{code:string, idx:int}> $wordMeta
     * @param array<string, array<int, int>> $idByCodeIdx
     * @param array<int, int> $rootOf
     * @param array<int, int> $rowByRoot
     */
    private function resolveGapRows(
        array $unresolvedRoots,
        array $components,
        array $wordMeta,
        array $idByCodeIdx,
        array $rootOf,
        array &$rowByRoot,
    ): void {
        $maxPasses = count($unresolvedRoots) + 1;
        for ($pass = 0; $pass < $maxPasses && $unresolvedRoots; $pass++) {
            $stillUnresolved = [];
            foreach ($unresolvedRoots as $root) {
                $row = $this->nearestResolvedRow($components[$root], $wordMeta, $idByCodeIdx, $rootOf, $rowByRoot);
                if ($row !== null) {
                    $rowByRoot[$root] = $row;
                } else {
                    $stillUnresolved[] = $root;
                }
            }
            $unresolvedRoots = $stillUnresolved;
        }
        foreach ($unresolvedRoots as $root) {
            $rowByRoot[$root] = 0;
        }
    }

    /**
     * @param list<int> $componentIds
     * @param array<int, array{code:string, idx:int}> $wordMeta
     * @param array<string, array<int, int>> $idByCodeIdx
     * @param array<int, int> $rootOf
     * @param array<int, int> $rowByRoot
     */
    private function nearestResolvedRow(array $componentIds, array $wordMeta, array $idByCodeIdx, array $rootOf, array $rowByRoot): ?int
    {
        foreach ($componentIds as $id) {
            $code = $wordMeta[$id]['code'];
            $idx = $wordMeta[$id]['idx'];
            $columnIds = $idByCodeIdx[$code];
            $n = count($columnIds);

            for ($j = $idx - 1; $j >= 0; $j--) {
                $neighbourId = $columnIds[$j] ?? null;
                if ($neighbourId !== null && isset($rowByRoot[$rootOf[$neighbourId]])) {
                    return $rowByRoot[$rootOf[$neighbourId]];
                }
            }
            for ($j = $idx + 1; $j < $n; $j++) {
                $neighbourId = $columnIds[$j] ?? null;
                if ($neighbourId !== null && isset($rowByRoot[$rootOf[$neighbourId]])) {
                    return $rowByRoot[$rootOf[$neighbourId]];
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, list<int>> $components
     * @param array<int, array{code:string, idx:int}> $wordMeta
     * @param array<string, list<array>> $wordsByCode
     * @param list<string> $codes
     * @param array<int, int> $rowByRoot
     * @return list<array<string, list<array>>>
     */
    private function assembleRows(array $components, array $wordMeta, array $wordsByCode, array $codes, array $rowByRoot): array
    {
        $rows = [];
        foreach ($components as $root => $ids) {
            $rowIdx = $rowByRoot[$root];
            if (!isset($rows[$rowIdx])) {
                $rows[$rowIdx] = array_fill_keys($codes, []);
            }
            foreach ($ids as $id) {
                $code = $wordMeta[$id]['code'];
                $rows[$rowIdx][$code][] = $wordsByCode[$code][$wordMeta[$id]['idx']];
            }
        }
        ksort($rows, SORT_NUMERIC);

        foreach ($rows as &$row) {
            foreach ($row as &$wordsInCell) {
                usort($wordsInCell, static fn($a, $b) => $a['word_position'] <=> $b['word_position']);
            }
        }
        unset($row, $wordsInCell);

        return array_values($rows);
    }
}
