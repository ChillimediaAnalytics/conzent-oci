<?php

declare(strict_types=1);

namespace OCI\Http\Middleware;

use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * CSRF protection for authenticated browser mutations.
 *
 * Existing forms use different single-use token intents, and many dashboard
 * AJAX requests have no token. Require browser-supplied same-origin evidence
 * centrally, retaining each form's own token validation. Public consent and
 * machine webhook routes do not use this middleware group.
 */
final class SameOriginMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $baseUrl,
    ) {}

    public function process(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if (!in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        // APP_URL is operator configuration. Never trust Host, forwarded host,
        // or request URI when deciding which origin may spend a login cookie.
        $expected = $this->origin($this->baseUrl);
        if ($expected === null) {
            return ApiResponse::error('Application origin is not configured', 503);
        }

        // An explicitly supplied null, malformed, or different Origin cannot
        // be rescued by a same-origin Referer. No evidence fails closed too.
        $provided = $request->hasHeader('Origin')
            ? $this->origin($request->getHeaderLine('Origin'), true)
            : $this->origin($request->getHeaderLine('Referer'));

        if ($provided === null || !hash_equals($expected, $provided)) {
            return ApiResponse::error('Request origin could not be verified. Reload the application and try again.', 403);
        }

        return $next($request);
    }

    private function origin(string $url, bool $originHeader = false): ?string
    {
        if ($url === '' || preg_match('/[\s\x00-\x1f\x7f]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        if ($originHeader && (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']))) {
            return null;
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port <= 0 || $port > 65535) {
            return null;
        }
        return $scheme . '://' . $host . ':' . $port;
    }
}
