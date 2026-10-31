<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional;

use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Http\BuiltinServer;
use PHPUnit\Framework\Attributes\CoversNothing;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * public/index.php served by PHP's built-in web server: the composition root
 * reads the environment, builds the kernel and sends its responses, and
 * answers a generic 503 when the configuration is invalid.
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
        $server = $this->start(['V1_SCRIPT_NAME' => 'convert-now', 'TEMP_DIR' => $this->directory->path('temp')]);

        $health = $server->request('GET', '/healthz');
        self::assertSame(200, $health['status'], $server->getOutput());
        self::assertSame('{"status":"ok"}', $health['body']);
        self::assertSame('application/json', $health['headers']['content-type'] ?? null);

        self::assertSame(501, $server->request('POST', '/v1/convert-now')['status']);
        self::assertSame(501, $server->request('POST', '/v2/convert')['status']);
        self::assertSame(404, $server->request('GET', '/missing')['status']);
        self::assertDoesNotMatchRegularExpression('/PHP (?:Fatal|Parse|Warning|Notice|Deprecated)/', $server->getOutput());
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
