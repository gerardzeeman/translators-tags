<?php

namespace App\Service\Alignment;

/**
 * One alignment edge: source-token index `src` linked to zero or more
 * target-token indices `tgt` (multiple for 1:n / phrase / compound merges).
 * Mutable by design -- later pipeline phases (moved-block rescue, clitic
 * merge) attach to or reclassify links created by earlier phases, mirroring
 * align.py's `Link` dataclass mutation pattern.
 */
class AlignmentLink
{
    public function __construct(
        public readonly int $src,
        public array $tgt = [],
        public float $score = 0.0,
        public string $kind = 'anchor',
    ) {
    }
}
