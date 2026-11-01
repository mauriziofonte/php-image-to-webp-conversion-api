<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional;

use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Http\BuiltinServer;
use PHPUnit\Framework\Attributes\CoversNothing;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * public/index.php served by PHP's built-in web server: the composition root
 * reads the environment, builds the kernel and sends its responses, and
 * answers a generic 503 when the configuration is invalid. The server speaks
 * plain HTTP: the test plays the TLS-terminating proxy at 127.0.0.1, which
 * TRUSTED_PROXIES trusts, and sends X-Forwarded-Proto.
 *
 * @coversNothing
 */
#[CoversNothing]
final class FrontControllerTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var BuiltinServer|null
     */
    private $server;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        if ($this->server !== null) {
            $this->server->stop();
        }
        $this->directory->remove();
        parent::tear_down();
    }

    public function testServesTheRoutesOfTheKernel(): void
    {
        $server = $this->start([
            'V1_SCRIPT_NAME' => 'convert-now',
            'TEMP_DIR' => $this->directory->path('temp'),
            'TRUSTED_PROXIES' => '127.0.0.1',
            'API_KEY' => 'front-controller-key',
        ]);
        $https = ['X-Forwarded-Proto' => 'https', 'X-Api-Key' => 'front-controller-key'];

        $health = $server->request('GET', '/healthz');
        self::assertSame(200, $health['status'], $server->getOutput());
        self::assertSame('{"status":"ok"}', $health['body']);
        self::assertSame('application/json', $health['headers']['content-type'] ?? null);

        $v1 = $server->request('POST', '/v1/convert-now', $https);
        self::assertSame(400, $v1['status']);
        self::assertSame('{"status":false,"version":"1.0.0","message":"No images uploaded."}', $v1['body']);
        self::assertSame('@' . KernelFactory::V1_DEPRECATED_AT, $v1['headers']['deprecation'] ?? null);
        self::assertSame(400, $server->request('POST', '/v2/convert', $https)['status']);
        self::assertSame(404, $server->request('GET', '/missing', $https)['status']);
        self::assertDoesNotMatchRegularExpression('/PHP (?:Fatal|Parse|Warning|Notice|Deprecated)/', $server->getOutput());
    }

    public function testAcceptsABearerKey(): void
    {
        $server = $this->start([
            'TEMP_DIR' => $this->directory->path('temp'),
            'TRUSTED_PROXIES' => '127.0.0.1',
            'API_KEY' => 'first-key-0123456789',
            'API_KEYS' => 'second-key-0123456789,third-key-0123456789',
        ]);
        $https = ['X-Forwarded-Proto' => 'https'];

        self::assertSame(400, $server->request('POST', '/v2/convert', $https + ['Authorization' => 'Bearer third-key-0123456789'])['status'], $server->getOutput());
        self::assertSame(401, $server->request('POST', '/v2/convert', $https + ['Authorization' => 'Bearer fourth-key-0123456789'])['status']);
        self::assertSame(200, $server->request('GET', '/metrics', $https + ['X-API-Key' => 'first-key-0123456789'])['status']);
    }

    /**
     * Fail closed (finding C-4): without API_KEY and API_KEYS, every
     * protected request is refused with 503, while /healthz still answers.
     */
    public function testRefusesEveryProtectedRequestWithoutAConfiguredKey(): void
    {
        $server = $this->start(['TEMP_DIR' => $this->directory->path('temp'), 'TRUSTED_PROXIES' => '127.0.0.1', 'API_KEY' => '']);
        $https = ['X-Forwarded-Proto' => 'https', 'X-API-Key' => ''];

        foreach ([['POST', '/v2/convert'], ['GET', '/metrics'], ['GET', '/missing']] as list($method, $path)) {
            $response = $server->request($method, $path, $https);
            self::assertSame(503, $response['status'], $server->getOutput());
            self::assertStringContainsString('"code":"authentication_not_configured"', $response['body']);
        }
        self::assertSame(200, $server->request('GET', '/healthz')['status']);
        self::assertStringContainsString('No API key is configured', $server->getOutput());
    }

    public function testRefusesPlainHttpUnlessATrustedProxySaysOtherwise(): void
    {
        $server = $this->start(['TEMP_DIR' => $this->directory->path('temp'), 'TRUSTED_PROXIES' => '192.0.2.1', 'API_KEY' => 'first-key-0123456789']);

        $plain = $server->request('POST', '/v2/convert');
        $untrusted = $server->request('POST', '/v2/convert', ['X-Forwarded-Proto' => 'https']);

        self::assertSame(400, $plain['status'], $server->getOutput());
        self::assertSame('{"error":{"code":"https_required","message":"This endpoint must be called over HTTPS."}}', $plain['body']);
        self::assertSame(400, $untrusted['status'], 'X-Forwarded-Proto from a peer that is not a trusted proxy');
        self::assertSame(200, $server->request('GET', '/healthz')['status'], '/healthz answers over HTTP');
    }

    public function testAnswers503AndLogsTheErrorsWhenTheConfigurationIsInvalid(): void
    {
        $server = $this->start(['V1_SCRIPT_NAME' => 'not/one/segment', 'TEMP_DIR' => 'relative']);

        $response = $server->request('GET', '/healthz');

        self::assertSame(503, $response['status'], $server->getOutput());
        self::assertSame('{"error":{"code":"invalid_configuration","message":"The service is not configured correctly."}}', $response['body']);
        self::assertStringNotContainsString('V1_SCRIPT_NAME', $response['body']);
        self::assertStringContainsString('"message":"Invalid configuration"', $server->getOutput());
        self::assertStringContainsString('V1_SCRIPT_NAME must be one URL path segment', $server->getOutput());
        self::assertStringContainsString('TEMP_DIR must be an absolute path', $server->getOutput());
    }

    /**
     * A short key makes the configuration invalid: every request, the one
     * that presents that very key included, gets the generic 503, and the
     * log names the problem without the key.
     */
    public function testRefusesAShortKey(): void
    {
        $server = $this->start(['TEMP_DIR' => $this->directory->path('temp'), 'TRUSTED_PROXIES' => '127.0.0.1', 'API_KEY' => 'short-key-xyz']);

        $response = $server->request('POST', '/v2/convert', ['X-Forwarded-Proto' => 'https', 'X-API-Key' => 'short-key-xyz']);

        self::assertSame(503, $response['status'], $server->getOutput());
        self::assertStringContainsString('"code":"invalid_configuration"', $response['body']);
        self::assertSame(503, $server->request('GET', '/healthz')['status']);
        self::assertStringContainsString('API_KEY must be at least 16 bytes long', $server->getOutput());
        self::assertStringNotContainsString('short-key-xyz', $server->getOutput());
    }

    public function testRefusesATemporaryDirectoryInsideTheWebRoot(): void
    {
        $server = $this->start(['TEMP_DIR' => dirname(__DIR__, 2) . '/public/temp']);

        self::assertSame(503, $server->request('GET', '/healthz')['status'], $server->getOutput());
        self::assertStringContainsString('TEMP_DIR must be outside the web root', $server->getOutput());
    }

    /**
     * @param array<string, string> $environment the configuration
     */
    private function start(array $environment): BuiltinServer
    {
        $public = dirname(__DIR__, 2) . '/public';
        $path = getenv('PATH');
        $this->server = BuiltinServer::start(
            $public,
            $public . '/index.php',
            $environment + ['PATH' => is_string($path) ? $path : '/usr/bin:/bin'],
            $this->directory->path('server.log')
        );

        return $this->server;
    }
}
