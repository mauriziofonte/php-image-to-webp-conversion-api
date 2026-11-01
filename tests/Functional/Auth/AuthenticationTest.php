<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\Auth;

use Mfonte\ImageConversionApi\Auth\ApiKeyAuthenticator;
use Mfonte\ImageConversionApi\Auth\IpAllowlist;
use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Http\Kernel;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Authentication as the kernel built by KernelFactory applies it: every
 * protected path needs an accepted key, /healthz needs none, keys rotate,
 * without a configured key the service refuses everything protected with
 * 503 (findings C-1 and C-4), and the optional IP allowlist judges the
 * client address that TRUSTED_PROXIES vouch for.
 *
 * @covers \Mfonte\ImageConversionApi\Auth\ApiKeyAuthenticator
 * @covers \Mfonte\ImageConversionApi\Auth\IpAllowlist
 * @covers \Mfonte\ImageConversionApi\Http\Kernel
 */
#[CoversClass(ApiKeyAuthenticator::class)]
#[CoversClass(IpAllowlist::class)]
#[CoversClass(Kernel::class)]
final class AuthenticationTest extends TestCase
{
    private const KEY = 'current-key-0123456789';

    private const NEXT_KEY = 'next-key-9876543210';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var MemoryLogger
     */
    private $logger;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->logger = new MemoryLogger();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function provideProtectedPaths(): array
    {
        return [
            'v2' => ['POST', '/v2/convert', 400],
            'v1' => ['POST', '/v1/convert-now', 400],
            'metrics' => ['GET', '/metrics', 200],
            'unknown path' => ['GET', '/missing', 404],
            'wrong method' => ['GET', '/v2/convert', 405],
        ];
    }

    /**
     * @dataProvider provideProtectedPaths
     */
    #[DataProvider('provideProtectedPaths')]
    public function testAProtectedPathNeedsAKey(string $method, string $path, int $status): void
    {
        $missing = $this->handle($method, $path, []);
        $wrong = $this->handle($method, $path, ['HTTP_AUTHORIZATION' => 'Bearer wrong-key']);
        $accepted = $this->handle($method, $path, ['HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY]);

        self::assertSame(401, $missing->getStatusCode());
        self::assertSame(ResponseFactory::ERROR_MISSING_API_KEY, self::errorCode($missing));
        self::assertSame('Bearer realm="image-conversion-api"', $missing->headers->get('WWW-Authenticate'));
        self::assertSame(401, $wrong->getStatusCode());
        self::assertSame(ResponseFactory::ERROR_INVALID_API_KEY, self::errorCode($wrong));
        self::assertSame('Bearer realm="image-conversion-api", error="invalid_token"', $wrong->headers->get('WWW-Authenticate'));
        self::assertSame($status, $accepted->getStatusCode(), 'an unauthenticated client cannot tell a 404 or a 405 from a 401');
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function provideHeaderShapes(): array
    {
        return [
            'Bearer (nginx and PHP-FPM, Apache with CGIPassAuth On)' => [['HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY]],
            'Bearer through a rewrite rule (Apache, E=HTTP_AUTHORIZATION)' => [['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY]],
            'X-API-Key / X-Api-Key' => [['HTTP_X_API_KEY' => self::KEY]],
            '-x-api-key (2.x proxy)' => [['HTTP__X_API_KEY' => self::KEY]],
        ];
    }

    /**
     * @dataProvider provideHeaderShapes
     *
     * @param array<string, string> $server
     */
    #[DataProvider('provideHeaderShapes')]
    public function testEveryHeaderShapeReachesTheEndpoint(array $server): void
    {
        self::assertSame(400, $this->handle('POST', '/v2/convert', $server)->getStatusCode());
    }

    public function testKeysRotate(): void
    {
        $both = ['API_KEY' => self::KEY, 'API_KEYS' => self::NEXT_KEY];
        $afterRotation = ['API_KEY' => null, 'API_KEYS' => self::NEXT_KEY];

        self::assertSame(400, $this->handle('POST', '/v2/convert', ['HTTP_X_API_KEY' => self::KEY], $both)->getStatusCode());
        self::assertSame(400, $this->handle('POST', '/v2/convert', ['HTTP_X_API_KEY' => self::NEXT_KEY], $both)->getStatusCode());
        self::assertSame(401, $this->handle('POST', '/v2/convert', ['HTTP_X_API_KEY' => self::KEY], $afterRotation)->getStatusCode());
        self::assertSame(400, $this->handle('POST', '/v2/convert', ['HTTP_X_API_KEY' => self::NEXT_KEY], $afterRotation)->getStatusCode());
    }

    /**
     * @return array<string, array{array<string, string|null>}>
     */
    public static function provideMissingConfigurations(): array
    {
        return [
            'nothing set' => [['API_KEY' => null, 'API_KEYS' => null]],
            'empty values' => [['API_KEY' => '', 'API_KEYS' => '']],
            'literal null' => [['API_KEY' => 'null', 'API_KEYS' => ' , ']],
        ];
    }

    /**
     * Fail closed: with no key configured, every protected request is
     * refused, whatever it carries, as a server misconfiguration (503), and
     * the misconfiguration is logged.
     *
     * @dataProvider provideMissingConfigurations
     *
     * @param array<string, string|null> $config
     */
    #[DataProvider('provideMissingConfigurations')]
    public function testEveryProtectedRequestIsRefusedWithoutAConfiguredKey(array $config): void
    {
        foreach (self::provideProtectedPaths() as list($method, $path)) {
            foreach ([[], ['HTTP_X_API_KEY' => ''], ['HTTP_AUTHORIZATION' => 'Bearer anything']] as $server) {
                $response = $this->handle($method, $path, $server, $config);

                self::assertSame(503, $response->getStatusCode());
                self::assertSame(ResponseFactory::ERROR_AUTHENTICATION_NOT_CONFIGURED, self::errorCode($response));
            }
        }
        self::assertSame('critical', $this->logger->getRecords()[0]['level'] ?? null);
        self::assertSame(200, $this->handle('GET', '/healthz', [], $config)->getStatusCode(), '/healthz stays public');
    }

    /**
     * /healthz is public: no key needed, and its answer does not change with
     * the credentials or the configuration, so it reveals neither.
     */
    public function testHealthzNeedsNoKeyAndRevealsNothing(): void
    {
        $responses = [
            $this->handle('GET', '/healthz', []),
            $this->handle('GET', '/healthz', ['HTTP_AUTHORIZATION' => 'Bearer wrong']),
            $this->handle('GET', '/healthz', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY]),
            $this->handle('GET', '/healthz', [], ['API_KEY' => null]),
        ];

        foreach ($responses as $response) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('{"status":"ok"}', $response->getContent());
            self::assertNull($response->headers->get('WWW-Authenticate'));
        }
    }

    /**
     * @return array<string, array{array<string, string>, int}>
     */
    public static function provideClients(): array
    {
        $key = ['HTTP_X_API_KEY' => self::KEY];

        return [
            'allowed client' => [$key + ['REMOTE_ADDR' => '198.51.100.7'], 400],
            'allowed IPv6 client' => [$key + ['REMOTE_ADDR' => '2001:db8::7'], 400],
            'other client' => [$key + ['REMOTE_ADDR' => '203.0.113.9'], 403],
            'other client, without a key' => [['REMOTE_ADDR' => '203.0.113.9'], 403],
            'allowed client, without a key' => [['REMOTE_ADDR' => '198.51.100.7'], 401],
            'other client claims an allowed address' => [$key + ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], 403],
            'allowed client behind the trusted proxy' => [$key + ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], 400],
            'other client behind the trusted proxy' => [$key + ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'], 403],
            'other client spoofs behind the trusted proxy' => [$key + ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 203.0.113.9'], 403],
            'the trusted proxy itself' => [$key + ['REMOTE_ADDR' => '10.0.0.5'], 403],
        ];
    }

    /**
     * @dataProvider provideClients
     *
     * @param array<string, string> $server
     */
    #[DataProvider('provideClients')]
    public function testTheAllowlistJudgesTheClientAddress(array $server, int $status): void
    {
        $config = ['IP_ALLOWLIST' => '198.51.100.7, 2001:db8::/32', 'TRUSTED_PROXIES' => '10.0.0.5'];

        $response = $this->handle('POST', '/v2/convert', $server, $config);

        self::assertSame($status, $response->getStatusCode());
        if ($status === 403) {
            self::assertSame(ResponseFactory::ERROR_IP_NOT_ALLOWED, self::errorCode($response));
        }
    }

    public function testTheAllowlistDoesNotApplyToHealthz(): void
    {
        $response = $this->handle('GET', '/healthz', ['REMOTE_ADDR' => '203.0.113.9'], ['IP_ALLOWLIST' => '198.51.100.7']);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testErrorsDoNotEchoTheKey(): void
    {
        $response = $this->handle('POST', '/v2/convert', ['HTTP_X_API_KEY' => 'presented-secret']);

        self::assertStringNotContainsString('presented-secret', (string) $response->getContent());
        self::assertStringNotContainsString('presented-secret', (string) $response->headers);
    }

    /**
     * @param array<string, string>      $server
     * @param array<string, string|null> $config
     */
    private function handle(string $method, string $path, array $server, array $config = []): Response
    {
        $values = $config + ['V1_SCRIPT_NAME' => 'convert-now', 'TEMP_DIR' => $this->directory->path(), 'API_KEY' => self::KEY, 'RATE_LIMIT' => '0'];

        return KernelFactory::create(Config::fromArray($values), $this->logger)
            ->handle(Request::create('https://api.example.test' . $path, $method, [], [], [], $server));
    }

    private static function errorCode(Response $response): string
    {
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['error'] ?? null);
        self::assertIsString($data['error']['code'] ?? null);

        return $data['error']['code'];
    }
}
