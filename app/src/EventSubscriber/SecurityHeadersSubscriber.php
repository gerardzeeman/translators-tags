<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds security-related HTTP response headers to every main request.
 *
 * Covers V-05 (CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy)
 * and V-07 (HSTS) from the security vulnerability report.
 */
class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers  = $response->headers;

        // Content-Security-Policy
        // - style-src: allows Google Fonts stylesheet + inline styles (Tailwind)
        // - font-src:  allows Google Fonts webfont files from fonts.gstatic.com
        // - script-src: hashes cover the inline <script> blocks in Twig templates.
        //   The importmap hash (last entry) changes whenever assets are rebuilt
        //   (yarn build / npm run build adds/renames files → importmap JSON changes).
        //   When you see "Executing inline script violates CSP" in the console,
        //   copy the sha256-... value the browser reports and replace the last hash here.
        $headers->set('Content-Security-Policy',
            "default-src 'self'; " .
            "script-src 'self' data: " .
                "'sha256-yz1FnBjYE3IRDro+PwRcgrDgsHOR5S2cmWOM3yUTCLU=' " .
                "'sha256-ECLG8qY1BzpZZkmvGwzIAvYx4rYATTBbdp22iY7QEhs=' " .
                "'sha256-6PeNBy+owUm6Gd8Z5yEum0eIWDj1/qFaCZ3n8xIKraY=' " .
                "'sha256-zrQsxlpBhYKr5BaUD6lKOzP/Zwum0kXIjOc9jbGM03k=' " .
                // importmap hash — regenerated when new JS assets are added:
                "'sha256-rhTIdkDR1Nm7nOZsqG6UiWJVIuMYHIJPfQQLfsc0BvU='; " .
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
            "font-src 'self' https://fonts.gstatic.com; " .
            "img-src 'self' data:; " .
            "connect-src 'self'; " .
            "frame-ancestors 'none';"
        );

        // Prevent clickjacking via iframes (belt-and-suspenders alongside CSP frame-ancestors)
        $headers->set('X-Frame-Options', 'DENY');

        // Prevent full URL from leaking to third-party origins on navigation
        $headers->set('Referrer-Policy', 'same-origin');

        // Disable browser features not used by the application
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // HTTP Strict Transport Security — only meaningful over HTTPS (V-07)
        // max-age=31536000 = 1 year; includeSubDomains added for defence in depth
        $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
