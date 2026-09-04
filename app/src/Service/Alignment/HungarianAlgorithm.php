<?php

namespace App\Service\Alignment;

/**
 * Rectangular linear-sum-assignment solver (Kuhn-Munkres / Hungarian
 * algorithm, O(n^2 * m) with n <= m), standing in for scipy's
 * `linear_sum_assignment` used by align.py. PHP has no built-in equivalent.
 *
 * Given an n x m cost matrix, finds the assignment of all n rows to n
 * distinct columns (n <= m) that minimises total cost -- every row MUST be
 * assigned (unlike a padded 0-cost dummy approach, which would make
 * assignment optional and change the result). If n > m, the problem is
 * solved transposed and the result mapped back, so every call returns
 * exactly min(n, m) pairs, matching scipy's return shape.
 *
 * Verse-level matrices here are a few dozen rows/columns at most, so the
 * O(n^3)-ish complexity is not a performance concern.
 */
class HungarianAlgorithm
{
    /**
     * @param float[][] $cost cost[row][col], row-major, all rows the same length
     * @return array<int, array{0:int,1:int}> list of [row, col] pairs, length min(rows, cols)
     */
    public function solve(array $cost): array
    {
        $numRows = count($cost);
        if ($numRows === 0) {
            return [];
        }
        $numCols = count($cost[0]);
        if ($numCols === 0) {
            return [];
        }

        if ($numRows <= $numCols) {
            return $this->solveRowsLeqCols($cost, $numRows, $numCols);
        }

        // Transpose so the smaller dimension is rows, solve, then map back.
        $transposed = [];
        for ($c = 0; $c < $numCols; $c++) {
            for ($r = 0; $r < $numRows; $r++) {
                $transposed[$c][$r] = $cost[$r][$c];
            }
        }
        $pairs = $this->solveRowsLeqCols($transposed, $numCols, $numRows);

        return array_map(static fn(array $p) => [$p[1], $p[0]], $pairs);
    }

    /**
     * Classic O(n^2 * m) potential-based Hungarian algorithm, requires n <= m.
     * 1-indexed internally (standard formulation), 0-indexed at the boundary.
     *
     * @return array<int, array{0:int,1:int}>
     */
    private function solveRowsLeqCols(array $cost, int $n, int $m): array
    {
        $INF = PHP_FLOAT_MAX;

        $u = array_fill(0, $n + 1, 0.0);
        $v = array_fill(0, $m + 1, 0.0);
        $p = array_fill(0, $m + 1, 0);   // p[j] = 1-based row currently matched to column j (0 = none)
        $way = array_fill(0, $m + 1, 0);

        for ($i = 1; $i <= $n; $i++) {
            $p[0] = $i;
            $j0 = 0;
            $minv = array_fill(0, $m + 1, $INF);
            $used = array_fill(0, $m + 1, false);

            do {
                $used[$j0] = true;
                $i0 = $p[$j0];
                $delta = $INF;
                $j1 = -1;

                for ($j = 1; $j <= $m; $j++) {
                    if ($used[$j]) {
                        continue;
                    }
                    $cur = $cost[$i0 - 1][$j - 1] - $u[$i0] - $v[$j];
                    if ($cur < $minv[$j]) {
                        $minv[$j] = $cur;
                        $way[$j] = $j0;
                    }
                    if ($minv[$j] < $delta) {
                        $delta = $minv[$j];
                        $j1 = $j;
                    }
                }

                for ($j = 0; $j <= $m; $j++) {
                    if ($used[$j]) {
                        $u[$p[$j]] += $delta;
                        $v[$j] -= $delta;
                    } else {
                        $minv[$j] -= $delta;
                    }
                }

                $j0 = $j1;
            } while ($p[$j0] !== 0);

            do {
                $j1 = $way[$j0];
                $p[$j0] = $p[$j1];
                $j0 = $j1;
            } while ($j0 !== 0);
        }

        $result = [];
        for ($j = 1; $j <= $m; $j++) {
            if ($p[$j] !== 0) {
                $result[] = [$p[$j] - 1, $j - 1];
            }
        }
        usort($result, static fn(array $a, array $b) => $a[0] <=> $b[0]);

        return $result;
    }
}
