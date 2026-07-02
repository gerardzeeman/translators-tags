<?php

namespace App\EventSubscriber;

use App\Service\CspNonceProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class CspNonceSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CspNonceProvider $nonceProvider) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -100]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $nonce = $this->nonceProvider->getNonce();

        $csp = implode('; ', [
            "default-src 'self'",
            "style-src 'self' https://fonts.googleapis.com 'unsafe-inline'",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data:",
            "script-src 'self' 'nonce-{$nonce}'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);

        $event->getResponse()->headers->set('Content-Security-Policy', $csp);
    }
}
