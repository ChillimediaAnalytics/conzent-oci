<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Doctrine\DBAL\Connection;
use Nyholm\Psr7\ServerRequest;
use OCI\Banner\Controller\GenerateScriptHandler;
use OCI\Banner\Service\ScriptGenerationService;
use OCI\Site\Repository\SiteRepositoryInterface;
use PHPUnit\Framework\TestCase;

/** Real handler/generator; mocked DB verifies access gates before generation. */
final class GenerateScriptAccessTest extends TestCase
{
    public function testAnonymousCannotGenerateScripts(): void
    {
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::never())->method('belongsToUser');
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchAssociative');
        $response = (new GenerateScriptHandler($this->generator($db), $sites))->handle(
            new ServerRequest('POST', '/app/sites/9/generate-script'),
        );
        self::assertSame(401, $response->getStatusCode());
    }

    public function testAnotherUsersSiteCannotGenerateScripts(): void
    {
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::once())->method('belongsToUser')->with(999, 1)->willReturn(false);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchAssociative');
        $response = (new GenerateScriptHandler($this->generator($db), $sites))->handle($this->request(999));
        self::assertSame(404, $response->getStatusCode());
    }

    public function testInvalidSiteCannotGenerateScripts(): void
    {
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::never())->method('belongsToUser');
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchAssociative');
        $response = (new GenerateScriptHandler($this->generator($db), $sites))->handle($this->request(-9));
        self::assertSame(400, $response->getStatusCode());
    }

    public function testOwnerRequestUsesStandardUserAttributeAndReachesGenerator(): void
    {
        $sites = $this->createMock(SiteRepositoryInterface::class);
        $sites->expects(self::once())->method('belongsToUser')->with(9, 1)->willReturn(true);
        $db = $this->createMock(Connection::class);
        // Simulate deletion between authorization and generation. This proves
        // the valid user attribute reaches the real generator without needing
        // to write a generated script in an authorization unit test.
        $db->expects(self::once())->method('fetchAssociative')
            ->with('SELECT * FROM oci_sites WHERE id = :id', ['id' => 9])->willReturn(false);
        $response = (new GenerateScriptHandler($this->generator($db), $sites))->handle($this->request(9));
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Script generation failed', json_decode((string) $response->getBody(), true)['error']['message']);
    }

    private function request(int $id): ServerRequest
    {
        return (new ServerRequest('POST', '/app/sites/' . $id . '/generate-script'))
            ->withAttribute('id', $id)->withAttribute('user', ['id' => 1]);
    }

    private function generator(Connection $db): ScriptGenerationService
    {
        $service = (new \ReflectionClass(ScriptGenerationService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ScriptGenerationService::class, 'db'))->setValue($service, $db);
        return $service;
    }
}
