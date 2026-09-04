<?php

namespace App\Service\Alignment;

/**
 * Result of aligning one source-text token array against one target-text
 * token array. Mirrors the (links, unmatched_tgt, particles, dropped_prefixes)
 * tuple returned by align.py's `align_pair`.
 */
class AlignmentResult
{
    /**
     * @param AlignmentLink[] $links
     * @param int[] $unmatchedTgt   target-token indices with no link
     * @param int[] $particles      source-token indices that are systematically
     *                              dropped double-negation particles (en...niet)
     * @param int[] $droppedPrefixes source-token indices that are systematically
     *                              dropped known prefixes (PREFIX_DROP_WORDS)
     */
    public function __construct(
        public readonly array $links,
        public readonly array $unmatchedTgt,
        public readonly array $particles,
        public readonly array $droppedPrefixes,
    ) {
    }
}
