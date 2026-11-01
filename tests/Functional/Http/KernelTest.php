<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\Http;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Http\Handler\HealthHandler;
use Mfonte\ImageConversionApi\Http\Handler\MetricsHandler;
use Mfonte\ImageConversionApi\Http\Handler\NotImplementedHandler;
use Mfonte\ImageConversionApi\Http\Kernel;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Http\RequestDirectories;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The kernel as public/index.php builds it (KernelFactory), driven with
 * Request objects: one test per route, unknown routes, wrong methods.
 *
 * @covers \Mfonte\ImageConversionApi\Http\Kernel
 * @covers \Mfonte\ImageConversionApi\Http\KernelFactory
 * @covers \Mfonte\ImageConversionApi\Http\Handler\HealthHandler
 * @covers \Mfonte\ImageConversionApi\Http\Handler\MetricsHandler
 * @covers \Mfonte\ImageConversionApi\Http\Handler\NotImplementedHandler
 */
#[CoversClass(Kernel::class)]
#[CoversClass(KernelFactory::class)]
#[CoversClass(HealthHandler::class)]
#[CoversClass(MetricsHandler::class)]
#[CoversClass(NotImplementedHandler::class)]
final class KernelTest extends TestCase
{
    private const SCRIPT_NAME = 'convert-now';

    private const API_KEY = 'kernel-test-key-0123456789';

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

    public function testHealthzAnswersAFixedStatus(): void
    {
        $response = $this->handle('GET', '/healthz');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * /healthz is public: nothing of the configuration may appear in it.
     */
    public function testHealthzRevealsNothingAboutTheConfiguration(): void
    {
        $content = (string) $this->handle('GET', '/healthz')->getContent();
        $headers = (string) $this->handle('GET', '/healthz')->headers;

        foreach ([self::SCRIPT_NAME, $this->directory->path()] as $secret) {
            self::assertStringNotContainsString($secret, $content);
            self::assertStringNotContainsString($secret, $headers);
        }
    }

    public function testHeadAnswersTheHeadersWithoutTheBody(): void
    {
        $response = $this->handle('HEAD', '/healthz');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
    }

    public function testMetricsAnswersThePrometheusExposition(): void
    {
        $response = $this->handle('GET', '/metrics');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ResponseFactory::PROMETHEUS_CONTENT_TYPE, $response->headers->get('Content-Type'));
        self::assertStringContainsString("# TYPE image_conversion_api_up gauge\nimage_conversion_api_up 1\n", (string) $response->getContent());
    }

    public function testV2ConvertNeedsAnImage(): void
    {
        $response = $this->handle('POST', '/v2/convert');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ResponseFactory::ERROR_MISSING_IMAGE, self::errorCode($response));
    }

    public function testV1IsServedAtItsScriptName(): void
    {
        $response = $this->handle('POST', '/v1/' . self::SCRIPT_NAME);

        self::assertSame(501, $response->getStatusCode());
        self::assertSame(ResponseFactory::ERROR_NOT_IMPLEMENTED, self::errorCode($response));
        self::assertSame(404, $this->handle('POST', '/v1/another-name')->getStatusCode());
    }

    public function testV1DoesNotExistWithoutAScriptName(): void
    {
        $response = $this->handle('POST', '/v1/' . self::SCRIPT_NAME, ['V1_SCRIPT_NAME' => null]);

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideUnknownRoutes(): array
    {
        return [
            'root' => ['GET', '/'],
            'v2 prefix' => ['POST', '/v2'],
            'trailing slash' => ['POST', '/v2/convert/'],
            'upper case' => ['GET', '/HEALTHZ'],
            'v1 without a script name' => ['POST', '/v1'],
            'v1 with an empty script name' => ['POST', '/v1/'],
            'the 1.x script name without /v1' => ['POST', '/' . self::SCRIPT_NAME],
            'the 1.x front controller' => ['POST', '/process.php'],
            'a dotfile' => ['GET', '/.env'],
            'percent-encoded path' => ['GET', '/health%7A'],
        ];
    }

    /**
     * @dataProvider provideUnknownRoutes
     */
    #[DataProvider('provideUnknownRoutes')]
    public function testUnknownRoutesAnswer404(string $method, string $path): void
    {
        $response = $this->handle($method, $path);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(ResponseFactory::ERROR_NOT_FOUND, self::errorCode($response));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function provideWrongMethods(): array
    {
        return [
            'GET /v2/convert' => ['GET', '/v2/convert', 'POST'],
            'OPTIONS /v2/convert' => ['OPTIONS', '/v2/convert', 'POST'],
            'PUT /v1' => ['PUT', '/v1/' . self::SCRIPT_NAME, 'POST'],
            'POST /healthz' => ['POST', '/healthz', 'GET, HEAD'],
            'DELETE /metrics' => ['DELETE', '/metrics', 'GET, HEAD'],
        ];
    }

    /**
     * @dataProvider provideWrongMethods
     */
    #[DataProvider('provideWrongMethods')]
    public function testWrongMethodsAnswer405WithAllow(string $method, string $path, string $allow): void
    {
        $response = $this->handle($method, $path);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame($allow, $response->headers->get('Allow'));
        self::assertSame(ResponseFactory::ERROR_METHOD_NOT_ALLOWED, self::errorCode($response));
    }

    /**
     * The method is the one on the request line: an override header
     * neither changes the route's answer nor throws (Symfony's getMethod()
     * throws on a malformed override).
     */
    public function testMethodOverridesAreIgnored(): void
    {
        $overridden = $this->handle('POST', '/healthz', [], ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET']);
        $malformed = $this->handle('POST', '/v2/convert', [], ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'NOT A METHOD']);

        self::assertSame(405, $overridden->getStatusCode());
        self::assertSame(ResponseFactory::ERROR_MISSING_IMAGE, self::errorCode($malformed));
    }

    public function testRoutesThatWriteNoFileTouchNoDisk(): void
    {
        $this->handle('GET', '/healthz');
        $this->handle('GET', '/metrics');
        $this->handle('GET', '/missing');

        self::assertSame([], $this->directory->entries());
        self::assertSame([], $this->logger->getRecords());
    }

    /**
     * What a killed worker left (a request directory with a working
     * directory of the library in it) goes with the next request that
     * creates a directory.
     */
    public function testTheNextConversionRemovesWhatADeadWorkerLeft(): void
    {
        $left = $this->directory->path(RequestDirectories::PREFIX . 'killed');
        mkdir($left . '/sic-0123456789abcdef', 0700, true);
        touch($left, time() - KernelFactory::MIN_STALE_SECONDS - 60);

        $this->handle('POST', '/v2/convert', [], ['CONTENT_TYPE' => 'image/png']);

        self::assertSame([], $this->directory->entries());
    }

    public function testARefusedConversionLeavesNoFile(): void
    {
        $this->handle('POST', '/v2/convert');

        self::assertSame([], $this->directory->entries());
    }

    /**
     * Handles an authenticated HTTPS request.
     *
     * @param array<string, string|null> $config overrides of the test configuration
     * @param array<string, string>      $server
     */
    private function handle(string $method, string $path, array $config = [], array $server = []): Response
    {
        $values = $config + ['V1_SCRIPT_NAME' => self::SCRIPT_NAME, 'TEMP_DIR' => $this->directory->path(), 'API_KEY' => self::API_KEY, 'RATE_LIMIT' => '0'];
        $kernel = KernelFactory::create(Config::fromArray($values), $this->logger);
        $server += ['HTTP_AUTHORIZATION' => 'Bearer ' . self::API_KEY];

        return $kernel->handle(Request::create('https://api.example.test' . $path, $method, [], [], [], $server));
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
