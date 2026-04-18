<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Custom CORS Listener - remplace NelmioCorsBundle
 */
class CorsListener
{
    // Domaines autorisés par défaut (développement local)
    private const DEFAULT_ORIGIN_PATTERNS = [
        '/^https?:\/\/localhost(:\d+)?$/',
        '/^https?:\/\/127\.0\.0\.1(:\d+)?$/',
        '/^https?:\/\/192\.168\.\d+\.\d+(:\d+)?$/',
    ];

    // Domaines de production PLANb
    private const PRODUCTION_ORIGINS = [
        'https://app.planb.ci',
        'https://planb.ci',
        'https://www.planb.ci',
        'https://admin.planb.ci',
    ];

    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    private const ALLOWED_HEADERS = 'Content-Type, Authorization, X-Requested-With, Accept';
    private const MAX_AGE = 3600;

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 250)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Gérer les requêtes OPTIONS (preflight)
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response('', 204);
            $this->addCorsHeaders($request, $response);
            $event->setResponse($response);
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 0)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        $this->addCorsHeaders($request, $response);
    }

    private function addCorsHeaders($request, Response $response): void
    {
        $origin = $request->headers->get('Origin');

        if ($origin && $this->isOriginAllowed($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', self::ALLOWED_METHODS);
            $response->headers->set('Access-Control-Allow-Headers', self::ALLOWED_HEADERS);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Max-Age', (string) self::MAX_AGE);
        }

        // Headers de sécurité HTTP
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'"
        );
    }

    private function isOriginAllowed(string $origin): bool
    {
        // Vérifier les domaines de production exacts (constant-time comparison)
        foreach (self::PRODUCTION_ORIGINS as $allowed) {
            if (hash_equals($allowed, $origin)) {
                return true;
            }
        }

        // Vérifier le pattern supplémentaire depuis l'env (supporte dev local + Netlify previews)
        $envPattern = $_ENV['CORS_ALLOW_ORIGIN'] ?? '';
        if ($envPattern !== '' && @preg_match('#' . $envPattern . '#', $origin)) {
            return true;
        }

        // Patterns localhost par défaut
        foreach (self::DEFAULT_ORIGIN_PATTERNS as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }

        return false;
    }
}
