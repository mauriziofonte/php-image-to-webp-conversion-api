<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\V1;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Http\Handler\DeprecatedHandler;
use Mfonte\ImageConversionApi\Http\Handler\V1ConvertHandler;
use Mfonte\ImageConversionApi\Http\Handler\V2ConvertHandler;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Mapping\ErrorMapper;
use Mfonte\ImageConversionApi\Mapping\V1RequestMapper;
use Mfonte\ImageConversionApi\Mapping\V1ResponseFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\ScriptedConverter;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\ScriptedConverterFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * POST /v1 beyond the golden documents: the limits 2.x adds (file count,
 * file size, time limit, conversion slots, rate limit), the rule that the
 * output is WEBP, the request shapes 1.x left undefined, the log and the
 * deprecation headers.
 *
 * @covers \Mfonte\ImageConversionApi\Http\Handler\V1ConvertHandler
 * @covers \Mfonte\ImageConversionApi\Http\Handler\DeprecatedHandler
 * @covers \Mfonte\ImageConversionApi\Mapping\V1RequestMapper
 * @covers \Mfonte\ImageConversionApi\Mapping\V1ResponseFactory
 *
 * @group contract
 */
#[CoversClass(V1ConvertHandler::class)]
#[CoversClass(DeprecatedHandler::class)]
#[CoversClass(V1RequestMapper::class)]
#[CoversClass(V1ResponseFactory::class)]
#[Group('contract')]
final class V1ConvertTest extends TestCase
{
    private const API_KEY = 'v1-test-key-0123456789';

    private const OUTPUT = 'RIFF....WEBPVP8 OUT';

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
        mkdir($this->directory->path('temp'));
        $this->logger = new MemoryLogger();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testRefusesMoreFilesThanTheLimitBeforeConverting(): void
    {
        $converter = ScriptedConverter::succeeding(self::OUTPUT);

        $response = $this->send($converter, $this->request(['a.png', 'b.png', 'c.png']), ['V1_MAX_FILES' => '2']);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('{"status":false,"version":"1.0.0","message":"Too many images: send at most 2 per request."}', $response->getContent());
        self::assertSame([], $converter->getCalls());
        self::assertSame(V1ConvertHandler::ERROR_TOO_MANY_FILES, $this->logged('route', 'v1')[V2ConvertHandler::ERROR_LOG_KEY] ?? null);
    }

    public function testAFileAboveTheMaximumSizeFailsAlone(): void
    {
        $converter = ScriptedConverter::succeeding(self::OUTPUT);

        $entries = self::entries($this->send($converter, $this->request(['big.png' => str_repeat('b', 65), 'small.png' => str_repeat('s', 64)]), [], 64));

        self::assertSame(['filename' => 'big.png', 'status' => false, 'error' => 'The uploaded file exceeds the maximum file size.'], $entries[0]);
        self::assertTrue($entries[1]['status']);
        self::assertCount(1, $converter->getCalls());
    }

    public function testAnOutputThatIsNotWebpFails(): void
    {
        $kept = ScriptedConverter::succeeding('PNG', ['format' => ImageFormat::PNG, 'passthrough' => true]);
        $fallback = ScriptedConverter::succeeding('JPEG', ['format' => ImageFormat::JPEG]);

        self::assertSame(
            [['filename' => 'a.png', 'status' => false, 'error' => 'The WEBP image would not be smaller than the original.']],
            self::entries($this->send($kept, $this->request(['a.png'])))
        );
        self::assertSame(
            [['filename' => 'a.png', 'status' => false, 'error' => 'Conversion failed.']],
            self::entries($this->send($fallback, $this->request(['a.png'])))
        );
    }

    public function testARejectedImageFailsWithTheFixedSentenceAndIsLogged(): void
    {
        $converter = ScriptedConverter::failingOn('BAD', new InvalidImageException('truncated IDAT at /tmp/x'), self::OUTPUT);

        $entries = self::entries($this->send($converter, $this->request(['bad.png' => 'BAD-INPUT', 'good.png' => 'GOOD-INPUT'])));

        self::assertSame(['filename' => 'bad.png', 'status' => false, 'error' => (new ErrorMapper())->messageOf(new InvalidImageException('x'))], $entries[0]);
        self::assertTrue($entries[1]['status']);
        $context = $this->logged(ErrorMapper::LOG_KEY, 'invalid_image');
        self::assertSame('v1', $context['route'] ?? null);
        self::assertSame('truncated IDAT at /tmp/x', $context['reason'] ?? null);
    }

    public function testAnswers503WithRetryAfterWhenNoSlotFreesUp(): void
    {
        $converter = ScriptedConverter::failingOn('SECOND', ConcurrencyLimitException::afterWaiting(2.5, 'no slot'), self::OUTPUT);

        $response = $this->send($converter, $this->request(['a.png' => 'FIRST', 'b.png' => 'SECOND', 'c.png' => 'THIRD']));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('{"status":false,"version":"1.0.0","message":"Too many conversions are running: retry later."}', $response->getContent());
        self::assertSame('3', $response->headers->get('Retry-After'));
        self::assertCount(2, $converter->getCalls(), 'the batch stops at the first file without a slot');
        self::assertSame([], $this->directory->entries('temp'));
    }

    public function testAnswers503WhenTheIsolationIsUnavailable(): void
    {
        $converter = ScriptedConverter::failing(new IsolationUnavailableException('bwrap missing'));

        $response = $this->send($converter, $this->request(['a.png']));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(V1ResponseFactory::CONTENT_TYPE, $response->headers->get('Content-Type'));
    }

    public function testEachConversionGetsWhatIsLeftOfTheTimeLimitInsideTheRequestDirectory(): void
    {
        $converter = ScriptedConverter::succeeding(self::OUTPUT);
        $factory = new ScriptedConverterFactory($converter);

        $this->sendWith($factory, $this->request(['a.png', 'b.png']), ['CONVERSION_TIME_LIMIT' => '9']);
        $expired = $this->request(['late.png']);
        $expired->server->set('REQUEST_TIME_FLOAT', microtime(true) - 10.0);
        $entries = self::entries($this->sendWith($factory, $expired, ['CONVERSION_TIME_LIMIT' => '9']));

        $created = $factory->getCreated();
        self::assertCount(2, $created);
        foreach ($created as $call) {
            self::assertTrue($call['existed']);
            self::assertStringStartsWith($this->directory->path('temp') . DIRECTORY_SEPARATOR, $call['workingDirectory']);
            self::assertGreaterThan(0.0, $call['timeLimitSeconds']);
            self::assertLessThanOrEqual(9.0, $call['timeLimitSeconds']);
        }
        self::assertSame($this->directory->path('temp'), dirname($converter->getCalls()[0]['outputPath'], 2));
        self::assertSame('The conversion would exceed the processing time limit.', $entries[0]['error'] ?? null);
    }

    public function testStreamsLargeOutputsAsTheirBase64(): void
    {
        $output = random_bytes(600001);
        $converter = ScriptedConverter::succeeding($output);

        $response = $this->send($converter, $this->request(['a.png' => str_repeat('a', 700000), 'b.png' => str_repeat('b', 700000)]), [], 2000000);
        $body = self::body($response);
        $entries = self::entries($response, $body);

        self::assertSame((string) strlen($body), $response->headers->get('Content-Length'));
        self::assertSame(base64_encode($output), $entries[0]['webp_image_base64']);
        self::assertSame(base64_encode($output), $entries[1]['webp_image_base64']);
        self::assertSame('585.94 kB', $entries[0]['new_filesize']);
        self::assertSame(14.29, $entries[0]['compression_ratio']);
    }

    public function testAcceptsASingleFileAndIgnoresEmptyFileInputs(): void
    {
        $converter = ScriptedConverter::succeeding(self::OUTPUT);
        $single = $this->request([]);
        $single->files->set('images', $this->upload('one.png', 'ONE'));
        // FileBag drops the empty inputs of a list, where 1.x listed them as failed files.
        $empty = $this->request([]);
        $empty->files->set('images', ['name' => [''], 'type' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE], 'size' => [0]]);

        self::assertSame('one.png', self::entries($this->send($converter, $single))[0]['filename']);
        self::assertSame([], $empty->files->all()['images'] ?? null);
        self::assertSame('{"status":false,"version":"1.0.0","message":"No images uploaded."}', $this->send($converter, $empty)->getContent());
    }

    public function testRefusesBodiesWithoutImagesOrWithDescriptorsThatAreNotAJsonArray(): void
    {
        $converter = ScriptedConverter::succeeding(self::OUTPUT);
        $raw = Request::create('https://api.example.test/v1/convert-now', 'POST', [], [], [], ['CONTENT_TYPE' => 'image/png', 'HTTP_X_API_KEY' => self::API_KEY], 'PNG');
        $nested = $this->request([]);
        $nested->files->set('images', [[$this->upload('a.png', 'A')]]);

        foreach (['null', '"text"', '5'] as $descriptors) {
            $response = $this->send($converter, $this->request(['a.png'], ['descriptors' => $descriptors]));
            self::assertSame(400, $response->getStatusCode(), $descriptors);
            self::assertSame('{"status":false,"version":"1.0.0","message":"Invalid descriptors JSON."}', $response->getContent());
        }
        $array = $this->request(['a.png']);
        $array->request->set('descriptors', ['[]']);
        self::assertSame(400, $this->send($converter, $array)->getStatusCode());
        foreach ([$raw, $nested] as $request) {
            $response = $this->send($converter, $request);
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('{"status":false,"version":"1.0.0","message":"No images uploaded."}', $response->getContent());
        }
        self::assertSame([], $converter->getCalls());
    }

    public function testEveryResponseCarriesTheDeprecationHeadersRateLimitedOnesIncluded(): void
    {
        $converter = ScriptedConverter::succeeding(self::OUTPUT);
        $config = ['RATE_LIMIT' => '1', 'RATE_LIMIT_BURST' => '1', 'RATE_LIMIT_STORE' => 'file', 'LOCK_DIR' => $this->directory->path('locks')];

        $first = $this->send($converter, $this->request(['a.png']), $config);
        $second = $this->send($converter, $this->request(['a.png']), $config);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertSame(['error' => ['code' => 'rate_limited', 'message' => 'This API key sent too many requests: retry later.']], json_decode((string) $second->getContent(), true));
        foreach ([$first, $second] as $response) {
            self::assertSame('@' . KernelFactory::V1_DEPRECATED_AT, $response->headers->get('Deprecation'));
            self::assertSame(gmdate('D, d M Y H:i:s', KernelFactory::V1_SUNSET_AT) . ' GMT', $response->headers->get('Sunset'));
            self::assertStringContainsString('rel="deprecation"', (string) $response->headers->get('Link'));
        }
    }

    public function testMapsTheMethodToTheEffortOfTheSameCwebpMethod(): void
    {
        $mapper = new V1RequestMapper();
        $expected = ['0' => 0, '1' => 2, '2' => 3, '3' => 5, '4' => 6, '5' => 8, '6' => 9, '7' => 6, '-1' => 6, 'fast' => 0, '3x' => 5];

        foreach ($expected as $m => $effort) {
            $request = Request::create('https://api.example.test/v1/x?m=' . rawurlencode((string) $m), 'POST');
            self::assertSame($effort, $mapper->toConversionRequest($request)->getEffort(), 'm=' . $m);
        }
        foreach ([0, 1, 2, 3, 4, 5, 6] as $method) {
            self::assertSame($method, (int) round(V1RequestMapper::effortOfMethod($method) * 6 / 9), 'the library maps the effort back to the method');
        }
        self::assertSame(6, $mapper->toConversionRequest(Request::create('https://api.example.test/v1/x?m[]=1', 'POST'))->getEffort());
        self::assertFalse($mapper->toConversionRequest(Request::create('https://api.example.test/v1/x?lossless=yes', 'POST'))->isLossless());
        self::assertTrue($mapper->toConversionRequest(Request::create('https://api.example.test/v1/x?lossless=1', 'POST'))->isLossless());
    }

    public function testFormatsSizesAs1xDid(): void
    {
        $mapper = new V1RequestMapper();

        self::assertSame('0 B', $mapper->formatBytes(0));
        self::assertSame('1023 B', $mapper->formatBytes(1023));
        self::assertSame('1 kB', $mapper->formatBytes(1024));
        self::assertSame('1.5 MB', $mapper->formatBytes(1572864));
        self::assertSame('2 GB', $mapper->formatBytes(2147483648));
        self::assertSame('Unknown upload error.', $mapper->uploadErrorMessage(99));
    }

    /**
     * @param array<int|string, string> $files  names, or names => contents
     * @param array<string, mixed>      $fields
     */
    private function request(array $files, array $fields = []): Request
    {
        $uploads = [];
        foreach ($files as $name => $content) {
            $uploads[] = is_int($name) ? $this->upload($content, 'INPUT-' . $content) : $this->upload($name, $content);
        }

        return Request::create(
            'https://api.example.test/v1/convert-now',
            'POST',
            $fields,
            [],
            $uploads === [] ? [] : ['images' => $uploads],
            ['CONTENT_TYPE' => 'multipart/form-data; boundary=----v1', 'HTTP_X_API_KEY' => self::API_KEY]
        );
    }

    private function upload(string $name, string $content): UploadedFile
    {
        $path = $this->directory->path('upload-' . bin2hex(random_bytes(4)));
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'application/octet-stream', UPLOAD_ERR_OK, true);
    }

    /**
     * @param array<string, string> $config
     */
    private function send(ScriptedConverter $converter, Request $request, array $config = [], int $maxInputBytes = 1048576): Response
    {
        return $this->sendWith(new ScriptedConverterFactory($converter, $maxInputBytes), $request, $config);
    }

    /**
     * @param array<string, string> $config
     */
    private function sendWith(ScriptedConverterFactory $factory, Request $request, array $config = []): Response
    {
        $values = $config + [
            'TEMP_DIR' => $this->directory->path('temp'),
            'API_KEY' => self::API_KEY,
            'V1_SCRIPT_NAME' => 'convert-now',
            'RATE_LIMIT' => '0',
        ];

        return KernelFactory::create(Config::fromArray($values), $this->logger, $factory)->handle($request);
    }

    /**
     * The context of the first log record whose context has $value under $key.
     *
     * @param mixed $value
     *
     * @return array<mixed>
     */
    private function logged(string $key, $value): array
    {
        foreach ($this->logger->getRecords() as $record) {
            if (($record['context'][$key] ?? null) === $value) {
                return $record['context'];
            }
        }
        self::fail('Nothing was logged with ' . $key);
    }

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function entries(Response $response, ?string $body = null): array
    {
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = json_decode($body ?? self::body($response), true);
        self::assertIsArray($document);
        self::assertTrue($document['status']);
        self::assertIsArray($document['response']);

        /** @var list<array<string, mixed>> */
        return $document['response'];
    }
}
