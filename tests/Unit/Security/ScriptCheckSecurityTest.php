<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Nyholm\Psr7\ServerRequest;
use OCI\Dashboard\Controller\ScriptCheckHandler;
use OCI\Infrastructure\Http\ChallengeFetcherInterface;
use OCI\Infrastructure\Http\UrlGuard;
use OCI\Site\Repository\SiteRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Real handler and URL guard, injected DNS/HTTP; never fetches a third-party site. */
final class ScriptCheckSecurityTest extends TestCase
{
    private array $originalEnvironment;

    protected function setUp(): void
    {
        $this->originalEnvironment = $_ENV;
        $_ENV['APP_ENV'] = 'production';
        // A forgotten development override must not open production networking.
        $_ENV['CLAIM_ALLOW_PRIVATE_HOSTS'] = 'true';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnvironment;
    }

    public function testAnonymousRequestCannotInspectOrFetchAnySite(): void
    {
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::never())->method('belongsToUser');
        $sites->expects(self::never())->method('findById');
        $fetcher = $this->createMock(ChallengeFetcherInterface::class);
        $fetcher->expects(self::never())->method('fetch');

        $response = $this->handler($sites, $fetcher)->handle(
            (new ServerRequest('POST', '/app/dashboard/script-check'))->withParsedBody(['site_id' => 9]),
        );
        self::assertSame(401, $response->getStatusCode());
    }

    public function testAnotherUsersSiteCannotBeReadOrFetched(): void
    {
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::once())->method('belongsToUser')->with(999, 1)->willReturn(false);
        $sites->expects(self::never())->method('findById');
        $fetcher = $this->createMock(ChallengeFetcherInterface::class);
        $fetcher->expects(self::never())->method('fetch');

        $response = $this->handler($sites, $fetcher)->handle($this->request(999));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testMissingSiteIdCannotFetch(): void
    {
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::never())->method('belongsToUser');
        $fetcher = $this->createMock(ChallengeFetcherInterface::class);
        $fetcher->expects(self::never())->method('fetch');

        self::assertSame(422, $this->handler($sites, $fetcher)->handle($this->request(0))->getStatusCode());
    }

    #[DataProvider('blockedDomains')]
    public function testProductionBlocksInternalDestinations(string $domain): void
    {
        $fetcher = $this->createMock(ChallengeFetcherInterface::class);
        $fetcher->expects(self::never())->method('fetch');
        // Testsite resolves to a private container IP rather than receiving the
        // development exception. Other names can resolve publicly in this fixture.
        $guard = new UrlGuard(static fn (string $host): array => ['a' => [$host === 'testsite' ? '172.18.0.9' : '93.184.215.14'], 'aaaa' => []]);

        $response = $this->handler($this->ownedSite($domain), $fetcher, $guard)->handle($this->request(9));
        self::assertSame(200, $response->getStatusCode());
        $data = $this->data($response);
        self::assertFalse($data['installed']);
        self::assertStringStartsWith('Website address blocked:', $data['reason']);
    }

    public static function blockedDomains(): array
    {
        return [
            'localhost' => ['localhost'],
            'localhost port remapping removed' => ['localhost:8101'],
            'loopback' => ['127.0.0.1'],
            'private IPv4' => ['10.0.0.1'],
            'metadata' => ['169.254.169.254'],
            'private IPv6' => ['[::1]'],
            'docker alias' => ['testsite'],
            'internal suffix' => ['service.internal'],
            'credentials' => ['https://user:password@fixture.example/'],
            'restricted port' => ['https://fixture.example:2087/'],
        ];
    }

    public function testMixedPublicAndPrivateDnsAnswersAreNeverFetched(): void
    {
        $fetcher = $this->createMock(ChallengeFetcherInterface::class);
        $fetcher->expects(self::never())->method('fetch');
        $guard = new UrlGuard(static fn (string $host): array => ['a' => ['93.184.215.14', '127.0.0.1'], 'aaaa' => []]);

        $data = $this->data($this->handler($this->ownedSite('fixture.example'), $fetcher, $guard)->handle($this->request(9)));
        self::assertFalse($data['installed']);
        self::assertStringStartsWith('Website address blocked:', $data['reason']);
    }

    public function testPublicSiteIsFetchedWithValidatedIpPinAndCanDetectScript(): void
    {
        $fetcher = $this->createMock(ChallengeFetcherInterface::class);
        $fetcher->expects(self::once())->method('fetch')->with('https://fixture.example', [
            'ok' => true, 'reason' => '', 'host' => 'fixture.example', 'port' => 443, 'pin_ip' => '93.184.215.14',
        ])->willReturn($this->fetchResult('<script src="https://consent.example/c/consent.js" data-key="fixture-website-key"></script>'));

        $data = $this->data($this->handler($this->ownedSite('fixture.example'), $fetcher)->handle($this->request(9)));
        self::assertTrue($data['installed']);
    }

    public function testRedirectIsReportedWithoutFollowingOrDetectingRedirectBody(): void
    {
        $fetcher = $this->createMock(ChallengeFetcherInterface::class);
        $fetcher->expects(self::once())->method('fetch')->willReturn($this->fetchResult('consent.js', 302, ['location' => 'http://127.0.0.1/private']));

        $data = $this->data($this->handler($this->ownedSite('fixture.example'), $fetcher)->handle($this->request(9)));
        self::assertFalse($data['installed']);
        self::assertStringContainsString('redirects', $data['reason']);
    }

    public function testTlsFailureDoesNotRetryInsecurelyOrDowngradeToHttp(): void
    {
        $fetcher = $this->createMock(ChallengeFetcherInterface::class);
        $fetcher->expects(self::once())->method('fetch')->with('https://fixture.example', self::anything())->willReturn([
            'ok' => false, 'error' => 'TLS certificate could not be verified', 'status_code' => 0,
            'headers' => [], 'body' => '', 'truncated' => false,
        ]);

        $data = $this->data($this->handler($this->ownedSite('fixture.example'), $fetcher)->handle($this->request(9)));
        self::assertFalse($data['installed']);
        self::assertStringContainsString('TLS certificate', $data['reason']);
    }

    public function testHttpFailurePageCannotReportScriptInstalled(): void
    {
        $fetcher = $this->createMock(ChallengeFetcherInterface::class);
        $fetcher->expects(self::once())->method('fetch')->willReturn($this->fetchResult('consent.js', 500));

        $data = $this->data($this->handler($this->ownedSite('fixture.example'), $fetcher)->handle($this->request(9)));
        self::assertFalse($data['installed']);
    }

    public function testTruncatedPageReportsInconclusiveRatherThanMissingScript(): void
    {
        $fetcher = $this->createMock(ChallengeFetcherInterface::class);
        $fetcher->expects(self::once())->method('fetch')->willReturn($this->fetchResult('<html>Large page', 200, [], true));

        $data = $this->data($this->handler($this->ownedSite('fixture.example'), $fetcher)->handle($this->request(9)));
        self::assertFalse($data['installed']);
        self::assertStringContainsString('could not be confirmed', $data['reason']);
        self::assertStringNotContainsString('script not found', $data['reason']);
    }

    public function testResponseFrameProtectionIsPreserved(): void
    {
        $fetcher = $this->createMock(ChallengeFetcherInterface::class);
        $fetcher->expects(self::once())->method('fetch')->willReturn($this->fetchResult('consent.js', 200, ['x-frame-options' => 'SAMEORIGIN']));

        $data = $this->data($this->handler($this->ownedSite('fixture.example'), $fetcher)->handle($this->request(9)));
        self::assertTrue($data['installed']);
        self::assertFalse($data['frameable']);
    }

    private function request(int $siteId): ServerRequest
    {
        return (new ServerRequest('POST', '/app/dashboard/script-check'))
            ->withAttribute('user', ['id' => 1, 'role' => 'customer'])->withParsedBody(['site_id' => $siteId]);
    }

    private function ownedSite(string $domain): SiteRepositoryInterface
    {
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::once())->method('belongsToUser')->with(9, 1)->willReturn(true);
        $sites->expects(self::once())->method('findById')->with(9)->willReturn(['id' => 9, 'domain' => $domain, 'website_key' => 'fixture-website-key']);
        return $sites;
    }

    private function handler(SiteRepositoryInterface $sites, ChallengeFetcherInterface $fetcher, ?UrlGuard $guard = null): ScriptCheckHandler
    {
        $guard ??= new UrlGuard(static fn (string $host): array => ['a' => ['93.184.215.14'], 'aaaa' => []]);
        return new ScriptCheckHandler($sites, new NullLogger(), $guard, $fetcher);
    }

    private function fetchResult(string $body, int $status = 200, array $headers = [], bool $truncated = false): array
    {
        return ['ok' => true, 'error' => '', 'status_code' => $status, 'headers' => $headers, 'body' => $body, 'truncated' => $truncated];
    }

    private function data(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true)['data'];
    }
}
