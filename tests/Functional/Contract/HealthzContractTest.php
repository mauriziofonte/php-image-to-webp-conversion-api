<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\Contract;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Http\Handler\HealthHandler;
use Mfonte\ImageConversionApi\Http\Kernel;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Mapping\ErrorMapper;
use Mfonte\ImageConversionApi\Observability\JsonLogger;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\ScriptedConverter;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\ScriptedConverterFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The contract of GET /healthz that the proxy's remote mode (PRX-8)
 * relies on: without a key, the fixed {"status":"ok"}; with an accepted
 * key, the capabilities in the library's schema, which
 * Capabilities::fromArray() reads back; a wrong key 401.
 *
 * @covers \Mfonte\ImageConversionApi\Http\Handler\HealthHandler
 * @covers \Mfonte\ImageConversionApi\Http\Kernel
 *
 * @group contract
 */
#[CoversClass(HealthHandler::class)]
#[CoversClass(Kernel::class)]
#[Group('contract')]
final class HealthzContractTest extends TestCase
{
    private const API_KEY = 'healthz-test-key-0123456789';

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
     * Without a key: the fixed document, without building a converter or
     * touching the disk, whatever the configuration.
     */
    public function testWithoutAKeyAnswersOnlyTheStatus(): void
    {
        foreach ([[], ['API_KEY' => null], ['IP_ALLOWLIST' => '192.0.2.1']] as $config) {
            $factory = self::factory();
            $response = $this->send($factory, self::request(), $config);

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('{"status":"ok"}', $response->getContent());
            self::assertSame([], $factory->getCreated());
        }
        self::assertSame(200, $this->send(self::factory(), self::request([], 'http'))->getStatusCode(), 'over plain HTTP too');
        self::assertSame([], $this->directory->entries());
        self::assertSame([], $this->logger->getRecords());
    }

    /**
     * With an accepted key, by either header: the capabilities, which the
     * library reads back into equal capabilities.
     */
    public function testWithAKeyAddsTheCapabilities(): void
    {
        $expected = ScriptedConverter::succeeding('OUT')->getCapabilities();
        foreach ([['HTTP_AUTHORIZATION' => 'Bearer ' . self::API_KEY], ['HTTP_X_API_KEY' => self::API_KEY]] as $server) {
            $factory = self::factory();
            $response = $this->send($factory, self::request($server));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('application/json', $response->headers->get('Content-Type'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $data = json_decode((string) $response->getContent(), true);
            self::assertIsArray($data);
            self::assertSame(['status', 'capabilities'], array_keys($data));
            self::assertSame(HealthHandler::STATUS_OK, $data['status']);
            self::assertIsArray($data['capabilities']);
            self::assertSame($expected->toArray(), Capabilities::fromArray($data['capabilities'])->toArray());
            self::assertCount(1, $factory->getCreated());
        }
        self::assertSame([], $this->directory->entries(), 'the request directory is removed');
    }

    /**
     * A key presented to /healthz goes through the checks of every
     * protected route.
     */
    public function testAPresentedKeyIsChecked(): void
    {
        $factory = self::factory();

        $wrong = $this->send($factory, self::request(['HTTP_AUTHORIZATION' => 'Bearer wrong-key-0123456789abcdef']));
        self::assertSame(401, $wrong->getStatusCode());
        self::assertSame('invalid_api_key', self::errorCode($wrong));

        $outside = $this->send($factory, self::request(['HTTP_X_API_KEY' => self::API_KEY]), ['IP_ALLOWLIST' => '192.0.2.1']);
        self::assertSame(403, $outside->getStatusCode());

        $plain = $this->send($factory, self::request(['HTTP_X_API_KEY' => self::API_KEY], 'http'));
        self::assertSame(400, $plain->getStatusCode());
        self::assertSame('https_required', self::errorCode($plain));

        self::assertSame([], $factory->getCreated());
    }

    /**
     * A key that opens /metrics only (METRICS_KEYS) is no key here.
     */
    public function testAMetricsKeyIsRefused(): void
    {
        $key = 'healthz-scraper-key-0123456789';
        $response = $this->send(self::factory(), self::request(['HTTP_X_API_KEY' => $key]), ['METRICS_KEYS' => $key]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAnswers503WhenTheConverterCannotBeBuilt(): void
    {
        $factory = self::factory()->failToCreate(new IsolationUnavailableException('bwrap is missing at /usr/bin/bwrap.'));

        $response = $this->send($factory, self::request(['HTTP_X_API_KEY' => self::API_KEY]));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ['status' => 'unavailable', 'error' => ['code' => 'isolation_unavailable', 'message' => 'The isolation that conversions require is not available on this server.']],
            json_decode((string) $response->getContent(), true)
        );
        $records = $this->logger->getRecords();
        self::assertCount(1, $records);
        self::assertSame('error', $records[0]['level']);
        self::assertSame('isolation_unavailable', $records[0]['context'][ErrorMapper::LOG_KEY] ?? null);
        self::assertSame('bwrap is missing at /usr/bin/bwrap.', $records[0]['context']['reason'] ?? null);
    }

    public function testAnswers503WhenTheSlotsAreUnusable(): void
    {
        $factory = self::factory()->failToCreate(new RequestException(503, 'concurrency_unavailable', 'No slot.', ['Retry-After' => '5']));

        $response = $this->send($factory, self::request(['HTTP_X_API_KEY' => self::API_KEY]));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertSame(
            ['status' => 'unavailable', 'error' => ['code' => 'concurrency_unavailable', 'message' => 'No slot.']],
            json_decode((string) $response->getContent(), true)
        );
        self::assertSame('concurrency_unavailable', $this->logger->getRecords()[0]['context'][JsonLogger::ERROR_CODE] ?? null);
    }

    private static function factory(): ScriptedConverterFactory
    {
        return new ScriptedConverterFactory(ScriptedConverter::succeeding('OUT'));
    }

    /**
     * @param array<string, string> $server
     */
    private static function request(array $server = [], string $scheme = 'https'): Request
    {
        return Request::create($scheme . '://api.example.test/healthz', 'GET', [], [], [], $server + ['REMOTE_ADDR' => '198.51.100.7']);
    }

    /**
     * @param array<string, string|null> $config
     */
    private function send(ScriptedConverterFactory $factory, Request $request, array $config = []): Response
    {
        $values = $config + ['TEMP_DIR' => $this->directory->path(), 'API_KEY' => self::API_KEY, 'RATE_LIMIT' => '0', 'METRICS_STORE' => 'off'];

        return KernelFactory::create(Config::fromArray($values), $this->logger, $factory)->handle($request);
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
