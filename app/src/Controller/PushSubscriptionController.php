<?php

namespace App\Controller;

use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API for the push_subscribe Stimulus controller (profile page): subscribe/
 * unsubscribe to the "Dagelijks CGK/Rijnsburg nieuwsoverzicht" push
 * notification. See docs/push-notifications-plan.md §5.1.
 */
#[Route('/account/meldingen')]
#[IsGranted('ROLE_CGK_RIJNSBURG_NIEUWS')]
class PushSubscriptionController extends AbstractController
{
    private const MAX_SUBSCRIPTIONS_PER_USER = 5;

    public function __construct(
        private readonly PushSubscriptionRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/abonneren', name: 'app_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('push_notifications', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Ongeldig verzoek.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $endpoint = is_string($data['endpoint'] ?? null) ? $data['endpoint'] : '';
        $keys = is_array($data['keys'] ?? null) ? $data['keys'] : [];
        $p256dh = is_string($keys['p256dh'] ?? null) ? $keys['p256dh'] : '';
        $auth = is_string($keys['auth'] ?? null) ? $keys['auth'] : '';

        if (!$this->isValidSubscriptionPayload($endpoint, $p256dh, $auth)) {
            return $this->json(['error' => 'Ongeldige abonnementsgegevens.'], 422);
        }

        /** @var User $user */
        $user = $this->getUser();

        $subscription = $this->repository->findOneByUserAndEndpoint($user, $endpoint);
        if ($subscription === null && $this->repository->countByUser($user) >= self::MAX_SUBSCRIPTIONS_PER_USER) {
            return $this->json(['error' => 'Maximaal ' . self::MAX_SUBSCRIPTIONS_PER_USER . ' apparaten per account.'], 429);
        }

        if ($subscription === null) {
            $subscription = new PushSubscription($user, $endpoint);
        }
        $subscription->setKeys($p256dh, $auth);
        $subscription->setUserAgent(substr((string) $request->headers->get('User-Agent', ''), 0, 255));

        $this->em->persist($subscription);
        $this->em->flush();

        return $this->json(['status' => 'ok']);
    }

    #[Route('/opzeggen', name: 'app_push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('push_notifications', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Ongeldig verzoek.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $endpoint = is_string($data['endpoint'] ?? null) ? $data['endpoint'] : '';

        /** @var User $user */
        $user = $this->getUser();

        // Scopen op de ingelogde gebruiker (niet alleen op endpoint) --
        // anders kan elke abonnee andermans abonnement opzeggen (plan §8,
        // HOOG-bevinding, verwerkt in §5.1). Geen match: stil niets doen.
        $subscription = $this->repository->findOneByUserAndEndpoint($user, $endpoint);
        if ($subscription !== null) {
            $this->em->remove($subscription);
            $this->em->flush();
        }

        return $this->json(['status' => 'ok']);
    }

    private function isValidSubscriptionPayload(string $endpoint, string $p256dh, string $auth): bool
    {
        return str_starts_with($endpoint, 'https://')
            && strlen($endpoint) <= 512
            && (bool) preg_match('#^[A-Za-z0-9_-]{60,}$#', $p256dh)
            && (bool) preg_match('#^[A-Za-z0-9_-]{16,}$#', $auth);
    }
}
