<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\Limits;

use Mfonte\ImageConversionApi\Auth\ApiKeyAuthenticator;
use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Conversion\LibraryConverterFactory;
use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Exception\RuntimeException;
use Mfonte\ImageConversionApi\Http\Handler\V1ConvertHandler;
use Mfonte\ImageConversionApi\Http\Handler\V2ConvertHandler;
use Mfonte\ImageConversionApi\Http\InputReader;
use Mfonte\ImageConversionApi\Http\Kernel;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Http\RequestDirectories;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Limits\ApcuRateLimitStore;
use Mfonte\ImageConversionApi\Limits\ConversionSlots;
use Mfonte\ImageConversionApi\Limits\GuardedSemaphore;
use Mfonte\ImageConversionApi\Limits\LimitingHandler;
use Mfonte\ImageConversionApi\Limits\RateLimiter;
use Mfonte\ImageConversionApi\Limits\RequestLimits;
use Mfonte\ImageConversionApi\Mapping\ErrorMapper;
use Mfonte\ImageConversionApi\Mapping\V2RequestMapper;
use Mfonte\ImageConversionApi\Observability\JsonLogger;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\ScriptedConverter;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\ScriptedConverterFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Limits\MemoryRateLimitStore;
use Mfonte\ImageConversionApi\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException as LibraryInvalidArgumentException;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use Mfonte\SecureImageConverter\Validation\Limits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The limits of the conversion endpoints, through the kernel built by
 * KernelFactory: the per-key rate limit (429 with Retry-After), the body
 * size (413 before the body is read) and the host's conversion slots (503
 * with Retry-After, from the library's semaphore).
 *
 * @covers \Mfonte\ImageConversionApi\Limits\LimitingHandler
 * @covers \Mfonte\ImageConversionApi\Limits\RateLimiter
 * @covers \Mfonte\ImageConversionApi\Limits\FileRateLimitStore
 * @covers \Mfonte\ImageConversionApi\Limits\RequestLimits
 * @covers \Mfonte\ImageConversionApi\Limits\ConversionSlots
 * @covers \Mfonte\ImageConversionApi\Limits\GuardedSemaphore
 * @covers \Mfonte\ImageConversionApi\Http\Handler\V2ConvertHandler
 * @covers \Mfonte\ImageConversionApi\Http\Handler\V1ConvertHandler
 * @covers \Mfonte\ImageConversionApi\Conversion\LibraryConverterFactory
 * @covers \Mfonte\ImageConversionApi\Http\KernelFactory
 * @covers \Mfonte\ImageConversionApi\Http\Kernel
 */
#[CoversClass(LimitingHandler::class)]
#[CoversClass(ConversionSlots::class)]
#[CoversClass(GuardedSemaphore::class)]
#[CoversClass(V2ConvertHandler::class)]
#[CoversClass(V1ConvertHandler::class)]
#[CoversClass(LibraryConverterFactory::class)]
#[CoversClass(KernelFactory::class)]
#[CoversClass(Kernel::class)]
final class LimitsTest extends TestCase
{
    private const KEY = 'limits-test-key-0123456789';

    private const OTHER_KEY = 'limits-other-key-0123456789';

    /**
     * A valid 1x1 PNG, which the library's inspector accepts without a decoder.
     */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

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

    public function testRefusesAKeyAboveItsRateLimit(): void
    {
        $config = ['RATE_LIMIT' => '60', 'RATE_LIMIT_BURST' => '3', 'RATE_LIMIT_STORE' => 'file'];
        $statuses = [];
        for ($request = 0; $request < 5; ++$request) {
            $statuses[] = $this->send($this->raw('IMAGE', self::KEY), $config)->getStatusCode();
        }
        $refused = $this->send($this->raw('IMAGE', self::KEY), $config);

        self::assertSame([200, 200, 200, 429, 429], $statuses);
        self::assertSame(429, $refused->getStatusCode());
        self::assertSame('1', $refused->headers->get('Retry-After'));
        self::assertSame(['error' => ['code' => 'rate_limited', 'message' => 'This API key sent too many requests: retry later.']], json_decode((string) $refused->getContent(), true));
        self::assertSame(200, $this->send($this->raw('IMAGE', self::OTHER_KEY), $config)->getStatusCode(), 'every key has its own bucket');
        self::assertSame(401, $this->send($this->raw('IMAGE', 'not-a-configured-key-012345'), $config)->getStatusCode(), 'authentication comes first');
        self::assertDirectoryExists($this->directory->path('temp/' . Config::DEFAULT_LOCK_DIR_NAME), 'the default LOCK_DIR is in TEMP_DIR');
    }

    /**
     * The bucket file of the key is a directory, so the file store cannot
     * update it: the request is refused, as a server error, and logged as one.
     */
    public function testAnswers503WhenTheRateLimitStoreFails(): void
    {
        $locks = $this->directory->path('locks');
        $config = ['RATE_LIMIT' => '60', 'RATE_LIMIT_BURST' => '3', 'RATE_LIMIT_STORE' => 'file', 'LOCK_DIR' => $locks];
        $request = $this->raw('IMAGE', self::KEY);
        $client = (new ApiKeyAuthenticator([self::KEY]))->clientId($request);
        self::assertNotNull($client);
        mkdir($locks . '/ratelimit.' . $client . '.state', 0700, true);
        chmod($locks, 0700);
        $converter = ScriptedConverter::succeeding('WEBP');

        $response = $this->send($request, $config, $converter);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => ['code' => 'rate_limit_unavailable', 'message' => 'The rate limit cannot be checked: retry later.']], json_decode((string) $response->getContent(), true));
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame([], $converter->getCalls());
        $errors = array_values(array_filter($this->logger->getRecords(), static function (array $record): bool {
            return $record['level'] === 'error';
        }));
        self::assertCount(1, $errors);
        self::assertSame(ResponseFactory::ERROR_RATE_LIMIT_UNAVAILABLE, $errors[0]['context']['error_code'] ?? null);
    }

    /**
     * A LOCK_DIR that other users may write to is refused by the library's
     * LockDirectory: the rate limit cannot be checked, so the request is
     * refused with 503, and the log keeps the reason and both exceptions.
     */
    public function testAnswers503WhenTheLockDirectoryIsNotPrivate(): void
    {
        $locks = $this->directory->path('shared-locks');
        mkdir($locks);
        chmod($locks, 0777);
        $config = ['RATE_LIMIT' => '60', 'RATE_LIMIT_BURST' => '3', 'RATE_LIMIT_STORE' => 'file', 'LOCK_DIR' => $locks];
        $converter = ScriptedConverter::succeeding('WEBP');

        $response = $this->send($this->raw('IMAGE', self::KEY), $config, $converter);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => ['code' => 'rate_limit_unavailable', 'message' => 'The rate limit cannot be checked: retry later.']], json_decode((string) $response->getContent(), true));
        self::assertSame([], $converter->getCalls());
        $errors = array_values(array_filter($this->logger->getRecords(), static function (array $record): bool {
            return $record['level'] === 'error';
        }));
        self::assertCount(1, $errors);
        $context = $errors[0]['context'];
        self::assertIsString($context['reason'] ?? null);
        self::assertStringStartsWith('The rate limit store is not usable: ', $context['reason']);
        $exception = $context['exception'] ?? null;
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertInstanceOf(LibraryInvalidArgumentException::class, $exception->getPrevious());

        // The production logger keeps the class and the message of both exceptions.
        $lines = [];
        (new JsonLogger(static function (string $line) use (&$lines): bool {
            $lines[] = $line;

            return true;
        }))->log('error', $errors[0]['message'], $context);
        self::assertCount(1, $lines);
        self::assertStringContainsString(json_encode(RuntimeException::class, JSON_UNESCAPED_SLASHES) ?: '-', $lines[0]);
        self::assertStringContainsString(json_encode(LibraryInvalidArgumentException::class, JSON_UNESCAPED_SLASHES) ?: '-', $lines[0]);
        self::assertStringContainsString('The rate limit store is not usable', $lines[0]);
    }

    /**
     * Finding F1 of the W4 review: Config refuses a LOCK_DIR that the sweep
     * of stale request directories would remove, but a symbolic link made
     * after the configuration was read can still put it there; the kernel
     * tells the sweep to keep LOCK_DIR, so its lock files survive.
     */
    public function testTheSweepKeepsTheLockDirectory(): void
    {
        $stale = $this->directory->path('temp/' . RequestDirectories::PREFIX . 'stale');
        $config = ['RATE_LIMIT' => '0', 'LOCK_DIR' => $this->directory->path('alias/locks')];
        $kernel = KernelFactory::create(Config::fromArray($config + ['TEMP_DIR' => $this->directory->path('temp'), 'API_KEY' => self::KEY]), $this->logger, new ScriptedConverterFactory(ScriptedConverter::succeeding('WEBP')));
        mkdir($stale . '/locks', 0700, true);
        touch($stale . '/locks/semaphore.0.lock');
        touch($stale, time() - 7200);
        symlink($stale, $this->directory->path('alias'));

        self::assertSame(200, $kernel->handle($this->raw('IMAGE', self::KEY))->getStatusCode());
        self::assertFileExists($stale . '/locks/semaphore.0.lock');
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function provideRateLimitSettings(): array
    {
        return [
            'rate limiting off' => [['RATE_LIMIT' => '0'], 'concurrency_unavailable', 'The concurrency limit cannot be checked: retry later.'],
            'file store, which reaches LOCK_DIR first' => [['RATE_LIMIT' => '60', 'RATE_LIMIT_STORE' => 'file'], 'rate_limit_unavailable', 'The rate limit cannot be checked: retry later.'],
        ];
    }

    /**
     * Finding F2 of the W4 review: an unusable LOCK_DIR answers 503 with
     * Retry-After whichever component reaches it first, never 500.
     *
     * @dataProvider provideRateLimitSettings
     *
     * @param array<string, string> $config
     */
    #[DataProvider('provideRateLimitSettings')]
    public function testAnUnusableLockDirectoryIs503OnEveryPath(array $config, string $code, string $message): void
    {
        $response = $this->send($this->raw((string) base64_decode(self::PNG, true), self::KEY), $config + ['LOCK_DIR' => $this->sharedLockDirectory()], null, true);

        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['error' => ['code' => $code, 'message' => $message]], json_decode((string) $response->getContent(), true));
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame([], is_dir($this->directory->path('temp')) ? $this->directory->entries('temp') : [], 'no request directory is left');
    }

    /**
     * With the APCu store, the rate limit does not touch LOCK_DIR: the
     * conversion slots find it unusable.
     */
    public function testAnUnusableLockDirectoryIs503WithTheApcuStore(): void
    {
        if (!ApcuRateLimitStore::isAvailable()) {
            self::markTestSkipped('APCu is not enabled (apc.enabled, apc.enable_cli); the fake store of the next test covers this path.');
        }
        $config = ['RATE_LIMIT' => '1000', 'RATE_LIMIT_BURST' => '1000', 'RATE_LIMIT_STORE' => 'apcu', 'LOCK_DIR' => $this->sharedLockDirectory()];

        $response = $this->send($this->raw((string) base64_decode(self::PNG, true), self::KEY), $config, null, true);

        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('"code":"concurrency_unavailable"', (string) $response->getContent());
        self::assertSame('1', $response->headers->get('Retry-After'));
    }

    /**
     * The APCu path with a store that, as APCu, keeps its buckets away from
     * LOCK_DIR; the error log keeps the cause behind the fixed sentence.
     */
    public function testAnUnusableLockDirectoryIs503WithAStoreOutsideIt(): void
    {
        $responses = new ResponseFactory();
        $converters = new LibraryConverterFactory(
            new SecureConverterBuilder(),
            new Limits(),
            (new ConverterOptions())->withSlotWaitSeconds(0.0),
            new ConversionSlots($this->sharedLockDirectory(), 2)
        );
        $handler = new LimitingHandler(
            new V2ConvertHandler($converters, new InputReader(), new V2RequestMapper(), new ErrorMapper(), $responses, $this->logger, 30),
            new RequestLimits(1000000),
            new RateLimiter(new MemoryRateLimitStore(), 60, 5),
            $responses,
            $this->logger
        );
        $request = $this->raw((string) base64_decode(self::PNG, true), self::KEY);
        $request->attributes->set(Kernel::CLIENT_ATTRIBUTE, 'client');
        $directories = new RequestDirectories($this->directory->path('temp'));

        $response = $handler->handle($request, $directories->forRequest());
        $directories->removeAll();

        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['error' => ['code' => 'concurrency_unavailable', 'message' => 'The concurrency limit cannot be checked: retry later.']], json_decode((string) $response->getContent(), true));
        self::assertSame('1', $response->headers->get('Retry-After'));
        $errors = $this->errorRecords();
        self::assertCount(1, $errors);
        self::assertSame('concurrency_unavailable', $errors[0]['context'][V2ConvertHandler::ERROR_LOG_KEY] ?? null);
        $exception = $errors[0]['context']['exception'] ?? null;
        self::assertInstanceOf(RequestException::class, $exception);
        self::assertInstanceOf(LibraryInvalidArgumentException::class, $exception->getPrevious());

        // The production logger keeps the class and the message of both exceptions.
        $lines = [];
        (new JsonLogger(static function (string $line) use (&$lines): bool {
            $lines[] = $line;

            return true;
        }))->log('error', $errors[0]['message'], $errors[0]['context']);
        self::assertCount(1, $lines);
        self::assertStringContainsString(json_encode(LibraryInvalidArgumentException::class, JSON_UNESCAPED_SLASHES) ?: '-', $lines[0]);
        self::assertStringContainsString('lock directory', $lines[0]);
    }

    /**
     * A slot file that cannot be opened fails when the library takes the
     * slot, inside convert(): the same 503.
     */
    public function testASlotFileThatCannotBeOpenedIs503(): void
    {
        $locks = $this->directory->path('locks');
        mkdir($locks . '/semaphore.' . ConversionSlots::NAME . '.0.lock', 0700, true);
        $config = ['RATE_LIMIT' => '0', 'LOCK_DIR' => $locks, 'CONCURRENCY_SLOTS' => '1', 'CONCURRENCY_WAIT' => '0'];

        $response = $this->send($this->raw((string) base64_decode(self::PNG, true), self::KEY), $config, null, true);

        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('"code":"concurrency_unavailable"', (string) $response->getContent());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame([], $this->directory->entries('temp'), 'neither the request directory nor a working directory is left');
    }

    /**
     * The 1.x endpoint refuses the whole batch with the same 503, in its own
     * document shape.
     */
    public function testAnUnusableLockDirectoryIs503OnV1(): void
    {
        $png = $this->directory->path('photo.png');
        file_put_contents($png, (string) base64_decode(self::PNG, true));
        $config = ['RATE_LIMIT' => '0', 'LOCK_DIR' => $this->sharedLockDirectory(), 'V1_SCRIPT_NAME' => 'convert-now'];
        $request = Request::create('https://api.example.test/v1/convert-now', 'POST', [], [], ['images' => [
            new UploadedFile($png, 'photo.png', 'image/png', UPLOAD_ERR_OK, true),
        ]], $this->server(self::KEY, ['CONTENT_TYPE' => 'multipart/form-data; boundary=----v1']));

        $response = $this->send($request, $config, null, true);
        ob_start();
        $response->sendContent();
        $document = json_decode((string) ob_get_clean(), true);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertIsArray($document);
        self::assertFalse($document['status'] ?? null);
        self::assertSame('The concurrency limit cannot be checked: retry later.', $document['message'] ?? null);
        $errors = $this->errorRecords();
        self::assertCount(1, $errors);
        self::assertSame('concurrency_unavailable', $errors[0]['context'][V2ConvertHandler::ERROR_LOG_KEY] ?? null);
        self::assertInstanceOf(RequestException::class, $errors[0]['context']['exception'] ?? null);
    }

    public function testRateLimitingCanBeTurnedOff(): void
    {
        for ($request = 0; $request < 20; ++$request) {
            self::assertSame(200, $this->send($this->raw('IMAGE', self::KEY), ['RATE_LIMIT' => '0', 'RATE_LIMIT_BURST' => '1', 'METRICS_STORE' => 'off'])->getStatusCode());
        }
        self::assertSame([], $this->directory->entries('temp'));
    }

    public function testRefusesADeclaredBodyAboveTheLimitBeforeReadingIt(): void
    {
        $converter = ScriptedConverter::succeeding('WEBP');
        $body = fopen('php://temp', 'w+b');
        self::assertIsResource($body);
        fwrite($body, 'IMAGE');
        rewind($body);
        $request = Request::create('https://api.example.test/v2/convert', 'POST', [], [], [], $this->server(self::KEY, ['CONTENT_TYPE' => 'image/png']), $body);
        $request->headers->set('Content-Length', '1001');

        $response = $this->send($request, ['MAX_BODY_BYTES' => '1000'], $converter);

        self::assertSame(413, $response->getStatusCode());
        self::assertStringContainsString('"code":"body_too_large"', (string) $response->getContent());
        self::assertSame(0, ftell($body), 'not one byte of the body was read');
        self::assertSame([], $converter->getCalls());
    }

    public function testAMultipartBodyAbovePostMaxSizeIsNotAMissingImage(): void
    {
        $request = Request::create('https://api.example.test/v2/convert', 'POST', [], [], [], $this->server(self::KEY, ['CONTENT_TYPE' => 'multipart/form-data; boundary=x']));
        $postMaxSize = (string) ini_get('post_max_size');
        $limit = RequestLimits::parseIniBytes($postMaxSize);
        if ($limit === PHP_INT_MAX) {
            self::markTestSkipped('post_max_size is unlimited.');
        }
        $request->headers->set('Content-Length', (string) ($limit + 1));

        $response = $this->send($request, ['MAX_BODY_BYTES' => (string) ($limit + 100)]);

        self::assertSame(413, $response->getStatusCode());
        self::assertStringContainsString('"code":"body_too_large"', (string) $response->getContent());
    }

    /**
     * The real library converter, with every slot of the host taken by
     * another process (here, this test): the request waits CONCURRENCY_WAIT
     * and is told when to retry.
     */
    public function testAnswers503WithRetryAfterWhenEverySlotIsTaken(): void
    {
        $locks = $this->directory->path('locks');
        $held = (new ConversionSlots($locks, 1))->semaphore()->acquire(0.0);
        $config = ['LOCK_DIR' => $locks, 'CONCURRENCY_SLOTS' => '1', 'CONCURRENCY_WAIT' => '0'];

        $started = microtime(true);
        $response = $this->send($this->raw((string) base64_decode(self::PNG, true), self::KEY), $config, null, true);
        $elapsed = microtime(true) - $started;
        $held->release();

        self::assertSame(503, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['error' => ['code' => 'concurrency_limit', 'message' => 'Too many conversions are running: retry later.']], json_decode((string) $response->getContent(), true));
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertLessThan(5.0, $elapsed);
        self::assertSame([], $this->directory->entries('temp'), 'the request directory is removed');
    }

    /**
     * A LOCK_DIR that other users may write to, which the library's
     * LockDirectory refuses.
     */
    private function sharedLockDirectory(): string
    {
        $locks = $this->directory->path('shared-locks');
        mkdir($locks);
        chmod($locks, 0777);

        return $locks;
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    private function errorRecords(): array
    {
        return array_values(array_filter($this->logger->getRecords(), static function (array $record): bool {
            return $record['level'] === 'error';
        }));
    }

    /**
     * Handles the request with a scripted converter, or with the library's
     * when $converter is null and $library is true.
     *
     * @param array<string, string> $config
     */
    private function send(Request $request, array $config, ?ScriptedConverter $converter = null, bool $library = false): Response
    {
        $values = $config + ['TEMP_DIR' => $this->directory->path('temp'), 'API_KEY' => self::KEY, 'API_KEYS' => self::OTHER_KEY];
        $factory = $library ? null : new ScriptedConverterFactory($converter ?? ScriptedConverter::succeeding('WEBP'));

        return KernelFactory::create(Config::fromArray($values), $this->logger, $factory)->handle($request);
    }

    private function raw(string $body, string $key): Request
    {
        return Request::create('https://api.example.test/v2/convert', 'POST', [], [], [], $this->server($key, ['CONTENT_TYPE' => 'image/png']), $body);
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, string>
     */
    private function server(string $key, array $server): array
    {
        return $server + ['HTTP_AUTHORIZATION' => 'Bearer ' . $key];
    }
}
