<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use DI\ContainerBuilder;
use OCI\Dashboard\Controller\ScriptCheckHandler;
use OCI\Infrastructure\Http\ChallengeFetcherInterface;
use OCI\Infrastructure\Http\CurlChallengeFetcher;
use OCI\Infrastructure\Http\UrlGuard;
use OCI\Site\Repository\SiteRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Real cURL against a disposable loopback fixture; never a third-party target. */
final class BoundedFetchTest extends TestCase
{
    private static mixed $server = null;
    private static array $pipes = [];
    private static string $router = '';
    private static int $port;

    public static function setUpBeforeClass(): void
    {
        if (!extension_loaded('curl') || !function_exists('proc_open')) {
            self::markTestSkipped('cURL and proc_open are required for the local HTTP fixture');
        }

        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        if ($listener === false) {
            throw new \RuntimeException('Could not reserve loopback fixture port');
        }
        self::$port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);

        self::$router = tempnam(sys_get_temp_dir(), 'oci-fetch-fixture-');
        file_put_contents(self::$router, '<?php header("Content-Type: text/html"); echo str_repeat("x", min(300000, max(0, (int) ($_GET["size"] ?? 300000)))); echo "<script src=consent.js></script>";');
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, self::$router],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            self::$pipes,
        );
        if (!is_resource(self::$server)) {
            throw new \RuntimeException('Could not start loopback HTTP fixture');
        }

        for ($attempt = 0; $attempt < 40; ++$attempt) {
            $connection = @stream_socket_client('tcp://127.0.0.1:' . self::$port, $errorCode, $error, 0.05);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(25000);
        }
        throw new \RuntimeException('Loopback HTTP fixture did not become ready');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            foreach (self::$pipes as $pipe) {
                fclose($pipe);
            }
            proc_close(self::$server);
        }
        if (self::$router !== '' && is_file(self::$router)) {
            unlink(self::$router);
        }
    }

    public function testClaimDefaultStillCapsBodyAtFourKiB(): void
    {
        $result = $this->fetch(new CurlChallengeFetcher(new NullLogger()));
        self::assertTrue($result['ok']);
        self::assertSame(200, $result['status_code']);
        self::assertTrue($result['truncated']);
        self::assertSame(4096, strlen($result['body']));
    }

    public function testHomepageTransportCapsBodyAt256KiB(): void
    {
        $result = $this->fetch(new CurlChallengeFetcher(new NullLogger(), ScriptCheckHandler::MAX_BODY_BYTES));
        self::assertTrue($result['ok']);
        self::assertSame(200, $result['status_code']);
        self::assertTrue($result['truncated']);
        self::assertSame(262144, strlen($result['body']));
    }

    public function testHomepageCanReadScriptBeyondClaimResponseLimit(): void
    {
        $result = $this->fetch(new CurlChallengeFetcher(new NullLogger(), ScriptCheckHandler::MAX_BODY_BYTES), '?size=32768');
        self::assertTrue($result['ok']);
        self::assertFalse($result['truncated']);
        self::assertStringContainsString('<script src=consent.js></script>', $result['body']);
        self::assertGreaterThan(4096, strlen($result['body']));
    }

    public function testServiceFactoriesKeepDifferentClaimAndHomepageLimits(): void
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions(require dirname(__DIR__, 3) . '/config/services.php');
        $builder->addDefinitions([
            SiteRepositoryInterface::class => $this->createMock(SiteRepositoryInterface::class),
            LoggerInterface::class => new NullLogger(),
            UrlGuard::class => new UrlGuard(),
        ]);
        $container = $builder->build();
        $claims = $container->get(ChallengeFetcherInterface::class);
        $handler = $container->get(ScriptCheckHandler::class);
        $homepage = (new \ReflectionProperty(ScriptCheckHandler::class, 'fetcher'))->getValue($handler);
        $limit = new \ReflectionProperty(CurlChallengeFetcher::class, 'maxBodyBytes');

        self::assertSame(4096, $limit->getValue($claims));
        self::assertSame(262144, $limit->getValue($homepage));
    }

    public function testNonPositiveBodyLimitIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CurlChallengeFetcher(new NullLogger(), 0);
    }

    private function fetch(CurlChallengeFetcher $fetcher, string $query = ''): array
    {
        // Transport tests intentionally supply a fixture pin directly. Real
        // callers only obtain pins from UrlGuard, which rejects this address.
        return $fetcher->fetch('http://127.0.0.1:' . self::$port . '/' . $query, [
            'host' => '127.0.0.1', 'port' => self::$port, 'pin_ip' => '127.0.0.1',
        ]);
    }
}
