<?php

namespace App\MessageHandler;

use App\Entity\PushSubscription;
use App\Message\SendNewsDigestPush;
use App\Repository\NewsDigestRepository;
use App\Repository\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends a NewsDigest to every PushSubscription belonging to a user entitled
 * to ROLE_CGK_RIJNSBURG_NIEUWS (directly or via role_hierarchy, e.g.
 * ROLE_ADMIN). See docs/push-notifications-plan.md §5.2/§3.2.
 */
#[AsMessageHandler]
final class SendNewsDigestPushHandler
{
    private const ROLE = 'ROLE_CGK_RIJNSBURG_NIEUWS';

    public function __construct(
        private readonly NewsDigestRepository $newsDigestRepository,
        private readonly PushSubscriptionRepository $pushSubscriptionRepository,
        private readonly EntityManagerInterface $em,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
    ) {
    }

    public function __invoke(SendNewsDigestPush $message): void
    {
        $digest = $this->newsDigestRepository->find($message->newsDigestId);
        if ($digest === null) {
            return;
        }

        $subscriptions = $this->pushSubscriptionRepository->findAllForRole(self::ROLE);
        if ($subscriptions === []) {
            return;
        }

        $webPush = new WebPush(
            auth: [
                'VAPID' => [
                    'subject' => 'mailto:beheer@alefomega.nl',
                    'publicKey' => $this->vapidPublicKey,
                    'privateKey' => $this->vapidPrivateKey,
                ],
            ],
            client: new Psr18Client(),
        );

        $payload = json_encode([
            'title' => $digest->getTitle(),
            'body' => $digest->getBody(),
            'url' => $digest->getUrl(),
        ], JSON_THROW_ON_ERROR);

        /** @var array<string, PushSubscription> $byEndpoint */
        $byEndpoint = [];
        foreach ($subscriptions as $subscription) {
            $byEndpoint[$subscription->getEndpoint()] = $subscription;
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->getEndpoint(),
                    'publicKey' => $subscription->getP256dhKey(),
                    'authToken' => $subscription->getAuthKey(),
                    'contentEncoding' => 'aes128gcm',
                ]),
                $payload,
            );
        }

        foreach ($webPush->flush() as $report) {
            $subscription = $byEndpoint[$report->getEndpoint()] ?? null;

            if ($report->isSuccess()) {
                $digest->incrementSuccess();
                continue;
            }

            $digest->incrementFailure();

            if ($subscription === null) {
                continue;
            }

            $statusCode = $report->getResponse()?->getStatusCode();

            // 404/410: browser-abonnement verlopen. 401/403: VAPID-sleutel
            // niet (meer) geldig, bv. na sleutelrotatie (plan §3.2) — deze
            // subscription kan met de huidige sleutel nooit meer slagen.
            if (in_array($statusCode, [401, 403, 404, 410], true)) {
                $this->em->remove($subscription);
            } else {
                $subscription->markFailure();
            }
        }

        $this->em->flush();
    }
}
