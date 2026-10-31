<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\Http;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Http\Kernel;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Http\TrustedProxies;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every path but the public /healthz must reach the service over HTTPS;
 * behind a load balancer that terminates TLS, X-Forwarded-Proto counts only
 * from TRUSTED_PROXIES (finding C-10: 1.x refused every request behind one).
 *
 * @covers \Mfonte\ImageConversionApi\Http\Kernel
 * @covers \Mfonte\ImageConversionApi\Http\TrustedProxies
 */
#[CoversClass(Kernel::class)]
#[CoversClass(TrustedProxies::class)]
final class HttpsEnforcementTest extends TestCase
{
    private const LOAD_BALANCER = '10.0.0.5';

    private const API_KEY = 'https-test-key-0123456789';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideProtectedPaths(): array
    {
        return [
            'v2' => ['POST', '/v2/convert'],
            'v1' => ['POST', '/v1/convert-now'],
            'metrics' => ['GET', '/metrics'],
            'unknown path, not revealed as 404' => ['GET', '/missing'],
            'wrong method, not revealed as 405' => ['GET', '/v2/convert'],
        ];
    }

    /**
     * @dataProvider provideProtectedPaths
     */
    #[DataProvider('provideProtectedPaths')]
    public function testPlainHttpIsRefused(string $method, string $path): void
    {
        $response = $this->handle($method, 'http://api.example.test' . $path);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('{"error":{"code":"https_required","message":"This endpoint must be called over HTTPS."}}', $response->getContent());
    }

    public function testHealthzAnswersOverPlainHttp(): void
    {
        self::assertSame(200, $this->handle('GET', 'http://api.example.test/healthz')->getStatusCode());
    }

    public function testHttpsIsAccepted(): void
    {
        self::assertSame(501, $this->handle('POST', 'https://api.example.test/v2/convert')->getStatusCode());
    }

    public function testRequireHttpsCanBeTurnedOff(): void
    {
        $response = $this->handle('POST', 'http://api.example.test/v2/convert', [], ['REQUIRE_HTTPS' => 'false']);

        self::assertSame(501, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function provideForwardedRequests(): array
    {
        return [
            'trusted load balancer, https' => [self::LOAD_BALANCER, 'https', 501],
            'trusted load balancer, http' => [self::LOAD_BALANCER, 'http', 400],
            'trusted load balancer, http appended' => [self::LOAD_BALANCER, 'https, http', 400],
            'untrusted peer, https' => ['192.0.2.1', 'https', 400],
            'trusted proxy in a CIDR block' => ['10.255.0.1', 'https', 501],
            'trusted IPv6 proxy' => ['2001:db8::10', 'https', 501],
        ];
    }

    /**
     * @dataProvider provideForwardedRequests
     */
    #[DataProvider('provideForwardedRequests')]
    public function testXForwardedProtoCountsOnlyFromTrustedProxies(string $peer, string $protocol, int $status): void
    {
        $response = $this->handle(
            'POST',
            'http://api.example.test/v2/convert',
            ['REMOTE_ADDR' => $peer, 'HTTP_X_FORWARDED_PROTO' => $protocol],
            ['TRUSTED_PROXIES' => '10.0.0.0/8,2001:db8::/32']
        );

        self::assertSame($status, $response->getStatusCode());
    }

    public function testNoProxyIsTrustedByDefault(): void
    {
        $response = $this->handle('POST', 'http://api.example.test/v2/convert', ['REMOTE_ADDR' => self::LOAD_BALANCER, 'HTTP_X_FORWARDED_PROTO' => 'https']);

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * Handles a request that carries an accepted API key.
     *
     * @param array<string, string>      $server
     * @param array<string, string|null> $config
     */
    private function handle(string $method, string $uri, array $server = [], array $config = []): Response
    {
        $values = $config + ['V1_SCRIPT_NAME' => 'convert-now', 'TEMP_DIR' => $this->directory->path(), 'API_KEY' => self::API_KEY];
        $server += ['HTTP_X_API_KEY' => self::API_KEY];

        return KernelFactory::create(Config::fromArray($values), new NullLogger())
            ->handle(Request::create($uri, $method, [], [], [], $server));
    }
}
