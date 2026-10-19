<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Nyholm\Psr7\ServerRequest;
use OCI\Consent\Controller\ConsentAnalyticsHandler;
use OCI\Consent\Controller\ConsentDetailHandler;
use OCI\Consent\Repository\ConsentRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/** Real handlers, mocked storage and renderer; no live session or database claim. */
final class ConsentAccessTest extends TestCase
{
    public function testAnonymousAnalyticsDoesNotReadStorage(): void
    {
        $consents = $this->createMock(ConsentRepositoryInterface::class);
        $consents->expects(self::never())->method('getPeriodComparison');
        $consents->expects(self::never())->method('getDailyTrend');
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::never())->method('belongsToUser');

        $response = (new ConsentAnalyticsHandler($consents, $sites))->handle(
            (new ServerRequest('POST', '/app/consents/analytics'))->withParsedBody(['site_id' => 999]),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAnotherUsersAnalyticsIsDeniedBeforeDataIsRead(): void
    {
        $consents = $this->createMock(ConsentRepositoryInterface::class);
        $consents->expects(self::never())->method('getPeriodComparison');
        $consents->expects(self::never())->method('getDailyTrend');
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::once())->method('belongsToUser')->with(999, 1)->willReturn(false);

        $response = (new ConsentAnalyticsHandler($consents, $sites))->handle(
            $this->analyticsRequest(['site_id' => 999]),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('comparison', (string) $response->getBody());
    }

    #[DataProvider('periods')]
    public function testOwnerCanReadAnalytics(int $requestedDays, int $expectedDays): void
    {
        $consents = $this->createMock(ConsentRepositoryInterface::class);
        $consents->expects(self::once())->method('getPeriodComparison')->with(4, $expectedDays)->willReturn(['current' => ['total' => 3]]);
        $consents->expects(self::once())->method('getDailyTrend')->with(4, $expectedDays, 2)->willReturn([['total_consents' => 3]]);
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::once())->method('belongsToUser')->with(4, 1)->willReturn(true);

        $response = (new ConsentAnalyticsHandler($consents, $sites))->handle(
            $this->analyticsRequest(['site_id' => 4, 'days' => $requestedDays, 'variant_id' => 2]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(3, json_decode((string) $response->getBody(), true)['data']['comparison']['current']['total']);
    }

    public static function periods(): array
    {
        return ['week' => [7, 7], 'month' => [30, 30], 'quarter' => [90, 90], 'invalid defaults to week' => [365, 7]];
    }

    public function testInvalidSiteIdIsRejectedBeforeOwnershipLookup(): void
    {
        $consents = $this->createMock(ConsentRepositoryInterface::class);
        $consents->expects(self::never())->method('getPeriodComparison');
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::never())->method('belongsToUser');

        $response = (new ConsentAnalyticsHandler($consents, $sites))->handle($this->analyticsRequest(['site_id' => 0]));
        self::assertSame(422, $response->getStatusCode());
    }

    public function testAnonymousConsentProofRedirectsBeforeLoadingRecord(): void
    {
        $consents = $this->createMock(ConsentRepositoryInterface::class);
        $consents->expects(self::never())->method('getConsentWithCategories');
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::never())->method('belongsToUser');

        $response = (new ConsentDetailHandler($consents, $twig, $sites))->handle(
            (new ServerRequest('GET', '/app/consents/20'))->withAttribute('id', 20),
        );
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testAnotherUsersConsentProofIsNeverRendered(): void
    {
        $consents = $this->createMock(ConsentRepositoryInterface::class);
        $consents->expects(self::once())->method('getConsentWithCategories')->with(20)->willReturn(['site_id' => 999, 'consent_session' => 'victim-secret']);
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::once())->method('belongsToUser')->with(999, 1)->willReturn(false);

        $response = (new ConsentDetailHandler($consents, $twig, $sites))->handle($this->proofRequest(20));
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('victim-secret', (string) $response->getBody());
    }

    public function testUnknownConsentAndForbiddenConsentUseSameResponse(): void
    {
        $consents = $this->createMock(ConsentRepositoryInterface::class);
        $consents->expects(self::once())->method('getConsentWithCategories')->with(20)->willReturn(null);
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::never())->method('belongsToUser');

        $response = (new ConsentDetailHandler($consents, $twig, $sites))->handle($this->proofRequest(20));
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Consent record not found', json_decode((string) $response->getBody(), true)['error']['message']);
    }

    public function testOwnerCanReadConsentProof(): void
    {
        $record = ['site_id' => 4, 'consent_session' => 'abcdefgh1234'];
        $consents = $this->createMock(ConsentRepositoryInterface::class);
        $consents->expects(self::once())->method('getConsentWithCategories')->with(20)->willReturn($record);
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->with('pages/consent/detail.html.twig', self::callback(
            static fn (array $context): bool => $context['consent'] === $record && $context['shortId'] === 'ABCD-EFGH',
        ))->willReturn('<p>Owner consent proof</p>');
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::once())->method('belongsToUser')->with(4, 1)->willReturn(true);

        $response = (new ConsentDetailHandler($consents, $twig, $sites))->handle($this->proofRequest(20));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<p>Owner consent proof</p>', (string) $response->getBody());
    }

    private function analyticsRequest(array $body): ServerRequest
    {
        return (new ServerRequest('POST', '/app/consents/analytics'))
            ->withAttribute('user', ['id' => 1, 'role' => 'customer'])->withParsedBody($body);
    }

    private function proofRequest(int $id): ServerRequest
    {
        return (new ServerRequest('GET', '/app/consents/' . $id))
            ->withAttribute('user', ['id' => 1, 'role' => 'customer'])->withAttribute('id', $id);
    }
}
