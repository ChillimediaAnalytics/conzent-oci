<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Nyholm\Psr7\ServerRequest;
use OCI\Scanning\Controller\ScanWebhookHandler;
use OCI\Scanning\Repository\ScanRepositoryInterface;
use OCI\Scanning\Service\ScanService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Real handler/service; repository expectations prove which writes are reached. */
final class ScanWebhookAuthTest extends TestCase
{
    private bool $secretWasSet;
    private mixed $originalSecret;

    protected function setUp(): void
    {
        $this->secretWasSet = array_key_exists('SCANNER_WEBHOOK_SECRET', $_ENV);
        $this->originalSecret = $_ENV['SCANNER_WEBHOOK_SECRET'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->secretWasSet) {
            $_ENV['SCANNER_WEBHOOK_SECRET'] = $this->originalSecret;
        } else {
            unset($_ENV['SCANNER_WEBHOOK_SECRET']);
        }
    }

    #[DataProvider('missingSecrets')]
    public function testMissingConfigurationDisablesWebhook(?string $secret): void
    {
        if ($secret === null) {
            unset($_ENV['SCANNER_WEBHOOK_SECRET']);
        } else {
            $_ENV['SCANNER_WEBHOOK_SECRET'] = $secret;
        }
        $repo = $this->createMock(ScanRepositoryInterface::class);
        $repo->expects(self::never())->method('findById');
        $repo->expects(self::never())->method('addScanCookie');

        // Even a supplied header cannot enable an unconfigured endpoint.
        $response = (new ScanWebhookHandler($this->service($repo)))->handle(
            $this->clientRequest()->withHeader('X-Api-Key', 'attacker-key'),
        );
        self::assertSame(503, $response->getStatusCode());
    }

    public static function missingSecrets(): array
    {
        return ['unset' => [null], 'empty' => [''], 'whitespace' => [" \t\n"]];
    }

    #[DataProvider('badKeys')]
    public function testMissingOrIncorrectCredentialsCannotWriteCookies(string $key): void
    {
        $_ENV['SCANNER_WEBHOOK_SECRET'] = 'test-only-callback-secret';
        $repo = $this->createMock(ScanRepositoryInterface::class);
        $repo->expects(self::never())->method('findById');
        $repo->expects(self::never())->method('addScanCookie');

        $response = (new ScanWebhookHandler($this->service($repo)))->handle(
            $this->clientRequest()->withHeader('X-Api-Key', $key),
        );
        self::assertSame(401, $response->getStatusCode());
    }

    public static function badKeys(): array
    {
        return ['missing' => [''], 'wrong' => ['incorrect'], 'prefix only' => ['test-only-callback']];
    }

    public function testAuthenticatedLegacyScannerCallbackCanWriteObservation(): void
    {
        $_ENV['SCANNER_WEBHOOK_SECRET'] = 'test-only-callback-secret';
        $repo = $this->createMock(ScanRepositoryInterface::class);
        $repo->expects(self::once())->method('findById')->with(77)->willReturn(['id' => 77, 'site_id' => 4, 'scan_status' => 'in_progress']);
        $repo->expects(self::once())->method('addScanCookie')->with(77, ['cookie_name' => 'fixture_cookie', 'found_on_url' => 'https://fixture.invalid/']);

        $response = (new ScanWebhookHandler($this->service($repo)))->handle(
            $this->clientRequest()->withHeader('X-Api-Key', 'test-only-callback-secret'),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('received', json_decode((string) $response->getBody(), true)['data']['status']);
    }

    public function testAuthenticatedBatchCallbackIsProcessed(): void
    {
        $_ENV['SCANNER_WEBHOOK_SECRET'] = 'test-only-callback-secret';
        $repo = $this->createMock(ScanRepositoryInterface::class);
        $repo->expects(self::once())->method('findById')->with(77)->willReturn(['id' => 77, 'site_id' => 4, 'scan_status' => 'in_progress']);
        $repo->expects(self::once())->method('updateScan')->with(77, ['callback_received' => 1]);
        $repo->method('countScanUrlsByStatus')->willReturn(1);

        $request = (new ServerRequest('POST', '/api/v1/scan-webhook'))
            ->withHeader('X-Api-Key', 'test-only-callback-secret')
            ->withParsedBody(['scan_id' => 77, 'results' => []]);
        $response = (new ScanWebhookHandler($this->service($repo)))->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('processed', json_decode((string) $response->getBody(), true)['data']['status']);
    }

    public function testUnconfiguredDispatcherFailsBeforeTouchingScanState(): void
    {
        unset($_ENV['SCANNER_WEBHOOK_SECRET']);
        $repo = $this->createMock(ScanRepositoryInterface::class);
        $repo->expects(self::never())->method('updateScan');
        $service = $this->service($repo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SCANNER_WEBHOOK_SECRET is required to dispatch scans');
        (new \ReflectionMethod(ScanService::class, 'executeScan'))->invoke($service, 77, []);
    }

    public function testDispatcherSuppliesRequiredCallbackCredential(): void
    {
        $_ENV['SCANNER_WEBHOOK_SECRET'] = 'test-only-callback-secret';
        $repo = $this->createMock(ScanRepositoryInterface::class);
        $repo->expects(self::once())->method('updateScan')->with(77, self::callback(
            static fn (array $data): bool => $data['scan_status'] === 'in_progress',
        ));
        $server = ['id' => 1, 'is_active' => 1, 'server_name' => 'Fixture scanner'];
        $repo->method('getActiveScanServer')->willReturn($server);
        $repo->expects(self::once())->method('getScanUrls')->with(77, 'pending')->willReturn([
            ['id' => 12, 'url' => 'https://fixture.invalid/'],
        ]);
        $repo->expects(self::once())->method('updateScanUrl')->with(12, ['status' => 'scanning']);
        $service = $this->service($repo);
        (new \ReflectionProperty(ScanService::class, 'logger'))->setValue($service, new NullLogger());
        $dispatched = false;
        $http = static function (array $actualServer, string $endpoint, array $payload) use ($server, &$dispatched): array {
            self::assertSame($server, $actualServer);
            self::assertSame('/scan/batch', $endpoint);
            self::assertSame('test-only-callback-secret', $payload['callback_api_key']);
            self::assertSame(77, $payload['scan_id']);
            self::assertSame(['https://fixture.invalid/'], $payload['urls']);
            self::assertStringEndsWith('/api/v1/scan-webhook', $payload['callback_url']);
            $dispatched = true;
            return ['success' => true];
        };
        (new \ReflectionProperty(ScanService::class, 'scannerHttp'))->setValue($service, $http);

        (new \ReflectionMethod(ScanService::class, 'executeScan'))->invoke($service, 77, [
            'server_id' => null, 'scan_attempts' => 0,
        ]);
        self::assertTrue($dispatched);
    }

    private function clientRequest(): ServerRequest
    {
        return (new ServerRequest('POST', '/api/v1/scan-webhook'))->withParsedBody([
            'action' => 'client_scan', 'scan_id' => 77, 'scan_url' => 'https://fixture.invalid/',
            'data' => ['c' => ['fixture_cookie']],
        ]);
    }

    private function service(ScanRepositoryInterface $repo): ScanService
    {
        // The tested branches only use scanRepo. All other dependencies remain
        // uninitialised so a newly reached side effect fails the regression.
        $service = (new \ReflectionClass(ScanService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ScanService::class, 'scanRepo'))->setValue($service, $repo);
        return $service;
    }
}
