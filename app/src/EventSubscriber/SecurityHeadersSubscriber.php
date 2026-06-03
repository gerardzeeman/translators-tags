<?php

namespace App\EventSubscriber;

use Symfony\Component\AssetMapper\ImportMap\ImportMapRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds security-related HTTP response headers to every main request.
 *
 * Covers V-05 (CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy)
 * and V-07 (HSTS) from the security vulnerability report.
 *
 * Importmap hash strategy
 * ────────────────────────
 * Symfony's AssetMapper renders an inline <script type="importmap"> whose
 * content changes whenever assets are rebuilt.  We inject ImportMapRenderer
 * directly so we can hash only its ~2 KB JSON output instead of scanning the
 * entire response body.  The result is cached in a static property so the
 * hash is computed at most once per PHP process / opcache lifetime.
 */
class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    /** Cached across requests within the same PHP process. */
    private static ?string $importmapHash = null;

    public function __construct(
        #[Autowire(service: 'asset_mapper.importmap.renderer')]
        private readonly ImportMapRenderer $importMapRenderer,
    ) {}

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

        $hash = $this->getImportmapHash();

        // ── Content-Security-Policy ───────────────────────────────────────────
        $headers->set('Content-Security-Policy',
            "default-src 'self'; " .
            "script-src 'self' data: " .
                ($hash ? "{$hash} " : '') .
                "; " .
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
            "font-src 'self' https://fonts.gstatic.com; " .
            "img-src 'self' data:; " .
            "connect-src 'self'; " .
            "frame-ancestors 'none';"
        );

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    /**
     * Render just the importmap HTML, extract the inline script content, and
     * return its sha256 hash in CSP format.  Cached statically so it is
     * computed at most once per PHP-FPM worker lifetime.
     */
    private function getImportmapHash(): string
    {
        if (self::$importmapHash !== null) {
            return self::$importmapHash;
        }

        // ImportMapRenderer::render() returns only the <script type="importmap">
        // and <script type="module"> tags — a few KB at most.
        $html = $this->importMapRenderer->render('app');

        // Extract every inline <script> block (no src= attribute) and hash each.
        $hashes = [];
        if (preg_match_all('/<script(?![^>]*\bsrc\s*=)[^>]*>(.*?)<\/script>/si', $html, $matches)) {
            foreach ($matches[1] as $content) {
                if (trim($content) !== '') {
                    $hashes[] = "'sha256-" . base64_encode(hash('sha256', $content, true)) . "'";
                }
            }
        }

        self::$importmapHash = implode(' ', array_unique($hashes));
        return self::$importmapHash;
    }
}
