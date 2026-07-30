<?php

namespace App\Repository;

use App\Service\WordDiffer;
use Doctrine\DBAL\Connection;

/**
 * InstitutioProposalRepository
 * Raw DBAL queries against translation_proposal/translation_proposal_event
 * (see db/migrate_add_translation_proposals.sql) -- the propose-then-review
 * workflow for editing the Institutio corpus's own (LLM) translation.
 * Deliberately separate from InstitutioRepository: this is a small
 * discussion/workflow state machine, not corpus-text retrieval, and has no
 * dependency on that repository at all (every id it needs is passed in by
 * the caller).
 */
class InstitutioProposalRepository
{
    public function __construct(
        private readonly Connection  $connection,
        private readonly WordDiffer  $wordDiffer,
    ) {}

    /**
     * Thin pass-through to Connection::transactional(), so callers that
     * need to combine an event/status write here with an apply-the-text
     * write on InstitutioRepository (approving a proposal) can wrap both
     * in one transaction without the controller reaching past its
     * repositories to touch Connection directly. Both repositories share
     * the same autowired Connection instance, so calls made to either one
     * inside $fn participate in the same transaction.
     */
    public function transactional(callable $fn): mixed
    {
        return $this->connection->transactional($fn);
    }

    /**
     * Active (non-approved -- pending or rejected, since a rejection isn't
     * final) proposals for a segment, keyed for the translate page's
     * readonly-row / "Bekijk voorstel"-button logic.
     *
     * @return array{whole: array{id: int, status: string}|null, rows: array<int, array{id: int, status: string}>}
     */
    public function getActiveProposalsForSegment(int $segmentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, sentence_alignment_id, status
             FROM translation_proposal
             WHERE segment_id = :segment_id AND status != 'approved'",
            ['segment_id' => $segmentId]
        );

        $result = ['whole' => null, 'rows' => []];
        foreach ($rows as $r) {
            $entry = ['id' => (int) $r['id'], 'status' => $r['status']];
            if ($r['sentence_alignment_id'] === null) {
                $result['whole'] = $entry;
            } else {
                $result['rows'][(int) $r['sentence_alignment_id']] = $entry;
            }
        }
        return $result;
    }

    /**
     * Creates a new proposal, computing its word_diff automatically from
     * $oldText/$newText (never entered by hand -- see WordDiffer). Looks up
     * the segment's LLM translation_id itself (same lookup
     * InstitutioRepository::saveSegmentTranslation() already does) rather
     * than requiring the caller to supply it, so callers only ever need to
     * pass around a segment id, never leak translation ids across
     * repositories.
     *
     * Throws (a unique-constraint violation, since only one active proposal
     * per target is allowed) if one already exists for this segment/row --
     * callers should check getActiveProposalsForSegment() first to avoid
     * hitting this in the normal UI flow; the constraint is defense in
     * depth, not the primary guard. Throws \RuntimeException if the segment
     * has no LLM translation at all yet (shouldn't happen via the UI, which
     * only reaches this from an already-rendered translation).
     */
    public function createTranslationProposal(
        int $segmentId,
        ?int $sentenceAlignmentId,
        string $oldText,
        string $newText,
        string $reason,
        int $createdByUserId
    ): int {
        $translationId = $this->connection->fetchOne(
            "SELECT id FROM translation WHERE segment_id = :segment_id AND layer = 'llm'",
            ['segment_id' => $segmentId]
        );
        if ($translationId === false) {
            throw new \RuntimeException("Segment {$segmentId} heeft nog geen eigen vertaling.");
        }

        $wordDiff = json_encode(
            $this->wordDiffer->diff($oldText, $newText),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return (int) $this->connection->fetchOne(
            'INSERT INTO translation_proposal
                (segment_id, translation_id, sentence_alignment_id, old_text, new_text, reason, word_diff, created_by_user_id)
             VALUES (:segment_id, :translation_id, :sentence_alignment_id, :old_text, :new_text, :reason, :word_diff, :created_by_user_id)
             RETURNING id',
            [
                'segment_id'            => $segmentId,
                'translation_id'        => (int) $translationId,
                'sentence_alignment_id' => $sentenceAlignmentId,
                'old_text'              => $oldText,
                'new_text'              => $newText,
                'reason'                => $reason,
                'word_diff'             => $wordDiff,
                'created_by_user_id'    => $createdByUserId,
            ]
        );
    }

    /**
     * Lightweight lookup for ownership/self-review checks and for applying
     * an approval (needs new_text), without the full timeline (events +
     * word_diff decode) getProposalTimeline() builds.
     *
     * @return array{
     *   id: int, segment_id: int, translation_id: int, sentence_alignment_id: ?int,
     *   new_text: string, status: string, created_by_user_id: int
     * }|null
     */
    public function findProposal(int $proposalId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, segment_id, translation_id, sentence_alignment_id, new_text, status, created_by_user_id
             FROM translation_proposal WHERE id = :id',
            ['id' => $proposalId]
        );
        if ($row === false) {
            return null;
        }
        return [
            'id'                    => (int) $row['id'],
            'segment_id'            => (int) $row['segment_id'],
            'translation_id'        => (int) $row['translation_id'],
            'sentence_alignment_id' => $row['sentence_alignment_id'] !== null ? (int) $row['sentence_alignment_id'] : null,
            'new_text'              => $row['new_text'],
            'status'                => $row['status'],
            'created_by_user_id'    => (int) $row['created_by_user_id'],
        ];
    }

    /**
     * Full proposal + ordered discussion/decision timeline, with display
     * names resolved, for the review side panel.
     *
     * @return array{
     *   id: int, segment_id: int, sentence_alignment_id: ?int,
     *   old_text: string, new_text: string, reason: string,
     *   word_diff: array<int, array{op: string, text: string}>,
     *   status: string, created_at: \DateTimeImmutable, resolved_at: ?\DateTimeImmutable,
     *   created_by: array{id: int, display_name: string},
     *   events: array<int, array{
     *     id: int, kind: string, body: ?string, created_at: \DateTimeImmutable,
     *     user: array{id: int, display_name: string}
     *   }>
     * }|null
     */
    public function getProposalTimeline(int $proposalId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT tp.id, tp.segment_id, tp.sentence_alignment_id, tp.old_text, tp.new_text,
                    tp.reason, tp.word_diff, tp.status, tp.created_at, tp.resolved_at,
                    u.id AS created_by_id, u.display_name AS created_by_name
             FROM translation_proposal tp
             JOIN users u ON u.id = tp.created_by_user_id
             WHERE tp.id = :id',
            ['id' => $proposalId]
        );
        if ($row === false) {
            return null;
        }

        $eventRows = $this->connection->fetchAllAssociative(
            'SELECT e.id, e.kind, e.body, e.created_at, u.id AS user_id, u.display_name AS user_name
             FROM translation_proposal_event e
             JOIN users u ON u.id = e.user_id
             WHERE e.proposal_id = :id
             ORDER BY e.created_at, e.id',
            ['id' => $proposalId]
        );

        return [
            'id'                    => (int) $row['id'],
            'segment_id'            => (int) $row['segment_id'],
            'sentence_alignment_id' => $row['sentence_alignment_id'] !== null ? (int) $row['sentence_alignment_id'] : null,
            'old_text'              => $row['old_text'],
            'new_text'              => $row['new_text'],
            'reason'                => $row['reason'],
            'word_diff'             => json_decode($row['word_diff'], true, 512, JSON_THROW_ON_ERROR),
            'status'                => $row['status'],
            'created_at'            => new \DateTimeImmutable($row['created_at']),
            'resolved_at'           => $row['resolved_at'] !== null ? new \DateTimeImmutable($row['resolved_at']) : null,
            'created_by'            => ['id' => (int) $row['created_by_id'], 'display_name' => $row['created_by_name']],
            'events'                => array_map(
                static fn($e) => [
                    'id'         => (int) $e['id'],
                    'kind'       => $e['kind'],
                    'body'       => $e['body'],
                    'created_at' => new \DateTimeImmutable($e['created_at']),
                    'user'       => ['id' => (int) $e['user_id'], 'display_name' => $e['user_name']],
                ],
                $eventRows
            ),
        ];
    }

    /**
     * Records a discussion/decision event. 'approve'/'reject' also flip
     * translation_proposal.status -- 'approve' additionally sets
     * resolved_at (the only terminal status); a 'reject' does NOT set
     * resolved_at, since rejection isn't final and discussion can still
     * lead to a later approval on the same proposal.
     *
     * Does not itself apply the approved text anywhere -- see
     * InstitutioProposalController::approve(), which calls this and then
     * InstitutioRepository's existing saveSegmentTranslation()/
     * saveSegmentRowTranslations() within one transaction.
     *
     * Body-required-per-kind validation is the controller's job (form
     * validation at the request boundary), not this method's.
     */
    public function addProposalEvent(int $proposalId, int $userId, string $kind, ?string $body): void
    {
        $this->connection->executeStatement(
            'INSERT INTO translation_proposal_event (proposal_id, user_id, kind, body)
             VALUES (:proposal_id, :user_id, :kind, :body)',
            ['proposal_id' => $proposalId, 'user_id' => $userId, 'kind' => $kind, 'body' => $body]
        );

        if ($kind === 'approve') {
            $this->connection->executeStatement(
                "UPDATE translation_proposal SET status = 'approved', resolved_at = now() WHERE id = :id",
                ['id' => $proposalId]
            );
        } elseif ($kind === 'reject') {
            $this->connection->executeStatement(
                "UPDATE translation_proposal SET status = 'rejected' WHERE id = :id",
                ['id' => $proposalId]
            );
        }
    }
}
