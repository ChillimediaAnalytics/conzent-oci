<?php

declare(strict_types=1);

namespace OCI\Dashboard\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Infrastructure\Http\ChallengeFetcherInterface;
use OCI\Infrastructure\Http\UrlGuard;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /app/dashboard/script-check — Check if the consent script is installed on a site.
 *
 * Fetches the site's HTML server-side (to avoid CORS) and checks for
 * the presence of the Conzent/OCI consent script tag.
 */
final class ScriptCheckHandler implements RequestHandlerInterface
{
    public const MAX_BODY_BYTES = 256 * 1024;

    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly LoggerInterface $logger,
        private readonly UrlGuard $urlGuard,
        private readonly ChallengeFetcherInterface $fetcher,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $siteId = (int) ($body['site_id'] ?? 0);

        if ($siteId <= 0) {
            return ApiResponse::error('Invalid site ID', 422);
        }

        if (!$this->siteRepo->belongsToUser($siteId, (int) $user['id'])) {
            return ApiResponse::error('Site not found', 404);
        }

        $site = $this->siteRepo->findById($siteId);
        if ($site === null) {
            return ApiResponse::error('Site not found', 404);
        }

        $domain = (string) ($site['domain'] ?? '');
        $websiteKey = (string) ($site['website_key'] ?? '');

        if ($domain === '') {
            return ApiResponse::success([
                'installed' => false,
                'domain' => '',
                'reason' => 'No domain configured for this site.',
            ]);
        }

        // Use the configured scheme, defaulting host-only domains to HTTPS.
        // Production must never remap localhost to an internal Docker service.
        $url = preg_match('#^https?://#i', $domain) === 1 ? $domain : 'https://' . $domain;
        $result = $this->checkScriptInstalled($url, $websiteKey);

        return ApiResponse::success($result + [
            'domain' => $domain,
            'frameable' => $result['frameable'] ?? true,
        ]);
    }

    /**
     * @return array{installed: bool, reason: string, frameable: bool}
     */
    private function checkScriptInstalled(string $url, string $websiteKey): array
    {
        try {
            $pin = $this->urlGuard->check($url);
            if (!$pin['ok']) {
                return [
                    'installed' => false,
                    'frameable' => false,
                    'reason' => 'Website address blocked: ' . $pin['reason'],
                ];
            }

            // Shared transport verifies TLS, pins the validated DNS address,
            // caps the response body, and never follows redirects. A check in
            // PHP must not bypass the scanner's private-network protections.
            $fetch = $this->fetcher->fetch($url, $pin);
            if (!$fetch['ok']) {
                return [
                    'installed' => false,
                    'frameable' => false,
                    'reason' => 'Could not reach and verify the website. Check its address and TLS certificate.',
                ];
            }

            if ($fetch['status_code'] >= 300 && $fetch['status_code'] < 400) {
                return [
                    'installed' => false,
                    'frameable' => false,
                    'reason' => 'The website redirects. Configure its final website address and retry.',
                ];
            }

            if ($fetch['status_code'] < 200 || $fetch['status_code'] >= 400) {
                return [
                    'installed' => false,
                    'frameable' => false,
                    'reason' => 'The website did not return a successful page response.',
                ];
            }

            $html = $fetch['body'];
            $responseHeaders = [];
            foreach ($fetch['headers'] as $name => $value) {
                $responseHeaders[] = $name . ': ' . $value;
            }

            // Check if the site allows iframing
            $frameable = $this->isFrameable($responseHeaders, $html);

            // Check for the consent script — look for common patterns:
            // 1. The website key in a script src
            // 2. conzent.net or conzent CDN references
            // 3. The OCI consent script pattern
            $patterns = [
                $websiteKey,           // Website key in script tag or data-key attribute
                'consent.js',          // CMP loader script (new path)
                'conzent-cmp.js',      // CMP loader script (legacy path)
                'conzent.net',         // Conzent CDN
                'conzent_banner',      // Legacy script variable
                'oci-consent',         // OCI consent script
                'cookie-consent.js',   // Common script name
            ];

            foreach ($patterns as $pattern) {
                if ($pattern !== '' && stripos($html, $pattern) !== false) {
                    return [
                        'installed' => true,
                        'frameable' => $frameable,
                        'reason' => 'Consent script detected on site.',
                    ];
                }
            }

            return [
                'installed' => false,
                'frameable' => $frameable,
                'reason' => $fetch['truncated']
                    ? 'The website response exceeded the check limit. Script installation could not be confirmed.'
                    : 'Consent script not found on the website. Add the script tag to your site\'s <head> section.',
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Script check failed: ' . $e->getMessage());

            return [
                'installed' => false,
                'frameable' => false,
                'reason' => 'Could not check the website. Please try again.',
            ];
        }
    }

    /**
     * Check HTTP headers and HTML meta tags for frame-blocking directives.
     *
     * @param array<int, string> $headers Raw $http_response_header lines
     */
    private function isFrameable(array $headers, string $html): bool
    {
        // Check X-Frame-Options header
        foreach ($headers as $header) {
            if (stripos($header, 'X-Frame-Options') !== false) {
                $value = strtolower(trim(explode(':', $header, 2)[1] ?? ''));
                if ($value === 'deny' || $value === 'sameorigin') {
                    return false;
                }
            }
            // Check Content-Security-Policy frame-ancestors
            if (stripos($header, 'Content-Security-Policy') !== false) {
                $value = strtolower($header);
                if (preg_match('/frame-ancestors\s+[\'"]?none[\'"]?/', $value)) {
                    return false;
                }
                if (str_contains($value, 'frame-ancestors') && str_contains($value, "'self'")) {
                    return false;
                }
            }
        }

        // Check HTML meta http-equiv for X-Frame-Options
        if (preg_match('/<meta[^>]+http-equiv=["\']X-Frame-Options["\'][^>]+content=["\'](\w+)["\']/i', $html, $m)) {
            $val = strtolower($m[1]);
            if ($val === 'deny' || $val === 'sameorigin') {
                return false;
            }
        }

        return true;
    }
}
