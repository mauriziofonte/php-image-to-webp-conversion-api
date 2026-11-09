<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\Contract;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Conversion\Deadline;
use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Http\Handler\V2ConvertHandler;
use Mfonte\ImageConversionApi\Http\InputReader;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Http\RequestDirectories;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Mapping\ErrorMapper;
use Mfonte\ImageConversionApi\Mapping\V2RequestMapper;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\ScriptedConverter;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\ScriptedConverterFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidImageException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidRequestException;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use Mfonte\SecureImageConverter\Contract\Exception\OutputVerificationException;
use Mfonte\SecureImageConverter\Contract\Exception\ResourceLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\UnsupportedFormatException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The HTTP contract of POST /v2/convert, which the proxy's RemoteConverter
 * relies on: request shapes (raw body, multipart), strict parameters,
 * the binary response with its exact Content-Type and X-Conversion-*
 * headers, and the status of every error code (finding C-6: no error
 * answers 200). The kernel is the production one (KernelFactory) with a
 * scripted converter in place of the library.
 *
 * @covers \Mfonte\ImageConversionApi\Http\Handler\V2ConvertHandler
 * @covers \Mfonte\ImageConversionApi\Http\InputReader
 * @covers \Mfonte\ImageConversionApi\Mapping\V2RequestMapper
 * @covers \Mfonte\ImageConversionApi\Mapping\ErrorMapper
 * @covers \Mfonte\ImageConversionApi\Conversion\Deadline
 * @covers \Mfonte\ImageConversionApi\Exception\RequestException
 *
 * @group contract
 */
#[CoversClass(V2ConvertHandler::class)]
#[CoversClass(InputReader::class)]
#[CoversClass(V2RequestMapper::class)]
#[CoversClass(ErrorMapper::class)]
#[CoversClass(Deadline::class)]
#[CoversClass(RequestException::class)]
#[Group('contract')]
final class V2ConvertContractTest extends TestCase
{
    private const API_KEY = 'contract-test-key-0123456789';

    private const INPUT = "\x89PNG\r\n\x1a\nINPUT-BYTES";

    private const OUTPUT = 'RIFF....WEBPVP8 OUTPUT-BYTES';

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

    public function testConvertsARawBody(): void
    {
        $converter = ScriptedConverter::succeeding(self::OUTPUT);
        $factory = new ScriptedConverterFactory($converter);

        $response = $this->send($factory, $this->raw(self::INPUT, ['HTTP_X_REQUEST_ID' => 'proxy-7f3a9c']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::OUTPUT, self::body($response));
        self::assertSame([
            'cache-control' => 'no-store, private',
            'content-length' => (string) strlen(self::OUTPUT),
            'content-type' => 'image/webp',
            'x-content-type-options' => 'nosniff',
            'x-conversion-backend' => 'cwebp',
            'x-conversion-duration-ms' => '12',
            'x-conversion-fallback' => '0',
            'x-conversion-format' => 'webp',
            'x-conversion-frames' => '1',
            'x-conversion-height' => '480',
            'x-conversion-input-bytes' => (string) strlen(self::INPUT),
            'x-conversion-isolation' => 'sandbox',
            'x-conversion-original-kept' => '0',
            'x-conversion-requested-format' => 'webp',
            'x-conversion-warnings' => '0',
            'x-conversion-width' => '640',
            'x-request-id' => 'proxy-7f3a9c',
        ], self::headers($response));

        $calls = $converter->getCalls();
        self::assertCount(1, $calls);
        self::assertSame(self::INPUT, $calls[0]['input']);
        self::assertSame((new ConversionRequest())->toParameters(), $calls[0]['request']->toParameters());
        self::assertSame([], $this->directory->entries(), 'the request directory is removed, the body was already open');
    }

    public function testTheConverterWorksInsideTheRequestDirectoryWithinTheTimeLimit(): void
    {
        $converter = ScriptedConverter::succeeding(self::OUTPUT);
        $factory = new ScriptedConverterFactory($converter);

        $this->send($factory, $this->raw(self::INPUT), ['CONVERSION_TIME_LIMIT' => '7']);

        $created = $factory->getCreated();
        self::assertCount(1, $created);
        self::assertTrue($created[0]['existed']);
        self::assertSame($this->directory->path(), dirname($created[0]['workingDirectory']));
        self::assertStringStartsWith(RequestDirectories::PREFIX, basename($created[0]['workingDirectory']));
        self::assertLessThanOrEqual(7.0, $created[0]['timeLimitSeconds']);
        self::assertGreaterThan(6.0, $created[0]['timeLimitSeconds']);
        $calls = $converter->getCalls();
        self::assertSame($created[0]['workingDirectory'], dirname($calls[0]['inputPath']));
        self::assertSame($created[0]['workingDirectory'], dirname($calls[0]['outputPath']));
    }

    public function testTheTimeLimitCountsFromTheStartOfTheRequest(): void
    {
        $converter = ScriptedConverter::succeeding(self::OUTPUT);
        $factory = new ScriptedConverterFactory($converter);
        $request = $this->raw(self::INPUT, ['REQUEST_TIME_FLOAT' => microtime(true) - 60.0]);

        $response = $this->send($factory, $request, ['CONVERSION_TIME_LIMIT' => '50']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('conversion_timeout', self::errorCode($response));
        self::assertSame([], $factory->getCreated());
    }

    public function testConvertsAMultipartUpload(): void
    {
        $converter = ScriptedConverter::succeeding('AVIF-OUTPUT', ['format' => ImageFormat::AVIF, 'backend' => 'avifenc']);
        $upload = $this->upload(self::INPUT);

        $response = $this->send(
            new ScriptedConverterFactory($converter),
            $this->multipart('?format=avif', ['quality' => '55', 'effort' => '6'], ['image' => $upload])
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('AVIF-OUTPUT', self::body($response));
        self::assertSame('image/avif', $response->headers->get('Content-Type'));
        self::assertSame('avif', $response->headers->get('X-Conversion-Format'));
        self::assertSame('avif', $response->headers->get('X-Conversion-Requested-Format'));
        self::assertSame('avifenc', $response->headers->get('X-Conversion-Backend'));
        $calls = $converter->getCalls();
        self::assertSame(self::INPUT, $calls[0]['input']);
        self::assertSame(
            ConversionRequest::fromParameters(['format' => 'avif', 'quality' => '55', 'effort' => '6'])->toParameters(),
            $calls[0]['request']->toParameters()
        );
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function provideParameters(): array
    {
        return [
            'none' => ['', []],
            'format and quality' => ['?format=avif&quality=50', ['format' => 'avif', 'quality' => '50']],
            'resize' => ['?width=320&height=200&fit=cover&enlarge=1', ['width' => '320', 'height' => '200', 'fit' => 'cover', 'enlarge' => '1']],
            'booleans spelled true and false' => ['?lossless=true&strip=false&keep_icc=0', ['lossless' => '1', 'strip' => '0', 'keep_icc' => '0']],
            'original format, first frame' => ['?format=original&animation=first_frame', ['format' => 'original', 'animation' => 'first_frame']],
        ];
    }

    /**
     * @dataProvider provideParameters
     *
     * @param array<string, string> $expected
     */
    #[DataProvider('provideParameters')]
    public function testMapsTheParametersToTheConversionRequest(string $query, array $expected): void
    {
        $converter = ScriptedConverter::succeeding(self::OUTPUT);

        $response = $this->send(new ScriptedConverterFactory($converter), $this->raw(self::INPUT, [], $query));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(ConversionRequest::fromParameters($expected)->toParameters(), $converter->getCalls()[0]['request']->toParameters());
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function provideInvalidParameters(): array
    {
        return [
            'unknown name' => ['?colour=red', null],
            'unknown name, upper case' => ['?Format=webp', null],
            'quality out of range' => ['?quality=0', 'quality'],
            'leading zero' => ['?quality=080', 'quality'],
            'unknown format' => ['?format=jpeg', 'format'],
            'upper-case value' => ['?format=WEBP', 'format'],
            'boolean spelled yes' => ['?lossless=yes', 'lossless'],
            'array' => ['?width[]=100', 'width'],
            'width above the contract' => ['?width=16384', 'width'],
            'empty value' => ['?effort=', 'effort'],
        ];
    }

    /**
     * @dataProvider provideInvalidParameters
     */
    #[DataProvider('provideInvalidParameters')]
    public function testRefusesInvalidParametersBeforeReadingTheBody(string $query, ?string $parameter): void
    {
        $converter = ScriptedConverter::succeeding(self::OUTPUT);
        $factory = new ScriptedConverterFactory($converter);

        $response = $this->send($factory, $this->raw(self::INPUT, [], $query));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_request', self::errorCode($response));
        $message = self::errorMessage($response);
        if ($parameter === null) {
            self::assertSame('The conversion parameters are not valid.', $message);
        } else {
            self::assertSame(sprintf('The conversion parameters are not valid. Check the parameter "%s".', $parameter), $message);
        }
        self::assertStringNotContainsString('colour', (string) $response->getContent());
        self::assertSame([], $factory->getCreated());
        self::assertSame([], $converter->getCalls());
        self::assertSame('invalid_request', $this->logger->getRecords()[0]['context'][ErrorMapper::LOG_KEY] ?? null);
    }

    public function testRefusesAParameterGivenTwice(): void
    {
        $factory = new ScriptedConverterFactory(ScriptedConverter::succeeding(self::OUTPUT));

        $response = $this->send($factory, $this->multipart('?quality=50', ['quality' => '60'], ['image' => $this->upload(self::INPUT)]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_request', self::errorCode($response));
        self::assertSame([], $factory->getCreated());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideEmptyBodies(): array
    {
        return ['no Content-Type' => [''], 'image/png' => ['image/png'], 'a form' => ['application/x-www-form-urlencoded']];
    }

    /**
     * @dataProvider provideEmptyBodies
     */
    #[DataProvider('provideEmptyBodies')]
    public function testAnEmptyBodyIsAMissingImage(string $contentType): void
    {
        $factory = new ScriptedConverterFactory(ScriptedConverter::succeeding(self::OUTPUT));
        $request = Request::create('https://api.example.test/v2/convert', 'POST', [], [], [], $this->server(['CONTENT_TYPE' => $contentType]), '');

        $response = $this->send($factory, $request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(ResponseFactory::ERROR_MISSING_IMAGE, self::errorCode($response));
        self::assertSame([], $factory->getCreated());
        self::assertSame([], $this->directory->entries());
        self::assertSame(ResponseFactory::ERROR_MISSING_IMAGE, $this->logger->getRecords()[0]['context'][V2ConvertHandler::ERROR_LOG_KEY] ?? null);
    }

    public function testRefusesAFormBody(): void
    {
        $factory = new ScriptedConverterFactory(ScriptedConverter::succeeding(self::OUTPUT));

        $response = $this->send($factory, $this->raw('image=abc', ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']));

        self::assertSame(415, $response->getStatusCode());
        self::assertSame(ResponseFactory::ERROR_UNSUPPORTED_MEDIA_TYPE, self::errorCode($response));
        self::assertSame([], $factory->getCreated());
    }

    /**
     * @return array<string, array{array<string, mixed>, int, string}>
     */
    public static function provideMultipartBodies(): array
    {
        return [
            'no file' => [[], 400, 'missing_image'],
            'another field' => [['picture' => true], 400, 'invalid_request'],
            'a second file' => [['image' => true, 'other' => true], 400, 'invalid_request'],
            'several images' => [['image' => [true, true]], 400, 'invalid_request'],
            'no file was uploaded' => [['image' => UPLOAD_ERR_NO_FILE], 400, 'missing_image'],
            'partial upload' => [['image' => UPLOAD_ERR_PARTIAL], 400, 'missing_image'],
            'above upload_max_filesize' => [['image' => UPLOAD_ERR_INI_SIZE], 413, 'file_too_large'],
            'above MAX_FILE_SIZE' => [['image' => UPLOAD_ERR_FORM_SIZE], 413, 'file_too_large'],
            'no temporary directory' => [['image' => UPLOAD_ERR_NO_TMP_DIR], 500, 'internal_error'],
            'cannot write' => [['image' => UPLOAD_ERR_CANT_WRITE], 500, 'internal_error'],
            'empty file' => [['image' => ''], 400, 'missing_image'],
        ];
    }

    /**
     * @dataProvider provideMultipartBodies
     *
     * @param array<string, mixed> $fields field name => true for a valid upload, an upload error, '' for an empty
     *                                     file, or a list of those
     */
    #[DataProvider('provideMultipartBodies')]
    public function testRefusesMultipartBodiesWithoutExactlyOneImage(array $fields, int $status, string $code): void
    {
        $factory = new ScriptedConverterFactory(ScriptedConverter::succeeding(self::OUTPUT));
        $files = [];
        foreach ($fields as $name => $spec) {
            $files[$name] = is_array($spec) ? array_map([$this, 'uploadFor'], $spec) : $this->uploadFor($spec);
        }

        $response = $this->send($factory, $this->multipart('', [], $files));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code, self::errorCode($response));
        self::assertSame([], $factory->getCreated());
    }

    public function testRefusesARawBodyAboveTheLimitWhileReadingIt(): void
    {
        $factory = new ScriptedConverterFactory(ScriptedConverter::succeeding(self::OUTPUT), 16);
        $body = fopen('php://temp', 'w+b');
        self::assertIsResource($body);
        fwrite($body, str_repeat('x', 1000));
        rewind($body);
        // No Content-Length, as with chunked transfer encoding.
        $request = Request::create('https://api.example.test/v2/convert', 'POST', [], [], [], $this->server(['CONTENT_TYPE' => 'image/png']), $body);
        $request->headers->remove('Content-Length');

        $response = $this->send($factory, $request);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('file_too_large', self::errorCode($response));
        self::assertSame(17, ftell($body), 'the reader stopped one byte past the limit');
        self::assertSame([], $factory->getCreated());
        self::assertSame([], $this->directory->entries());
    }

    public function testAcceptsABodyOfExactlyTheLimit(): void
    {
        $factory = new ScriptedConverterFactory(ScriptedConverter::succeeding(self::OUTPUT), 16);

        self::assertSame(200, $this->send($factory, $this->raw(str_repeat('x', 16)))->getStatusCode());
        self::assertSame(413, $this->send($factory, $this->raw(str_repeat('x', 17)))->getStatusCode());
    }

    public function testRefusesAnUploadAboveTheLimit(): void
    {
        $factory = new ScriptedConverterFactory(ScriptedConverter::succeeding(self::OUTPUT), 16);

        $response = $this->send($factory, $this->multipart('', [], ['image' => $this->upload(str_repeat('x', 17))]));

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('file_too_large', self::errorCode($response));
    }

    /**
     * @return array<string, array{\Throwable, int, string, string}>
     */
    public static function provideLibraryErrors(): array
    {
        return [
            'invalid_request' => [InvalidRequestException::forParameter('width', 'must be at most 16383'), 400, 'invalid_request', 'The conversion parameters are not valid. Check the parameter "width".'],
            'unsupported_format' => [new UnsupportedFormatException('HEIC at /srv/x is not enabled.'), 415, 'unsupported_format', 'The image format is not supported.'],
            'invalid_image' => [new InvalidImageException('Truncated PNG at /srv/x.'), 422, 'invalid_image', 'The image is malformed, truncated or not of the format its content claims.'],
            'animation_rejected' => [InvalidImageException::animationRejected(12), 422, 'animation_rejected', 'The image is animated and the request rejects animations.'],
            'file_too_large' => [LimitExceededException::forInput(Capabilities::LIMIT_MAX_BYTES, 10, 20), 413, 'file_too_large', 'The image is larger than the maximum file size.'],
            'image_too_large' => [LimitExceededException::forOutput(Capabilities::LIMIT_MAX_WIDTH, 10, 20), 422, 'image_too_large', 'The image, or the requested output, exceeds a dimension, pixel or frame limit.'],
            'conversion_timeout' => [new ConversionTimeoutException('Above the ceiling.'), 422, 'conversion_timeout', 'The conversion would exceed the processing time limit.'],
            'resource_limit' => [new ResourceLimitException('Killed by RLIMIT_AS.'), 422, 'resource_limit', 'The conversion exceeded its memory, CPU time or file size limit.'],
            'isolation_unavailable' => [new IsolationUnavailableException('bwrap is missing.'), 503, 'isolation_unavailable', 'The isolation that conversions require is not available on this server.'],
            'concurrency_limit' => [ConcurrencyLimitException::afterWaiting(2.5, 'All 4 slots are busy.'), 503, 'concurrency_limit', 'Too many conversions are running: retry later.'],
            'conversion_failed' => [new ConversionFailedException('cwebp crashed (signal 11).'), 500, 'conversion_failed', 'The image could not be converted.'],
            'output_verification_failed' => [new OutputVerificationException('Sniffed PNG, expected WEBP.'), 500, 'output_verification_failed', 'The conversion output failed its verification.'],
            'internal_error' => [new InvalidArgumentException('The input path is not a readable file.'), 500, 'internal_error', 'The request could not be processed.'],
        ];
    }

    /**
     * @dataProvider provideLibraryErrors
     */
    #[DataProvider('provideLibraryErrors')]
    public function testMapsEveryLibraryErrorCodeToItsStatus(\Throwable $failure, int $status, string $code, string $message): void
    {
        $response = $this->send(new ScriptedConverterFactory(ScriptedConverter::failing($failure)), $this->raw(self::INPUT));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame(['error' => ['code' => $code, 'message' => $message]], json_decode((string) $response->getContent(), true));
        self::assertSame($code === 'concurrency_limit' ? '3' : null, $response->headers->get('Retry-After'));
        self::assertSame([], $this->directory->entries());

        $records = $this->logger->getRecords();
        self::assertCount(1, $records);
        self::assertSame($status >= 500 ? 'error' : 'info', $records[0]['level']);
        self::assertSame($code, $records[0]['context'][ErrorMapper::LOG_KEY] ?? null);
        self::assertSame($failure->getMessage(), $records[0]['context']['reason'] ?? null);
    }

    /**
     * Every library code has a status (the table of ErrorMapper is complete).
     */
    public function testEveryLibraryErrorCodeIsCovered(): void
    {
        $covered = array_keys(self::provideLibraryErrors());
        sort($covered);
        $codes = \Mfonte\SecureImageConverter\Contract\Exception\ErrorCode::all();
        sort($codes);

        self::assertSame($codes, $covered);
    }

    public function testAnUnknownLibraryCodeIsAnInternalError(): void
    {
        $failure = new class ('Something new.') extends \RuntimeException implements ExceptionInterface {
            public function getErrorCode(): string
            {
                return 'something_new';
            }
        };

        $response = $this->send(new ScriptedConverterFactory(ScriptedConverter::failing($failure)), $this->raw(self::INPUT));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('internal_error', self::errorCode($response));
    }

    public function testReportsAFallbackWithItsReason(): void
    {
        $reason = 'The avif output is not available, fell back to webp: no available backend writes avif.';
        $converter = ScriptedConverter::succeeding(self::OUTPUT, ['warnings' => [$reason, 'Isolation is process, weaker than sandbox.']]);

        $response = $this->send(new ScriptedConverterFactory($converter), $this->raw(self::INPUT, [], '?format=avif'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertSame('avif', $response->headers->get('X-Conversion-Requested-Format'));
        self::assertSame('1', $response->headers->get('X-Conversion-Fallback'));
        self::assertSame($reason, $response->headers->get('X-Conversion-Fallback-Reason'));
        self::assertSame('2', $response->headers->get('X-Conversion-Warnings'));
        self::assertSame([$reason, 'Isolation is process, weaker than sandbox.'], $response->headers->all('X-Conversion-Warning'));
    }

    public function testReportsTheOriginalKept(): void
    {
        $converter = ScriptedConverter::succeeding(self::INPUT, ['format' => ImageFormat::PNG, 'passthrough' => true, 'backend' => 'gd', 'isolation' => IsolationLevel::RLIMIT]);

        $response = $this->send(new ScriptedConverterFactory($converter), $this->raw(self::INPUT));

        self::assertSame(self::INPUT, self::body($response));
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('1', $response->headers->get('X-Conversion-Original-Kept'));
        self::assertSame('0', $response->headers->get('X-Conversion-Fallback'), 'keeping the original is not a fallback');
        self::assertSame('rlimit', $response->headers->get('X-Conversion-Isolation'));
    }

    public function testReportsAnAnimation(): void
    {
        $converter = ScriptedConverter::succeeding(self::OUTPUT, ['frames' => 24]);

        $response = $this->send(new ScriptedConverterFactory($converter), $this->raw(self::INPUT));

        self::assertSame('24', $response->headers->get('X-Conversion-Frames'));
    }

    /**
     * Each request is counted in the metrics with its outcome; only the
     * conversions that produced an output are timed.
     */
    public function testCountsEachConversionInTheMetrics(): void
    {
        $config = ['METRICS_STORE' => 'file', 'LOCK_DIR' => $this->directory->path('locks')];
        $this->send(new ScriptedConverterFactory(ScriptedConverter::succeeding(self::OUTPUT)), $this->raw(self::INPUT), $config);
        $this->send(new ScriptedConverterFactory(ScriptedConverter::failing(new InvalidImageException('Truncated.'))), $this->raw(self::INPUT), $config);
        $this->send(new ScriptedConverterFactory(ScriptedConverter::failing(new InvalidArgumentException('Unknown.'))), $this->raw(self::INPUT), $config);

        $scrape = Request::create('https://api.example.test/metrics', 'GET', [], [], [], $this->server([]));
        $body = (string) $this->send(new ScriptedConverterFactory(ScriptedConverter::succeeding(self::OUTPUT)), $scrape, $config)->getContent();

        self::assertStringContainsString('image_conversion_api_conversions_total{route="v2_convert",outcome="converted"} 1' . "\n", $body);
        self::assertStringContainsString('image_conversion_api_conversions_total{route="v2_convert",outcome="invalid_image"} 1' . "\n", $body);
        self::assertStringContainsString('image_conversion_api_conversions_total{route="v2_convert",outcome="internal_error"} 1' . "\n", $body);
        self::assertStringContainsString('image_conversion_api_conversion_duration_seconds_count{route="v2_convert"} 1' . "\n", $body);
        self::assertStringContainsString('image_conversion_api_requests_total{route="v2_convert",status="422"} 1' . "\n", $body);
    }

    public function testAnswersOnlyAuthenticatedClients(): void
    {
        $factory = new ScriptedConverterFactory(ScriptedConverter::succeeding(self::OUTPUT));
        $request = Request::create('https://api.example.test/v2/convert', 'POST', [], [], [], ['CONTENT_TYPE' => 'image/png'], self::INPUT);

        $response = $this->send($factory, $request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $factory->getCreated());
    }

    /**
     * @param array<string, string|null> $config
     */
    private function send(ScriptedConverterFactory $factory, Request $request, array $config = []): Response
    {
        // Rate limiting is off here (LimitsTest covers it): its state would live in TEMP_DIR.
        $values = $config + ['TEMP_DIR' => $this->directory->path(), 'API_KEY' => self::API_KEY, 'RATE_LIMIT' => '0', 'METRICS_STORE' => 'off'];

        return KernelFactory::create(Config::fromArray($values), $this->logger, $factory)->handle($request);
    }

    /**
     * @param array<string, mixed> $server
     */
    private function raw(string $body, array $server = [], string $query = ''): Request
    {
        return Request::create('https://api.example.test/v2/convert' . $query, 'POST', [], [], [], $this->server($server + ['CONTENT_TYPE' => 'image/png']), $body);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed>  $files
     */
    private function multipart(string $query, array $fields, array $files): Request
    {
        $server = $this->server(['CONTENT_TYPE' => 'multipart/form-data; boundary=----contract']);

        return Request::create('https://api.example.test/v2/convert' . $query, 'POST', $fields, [], $files, $server);
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array<string, mixed>
     */
    private function server(array $server): array
    {
        return $server + ['HTTP_AUTHORIZATION' => 'Bearer ' . self::API_KEY];
    }

    private function upload(string $content, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $path = $this->directory->path('upload-' . bin2hex(random_bytes(4)));
        file_put_contents($path, $content);

        return new UploadedFile($path, 'image.png', 'image/png', $error, true);
    }

    /**
     * @param mixed $spec true for a valid upload, '' for an empty file, an int for an upload error
     *
     * @return UploadedFile|array<string, mixed>
     */
    private function uploadFor($spec)
    {
        if ($spec === UPLOAD_ERR_NO_FILE) {
            // What PHP gives for an empty file input; FileBag turns it into null.
            return ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        }
        if (is_int($spec)) {
            return $this->upload('', $spec);
        }

        return $this->upload($spec === '' ? '' : self::INPUT);
    }

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /**
     * @return array<string, string> lower-case names, without Date
     */
    private static function headers(Response $response): array
    {
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            if ($name !== 'date') {
                $headers[(string) $name] = implode(', ', $values);
            }
        }
        ksort($headers);

        return $headers;
    }

    /**
     * @return array{code: string, message: string}
     */
    private static function error(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['error'] ?? null);
        self::assertIsString($data['error']['code'] ?? null);
        self::assertIsString($data['error']['message'] ?? null);

        return ['code' => $data['error']['code'], 'message' => $data['error']['message']];
    }

    private static function errorCode(Response $response): string
    {
        return self::error($response)['code'];
    }

    private static function errorMessage(Response $response): string
    {
        return self::error($response)['message'];
    }
}
