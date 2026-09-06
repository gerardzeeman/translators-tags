<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Soft per-scope (verse/chapter/book) locking for the alignment review UI
 * (plan sectie 5), backed by the `review_lock` table.
 *
 * Deliberately lightweight -- this is a UX aid against two reviewers
 * clobbering each other's work, not a hard distributed lock: expired rows
 * are simply treated as free on the next attempt (no cleanup job), and the
 * hierarchy check below has a small, accepted race window between it and
 * the same-scope acquire immediately after.
 *
 * Hierarchy-aware: acquiring a lock checks both up (does an ancestor scope
 * -- e.g. the whole book -- already have an active lock?) and down (does
 * any descendant scope -- e.g. a verse within the book being requested --
 * have one?), so a book-wide review and a single-verse review of the same
 * book can never both be active at once. Conflicts against the requesting
 * user's own locks are never reported -- only other users can block you.
 */
class ReviewLockService
{
    private const DEFAULT_TTL_SECONDS = 600; // ~10 min, per plan sectie 5
    private const VALID_TYPES = ['verse', 'chapter', 'book'];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function acquire(
        string $scopeType,
        string $scopeId,
        int $userId,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ): LockAcquireResult {
        $this->assertValidType($scopeType);

        $conflict = $this->hierarchyConflict($scopeType, $scopeId, $userId);
        if ($conflict !== null) {
            return new LockAcquireResult(false, $conflict);
        }

        // Atomic upsert: only actually take the row if it's expired or
        // already ours, so a same-scope race with another acquire() can't
        // silently steal an active lock.
        $affected = $this->connection->executeStatement(
            "INSERT INTO review_lock (scope_type, scope_id, user_id, locked_at, expires_at)
             VALUES (:type, :id, :userId, NOW(), NOW() + make_interval(secs => :ttl))
             ON CONFLICT (scope_type, scope_id) DO UPDATE
                SET user_id    = EXCLUDED.user_id,
                    locked_at  = CASE WHEN review_lock.user_id = EXCLUDED.user_id
                                      THEN review_lock.locked_at ELSE EXCLUDED.locked_at END,
                    expires_at = EXCLUDED.expires_at
             WHERE review_lock.expires_at < NOW() OR review_lock.user_id = EXCLUDED.user_id",
            ['type' => $scopeType, 'id' => $scopeId, 'userId' => $userId, 'ttl' => $ttlSeconds]
        );

        if ($affected > 0) {
            return new LockAcquireResult(true);
        }

        $holder = $this->currentHolder($scopeType, $scopeId);

        return new LockAcquireResult(false, $holder);
    }

    /**
     * Extends an already-held lock. Returns false if the lock has expired,
     * was never held, or is now held by someone else -- the caller should
     * treat that as "you no longer have write access, reload".
     */
    public function heartbeat(
        string $scopeType,
        string $scopeId,
        int $userId,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ): bool {
        $this->assertValidType($scopeType);

        $affected = $this->connection->executeStatement(
            "UPDATE review_lock SET expires_at = NOW() + make_interval(secs => :ttl)
             WHERE scope_type = :type AND scope_id = :id AND user_id = :userId AND expires_at > NOW()",
            ['type' => $scopeType, 'id' => $scopeId, 'userId' => $userId, 'ttl' => $ttlSeconds]
        );

        return $affected > 0;
    }

    /**
     * Releases a lock this user holds. A no-op if they don't hold it (e.g.
     * it already expired) -- release is always safe to call.
     */
    public function release(string $scopeType, string $scopeId, int $userId): void
    {
        $this->assertValidType($scopeType);

        $this->connection->executeStatement(
            'DELETE FROM review_lock WHERE scope_type = :type AND scope_id = :id AND user_id = :userId',
            ['type' => $scopeType, 'id' => $scopeId, 'userId' => $userId]
        );
    }

    /**
     * Current holder of this exact scope, for read-only display -- unlike
     * acquire()'s hierarchy check, this does not look at ancestors/descendants,
     * only the exact scope requested.
     */
    public function status(string $scopeType, string $scopeId): ?LockConflict
    {
        $this->assertValidType($scopeType);

        return $this->currentHolder($scopeType, $scopeId);
    }

    private function currentHolder(string $scopeType, string $scopeId): ?LockConflict
    {
        $row = $this->connection->fetchAssociative(
            'SELECT rl.scope_type, rl.scope_id, rl.user_id, rl.locked_at, u.display_name
             FROM review_lock rl
             JOIN users u ON u.id = rl.user_id
             WHERE rl.scope_type = :type AND rl.scope_id = :id AND rl.expires_at > NOW()',
            ['type' => $scopeType, 'id' => $scopeId]
        );

        return $row ? $this->rowToConflict($row) : null;
    }

    /**
     * Looks for an active lock, held by someone else, on an ancestor scope
     * (book for chapter/verse, chapter for verse) or a descendant scope
     * (any chapter/verse within a requested book, any verse within a
     * requested chapter).
     */
    private function hierarchyConflict(string $scopeType, string $scopeId, int $userId): ?LockConflict
    {
        $parts = explode('.', $scopeId);
        $book = $parts[0];

        $conditions = [];
        $params = ['userId' => $userId];

        if ($scopeType === 'chapter' || $scopeType === 'verse') {
            $conditions[] = "(scope_type = 'book' AND scope_id = :book)";
            $params['book'] = $book;
        }
        if ($scopeType === 'verse' && count($parts) >= 2) {
            $conditions[] = "(scope_type = 'chapter' AND scope_id = :chapter)";
            $params['chapter'] = $book . '.' . $parts[1];
        }
        if ($scopeType === 'book') {
            $conditions[] = "(scope_type IN ('chapter', 'verse') AND scope_id LIKE :bookPrefix)";
            $params['bookPrefix'] = $book . '.%';
        }
        if ($scopeType === 'chapter') {
            $conditions[] = "(scope_type = 'verse' AND scope_id LIKE :chapterPrefix)";
            $params['chapterPrefix'] = $scopeId . '.%';
        }

        if (!$conditions) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT rl.scope_type, rl.scope_id, rl.user_id, rl.locked_at, u.display_name
             FROM review_lock rl
             JOIN users u ON u.id = rl.user_id
             WHERE rl.expires_at > NOW() AND rl.user_id != :userId AND (' . implode(' OR ', $conditions) . ')
             ORDER BY rl.locked_at ASC
             LIMIT 1',
            $params
        );

        return $row ? $this->rowToConflict($row) : null;
    }

    private function rowToConflict(array $row): LockConflict
    {
        return new LockConflict(
            $row['scope_type'],
            $row['scope_id'],
            (int) $row['user_id'],
            $row['display_name'],
            new \DateTimeImmutable($row['locked_at']),
        );
    }

    private function assertValidType(string $scopeType): void
    {
        if (!in_array($scopeType, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid scope_type '{$scopeType}', expected one of: " . implode(', ', self::VALID_TYPES));
        }
    }
}
