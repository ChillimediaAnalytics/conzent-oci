<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use OCI\Http\Middleware\SameOriginMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SameOriginTest extends TestCase
{
    private const BASE = 'https://consent.chillimedia.se';

    #[DataProvider('browserMutations')]
    public function testSameOriginBrowserFormsAndAjaxContinue(string $method, array $headers): void
    {
        $request = new ServerRequest($method, self::BASE . '/app/sites/9', $headers);
        $response = (new SameOriginMiddleware(self::BASE))->process($request, static fn () => new Response(204));
        self::assertSame(204, $response->getStatusCode());
    }

    public static function browserMutations(): array
    {
        return [
            'HTML form' => ['POST', ['Origin' => self::BASE, 'Content-Type' => 'application/x-www-form-urlencoded']],
            'fetch JSON' => ['PUT', ['Origin' => self::BASE, 'Content-Type' => 'application/json']],
            'patch' => ['PATCH', ['Origin' => self::BASE]],
            'delete' => ['DELETE', ['Origin' => self::BASE]],
            'referer fallback' => ['POST', ['Referer' => self::BASE . '/sites/create?step=2']],
            'default port is equivalent' => ['POST', ['Origin' => self::BASE . ':443']],
            'host case insensitive' => ['POST', ['Origin' => 'https://CONSENT.CHILLIMEDIA.SE']],
        ];
    }

    #[DataProvider('untrustedOrigins')]
    public function testUntrustedOrAbsentEvidenceCannotRunMutation(array $headers): void
    {
        $request = (new ServerRequest('POST', self::BASE . '/sites/create', $headers))
            ->withParsedBody(['_csrf_token' => 'present-but-not-a-substitute-for-origin']);
        $called = false;
        $response = (new SameOriginMiddleware(self::BASE))->process($request, static function () use (&$called) {
            $called = true;
            return new Response(204);
        });
        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($called);
    }

    public static function untrustedOrigins(): array
    {
        return [
            'missing both' => [[]],
            'null origin' => [['Origin' => 'null']],
            'blank origin' => [['Origin' => '']],
            'different site' => [['Origin' => 'https://evil.example']],
            'sibling subdomain' => [['Origin' => 'https://chillimedia.se']],
            'suffix confusion' => [['Origin' => 'https://consent.chillimedia.se.evil.example']],
            'different scheme' => [['Origin' => 'http://consent.chillimedia.se']],
            'different port' => [['Origin' => self::BASE . ':8443']],
            'origin cannot contain a path' => [['Origin' => self::BASE . '/sites']],
            'origin cannot contain a query' => [['Origin' => self::BASE . '?x=1']],
            'origin cannot contain fragment' => [['Origin' => self::BASE . '#fragment']],
            'userinfo forbidden' => [['Origin' => 'https://user@consent.chillimedia.se']],
            'multiple origins' => [['Origin' => [self::BASE, 'https://evil.example']]],
            'explicit bad origin cannot use good referer' => [['Origin' => 'https://evil.example', 'Referer' => self::BASE . '/']],
            'null origin cannot use good referer' => [['Origin' => 'null', 'Referer' => self::BASE . '/']],
            'cross-site referer' => [['Referer' => 'https://evil.example/form']],
            'userinfo referer' => [['Referer' => 'https://evil@consent.chillimedia.se/form']],
            'host injection' => [['Origin' => 'https://evil.example', 'Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example']],
        ];
    }

    public function testExpectedOriginComesFromConfigurationNotRequestHost(): void
    {
        $request = new ServerRequest('POST', 'https://evil.example/sites/create', [
            'Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example', 'Origin' => 'https://evil.example',
        ]);
        $response = (new SameOriginMiddleware(self::BASE))->process($request, static fn () => new Response(204));
        self::assertSame(403, $response->getStatusCode());
    }

    public function testInvalidConfiguredOriginFailsClosed(): void
    {
        $request = new ServerRequest('POST', self::BASE . '/', ['Origin' => self::BASE]);
        $response = (new SameOriginMiddleware(''))->process($request, static fn () => new Response(204));
        self::assertSame(503, $response->getStatusCode());
    }

    public function testReadOnlyNavigationDoesNotRequireOrigin(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $response = (new SameOriginMiddleware(self::BASE))->process(
                new ServerRequest($method, self::BASE . '/sites'), static fn () => new Response(204),
            );
            self::assertSame(204, $response->getStatusCode());
        }
    }

    public function testAuthenticatedGroupsUseProtectionWhilePublicAndGuestRoutesRemainSeparate(): void
    {
        $groups = require dirname(__DIR__, 3) . '/config/middleware.php';
        foreach (['web', 'admin', 'agency', 'agency_write'] as $group) {
            self::assertContains(SameOriginMiddleware::class, $groups[$group]);
        }
        foreach (['public_api', 'guest', 'webhook'] as $group) {
            self::assertNotContains(SameOriginMiddleware::class, $groups[$group]);
        }
    }
}
