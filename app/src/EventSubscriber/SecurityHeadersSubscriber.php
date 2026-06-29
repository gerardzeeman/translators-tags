<?php

namespace App\EventSubscriber;

use Symfony\Component\AssetMapper\ImportMap\ImportMapRenderer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    private const WEAK_SECRETS = ['changeme', 'change_me', '', 'secret', 'app_secret',
        'change_me_in_production_32chars', 'change_me_generate_with_openssl_rand_hex_32'];

    public function __construct(
        #[Autowire(service: 'asset_mapper.importmap.renderer')]
        private readonly ImportMapRenderer $importMapRenderer,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(APP_SECRET)%')]
        private readonly string $appSecret,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onKernelRequest', 255],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->environment === 'prod' && in_array($this->appSecret, self::WEAK_SECRETS, true)) {
            throw new HttpException(500, 'Application is misconfigured: APP_SECRET is not set to a secure value.');
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $headers  = $response->headers;

        // ── Content-Security-Policy ───────────────────────────────────────────
        // In dev the Symfony profiler toolbar injects its own inline scripts
        // after this subscriber runs, making hash-based CSP impossible without
        // scanning the full response body.  CSP enforcement only matters in
        // production, so in dev we allow unsafe-inline for scripts.
        if ($this->environment === 'prod') {
            $hash = $this->getImportmapHash();
            $headers->set('Content-Security-Policy',
                "default-src 'self'; " .
                "script-src 'self' " .
                    ($hash ? "{$hash} " : '') .
                    "; " .
                "style-src 'self' https://fonts.googleapis.com; " .
                "font-src 'self' https://fonts.gstatic.com; " .
                "img-src 'self' data:; " .
                "connect-src 'self'; " .
                "frame-ancestors 'none'; " .
                "upgrade-insecure-requests;"
            );
        } else {
            // Dev: permissive script-src so the profiler toolbar works.
            $headers->set('Content-Security-Policy',
                "default-src 'self'; " .
                "script-src 'self' 'unsafe-inline' data:; " .
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
                "font-src 'self' https://fonts.gstatic.com; " .
                "img-src 'self' data:; " .
                "connect-src 'self'; " .
                "frame-ancestors 'none';"
            );
        }

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    /**
     * Render just the importmap HTML, extract the inline script content, and
     * return its sha256 hash in CSP format.
     * In prod the result is cached statically (valid for the worker lifetime;
     * workers restart on deploy so the hash is always fresh).
     * In dev the hash is recomputed every request so yarn builds take effect
     * immediately without restarting PHP.
     */
    private function getImportmapHash(): string
    {
        if ($this->environment === 'prod' && self::$importmapHash !== null) {
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
