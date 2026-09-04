<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\LockConflict;
use App\Service\ReviewLockService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API for the review-lock Stimulus controller (plan sectie 5): acquire on
 * page open, heartbeat every ~2 min while open, release on close/navigate.
 * Not yet wired to a page -- the historical-alignment review UI (sectie 6)
 * is a later phase; this endpoint set is built ahead of it so that page can
 * just consume it.
 */
#[Route('/api/review-lock')]
#[IsGranted('ROLE_LINKER')]
class ReviewLockController extends AbstractController
{
    private const VALID_TYPES = ['verse', 'chapter', 'book'];

    public function __construct(
        private readonly ReviewLockService $lockService,
    ) {
    }

    #[Route('/acquire', name: 'app_review_lock_acquire', methods: ['POST'])]
    public function acquire(Request $request): JsonResponse
    {
        [$scope, $error] = $this->parseScope($request);
        if ($error !== null) {
            return $this->json(['success' => false, 'error' => $error], 422);
        }
        if (!$this->isCsrfTokenValid('review_lock_api', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['success' => false, 'error' => 'Invalid request.'], 403);
        }

        /** @var User $user */
        $user = $this->getUser();
        $result = $this->lockService->acquire($scope['type'], $scope['id'], $user->getId());

        return $this->json([
            'success' => $result->success,
            'conflict' => $this->conflictPayload($result->conflict),
        ]);
    }

    #[Route('/heartbeat', name: 'app_review_lock_heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request): JsonResponse
    {
        [$scope, $error] = $this->parseScope($request);
        if ($error !== null) {
            return $this->json(['success' => false, 'error' => $error], 422);
        }
        if (!$this->isCsrfTokenValid('review_lock_api', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['success' => false, 'error' => 'Invalid request.'], 403);
        }

        /** @var User $user */
        $user = $this->getUser();
        $ok = $this->lockService->heartbeat($scope['type'], $scope['id'], $user->getId());

        return $this->json(['success' => $ok]);
    }

    #[Route('/release', name: 'app_review_lock_release', methods: ['POST'])]
    public function release(Request $request): JsonResponse
    {
        [$scope, $error] = $this->parseScope($request);
        if ($error !== null) {
            return $this->json(['success' => false, 'error' => $error], 422);
        }
        if (!$this->isCsrfTokenValid('review_lock_api', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['success' => false, 'error' => 'Invalid request.'], 403);
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->lockService->release($scope['type'], $scope['id'], $user->getId());

        return $this->json(['success' => true]);
    }

    #[Route('/status', name: 'app_review_lock_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $type = (string) $request->query->get('scope_type', '');
        $id = (string) $request->query->get('scope_id', '');
        if (!in_array($type, self::VALID_TYPES, true) || $id === '') {
            return $this->json(['error' => 'Invalid scope_type/scope_id'], 422);
        }

        $conflict = $this->lockService->status($type, $id);

        return $this->json([
            'locked' => $conflict !== null,
            'conflict' => $this->conflictPayload($conflict),
        ]);
    }

    /**
     * @return array{0: ?array{type: string, id: string}, 1: ?string}
     */
    private function parseScope(Request $request): array
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $type = (string) ($data['scope_type'] ?? '');
        $id = (string) ($data['scope_id'] ?? '');

        if (!in_array($type, self::VALID_TYPES, true) || $id === '') {
            return [null, 'Invalid scope_type/scope_id'];
        }

        return [['type' => $type, 'id' => $id], null];
    }

    private function conflictPayload(?LockConflict $conflict): ?array
    {
        if ($conflict === null) {
            return null;
        }

        return [
            'scope_type' => $conflict->scopeType,
            'scope_id' => $conflict->scopeId,
            'user_display_name' => $conflict->userDisplayName,
            'locked_at' => $conflict->lockedAt->format(DATE_ATOM),
        ];
    }
}
