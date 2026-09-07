<?php

namespace App\Controller;

use App\Entity\NewsDigest;
use App\Message\SendNewsDigestPush;
use App\Repository\NewsDigestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Machine-to-machine webhook for the "Dagelijks CGK/Rijnsburg nieuwsoverzicht"
 * scheduled task. See docs/push-notifications-plan.md §5.2.
 */
class NewsDigestWebhookController extends AbstractController
{
    public function __construct(
        private readonly NewsDigestRepository $newsDigestRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'limiter.news_digest_push')]
        private readonly RateLimiterFactory $rateLimiterFactory,
        private readonly bool $newsDigestPushEnabled,
        private readonly string $newsDigestWebhookToken,
    ) {
    }

    #[Route('/api/nieuwsoverzicht/push', name: 'app_news_digest_push', methods: ['POST'])]
    public function push(Request $request): JsonResponse
    {
        // 1. Kill switch (plan §3.1) — geen tokencheck, geen logging.
        if (!$this->newsDigestPushEnabled) {
            return $this->json(['error' => 'Push-verzending is gepauzeerd.'], 503);
        }

        // 2. Token (timing-safe).
        $authHeader = $request->headers->get('Authorization', '');
        $token = str_starts_with($authHeader, 'Bearer ') ? substr($authHeader, 7) : '';
        if ($this->newsDigestWebhookToken === '' || !hash_equals($this->newsDigestWebhookToken, $token)) {
            return $this->json(['error' => 'Ongeldig token.'], 403);
        }

        // 3. Rate limit — begrenst zowel misbruik van een gelekt token als
        // een verkeerd geconfigureerde scheduled task (plan §5.2).
        $limit = $this->rateLimiterFactory->create('news_digest_push')->consume();
        if (!$limit->isAccepted()) {
            return $this->json(['error' => 'Te veel verzoeken.'], 429);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['title'], $data['body']) || !is_string($data['title']) || !is_string($data['body'])) {
            return $this->json(['error' => 'title en body zijn verplicht.'], 422);
        }

        $title = trim($data['title']);
        $body = trim($data['body']);
        $url = isset($data['url']) && is_string($data['url']) ? $data['url'] : null;

        if ($title === '' || $body === '') {
            return $this->json(['error' => 'title en body mogen niet leeg zijn.'], 422);
        }

        // 4. Idempotentie — client-sleutel of server-fallback op de huidige
        // UTC-datum, passend bij een taak die hooguit eens per dag draait.
        $idempotencyKey = isset($data['idempotency_key']) && is_string($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? substr($data['idempotency_key'], 0, 32)
            : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');

        $existing = $this->newsDigestRepository->findOneByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $this->json([
                'status' => 'already_sent',
                'success_count' => $existing->getSuccessCount(),
                'failure_count' => $existing->getFailureCount(),
            ]);
        }

        // 5. url-validatie — alleen een relatief, same-origin pad.
        if (!$this->isValidRelativePath($url)) {
            return $this->json(['error' => 'url moet een relatief pad zijn (bv. "/blog/voorbeeld"), geen absolute URL.'], 422);
        }

        // 6. Opslaan + dispatchen.
        $digest = new NewsDigest($idempotencyKey, $title, $body, $url);
        $this->em->persist($digest);
        $this->em->flush();

        $this->bus->dispatch(new SendNewsDigestPush($digest->getId()));

        return $this->json(['status' => 'queued'], 202);
    }

    private function isValidRelativePath(?string $url): bool
    {
        if ($url === null || $url === '') {
            return true; // optioneel veld, sw.js valt terug op '/'
        }
        // moet beginnen met exact één '/': geen 'https://...' (absoluut),
        // geen '//evil.tld' (protocol-relative), geen 'javascript:'/'data:'
        return (bool) preg_match('#^/(?!/)[A-Za-z0-9/_\-.]*$#', $url);
    }
}
