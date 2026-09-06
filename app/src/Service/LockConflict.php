<?php

namespace App\Service;

/**
 * Describes who currently holds a review_lock, for display ("wordt nu
 * gereviewd door X, sinds Y") and for reporting hierarchical conflicts
 * (e.g. a book-level lock blocking a verse-level acquire within it).
 */
class LockConflict
{
    public function __construct(
        public readonly string $scopeType,
        public readonly string $scopeId,
        public readonly int $userId,
        public readonly string $userDisplayName,
        public readonly \DateTimeImmutable $lockedAt,
    ) {
    }
}
