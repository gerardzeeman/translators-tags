<?php

namespace App\Service;

/**
 * WordDiffer
 * Word-level diff between two versions of the same Dutch sentence/segment
 * text, for the translation-proposal review panel (see
 * InstitutioProposalRepository::createTranslationProposal) -- shows exactly
 * which words a reviewer is being asked to approve, not just the two full
 * texts side by side.
 */
class WordDiffer
{
    /**
     * Word-level LCS diff, tokenized on whitespace (\S+, same convention as
     * InstitutioRepository::splitDutchWords's TOKEN_RE). Adjacent entries
     * sharing the same op are coalesced into one (space-joined), so a
     * multi-word insertion/deletion renders as one highlighted run rather
     * than one span per word.
     *
     * @return array<int, array{op: 'equal'|'insert'|'delete', text: string}>
     */
    public function diff(string $oldText, string $newText): array
    {
        $oldWords = preg_split('/\s+/u', trim($oldText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $newWords = preg_split('/\s+/u', trim($newText), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $this->coalesce($this->lcsOps($oldWords, $newWords));
    }

    /**
     * Classic O(n*m) LCS dynamic-programming table between $oldWords and
     * $newWords, backtracked from [0][0] into an ops sequence in original
     * (not reversed) order.
     *
     * @param array<int, string> $oldWords
     * @param array<int, string> $newWords
     * @return array<int, array{op: string, text: string}>
     */
    private function lcsOps(array $oldWords, array $newWords): array
    {
        $n = count($oldWords);
        $m = count($newWords);

        // dp[i][j] = length of the LCS of oldWords[i:] and newWords[j:]
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $oldWords[$i] === $newWords[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($oldWords[$i] === $newWords[$j]) {
                $ops[] = ['op' => 'equal', 'text' => $oldWords[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = ['op' => 'delete', 'text' => $oldWords[$i]];
                $i++;
            } else {
                $ops[] = ['op' => 'insert', 'text' => $newWords[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $ops[] = ['op' => 'delete', 'text' => $oldWords[$i]];
            $i++;
        }
        while ($j < $m) {
            $ops[] = ['op' => 'insert', 'text' => $newWords[$j]];
            $j++;
        }

        return $ops;
    }

    /**
     * Merges consecutive entries sharing the same op into one, space-joining
     * their text.
     *
     * @param array<int, array{op: string, text: string}> $ops
     * @return array<int, array{op: string, text: string}>
     */
    private function coalesce(array $ops): array
    {
        $result = [];
        foreach ($ops as $op) {
            $last = count($result) - 1;
            if ($last >= 0 && $result[$last]['op'] === $op['op']) {
                $result[$last]['text'] .= ' ' . $op['text'];
            } else {
                $result[] = $op;
            }
        }
        return $result;
    }
}
