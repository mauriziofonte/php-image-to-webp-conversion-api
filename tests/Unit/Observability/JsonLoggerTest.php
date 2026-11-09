<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Observability;

use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Observability\JsonLogger;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\LimitExceededException;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The entries follow the log schema shared with the proxy's JsonLogger:
 * the same fields in the same order (with the request ID), the context
 * always an object, the same interpolation and the same JSON-friendly
 * values, error codes under error_code (the API's) and
 * conversion_error_code (the library's).
 *
 * @covers \Mfonte\ImageConversionApi\Observability\JsonLogger
 */
#[CoversClass(JsonLogger::class)]
final class JsonLoggerTest extends TestCase
{
    /**
     * @var list<string>
     */
    private $lines = [];

    public function testWritesOneJsonObjectPerEntryInTheSharedSchema(): void
    {
        $this->logger()->error('Failure in {route} after {attempts} attempts', ['route' => 'v2_convert', 'attempts' => 3]);

        $entry = $this->decodedLine();
        self::assertSame(['timestamp', 'level', 'channel', 'request_id', 'message', 'context'], array_keys($entry));
        self::assertSame('error', $entry['level']);
        self::assertSame('image-conversion-api', $entry['channel']);
        self::assertNull($entry['request_id']);
        self::assertSame('Failure in v2_convert after 3 attempts', $entry['message']);
        self::assertSame(['route' => 'v2_convert', 'attempts' => 3], $entry['context']);
    }

    public function testTheTimestampIsRfc3339WithMicroseconds(): void
    {
        $this->logger()->warning('message');

        $timestamp = $this->decodedLine()['timestamp'] ?? null;
        self::assertIsString($timestamp);
        self::assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}\z/', $timestamp);
    }

    /**
     * 1.x-style "context":[] would be a list for a JSON reader.
     */
    public function testAnEmptyContextIsAnObject(): void
    {
        $this->logger()->critical('No API key is configured');

        self::assertCount(1, $this->lines);
        self::assertStringEndsWith('"request_id":null,"message":"No API key is configured","context":{}}', $this->lines[0]);
    }

    public function testPlaceholdersAreReplacedAsTheProxyReplacesThem(): void
    {
        $this->logger()->info('{route} {ms} ms, cached {cached}, retried {retried}, [{none}] at {date}, {missing} {array}', [
            'route' => 'v2_convert',
            'ms' => 12.5,
            'cached' => true,
            'retried' => false,
            'none' => null,
            'date' => new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
            'array' => [1],
        ]);

        self::assertSame(
            'v2_convert 12.5 ms, cached true, retried false, [] at 2026-09-01T10:00:00+00:00, {missing} {array}',
            $this->decodedLine()['message'] ?? null
        );
    }

    public function testReducesExceptionsToTheirDescription(): void
    {
        $previous = new \LogicException('inner', 7);
        $exception = new \RuntimeException('outer', 1, $previous);

        $this->logger()->log(LogLevel::CRITICAL, 'Unhandled', ['exception' => $exception]);

        self::assertSame([
            'class' => 'RuntimeException',
            'message' => 'outer',
            'code' => 1,
            'file' => __FILE__ . ':' . $exception->getLine(),
            'previous' => [
                'class' => 'LogicException',
                'message' => 'inner',
                'code' => 7,
                'file' => __FILE__ . ':' . $previous->getLine(),
            ],
        ], self::arrayAt(self::arrayAt($this->decodedLine(), 'context'), 'exception'));
    }

    public function testMakesOtherValuesJsonFriendly(): void
    {
        $stream = fopen('php://memory', 'rb');
        self::assertIsResource($stream);

        $this->logger()->warning('Odd context', [
            'null' => null,
            'flag' => false,
            'date' => new \DateTimeImmutable('2026-09-01T10:00:00+00:00'),
            'object' => new \ArrayObject(),
            'serialisable' => new class () implements \JsonSerializable {
                /**
                 * @return array<string, int>
                 */
                public function jsonSerialize(): array
                {
                    return ['id' => 7];
                }
            },
            'nested' => ['a' => ['b' => 1]],
            'infinite' => INF,
            'negative' => -INF,
            'nan' => NAN,
            'resource' => $stream,
            'binary' => "\xff\xfe",
        ]);
        fclose($stream);

        self::assertSame([
            'null' => null,
            'flag' => false,
            'date' => '2026-09-01T10:00:00+00:00',
            'object' => '[object ArrayObject]',
            'serialisable' => ['id' => 7],
            'nested' => ['a' => ['b' => 1]],
            'infinite' => 'INF',
            'negative' => '-INF',
            'nan' => 'NAN',
            'resource' => '[resource]',
            'binary' => "\u{fffd}\u{fffd}",
        ], $this->decodedLine()['context'] ?? null);
    }

    /**
     * PHP 8.5 warns when NAN is cast to a string; the strict error handler
     * of the suite turns that warning into a failure.
     */
    public function testWritesFloatsThatAreNotFiniteWithoutWarnings(): void
    {
        $this->logger()->info('{nan} {inf} {negative}', ['nan' => NAN, 'inf' => INF, 'negative' => -INF]);
        $this->logger()->log(LogLevel::INFO, NAN);

        self::assertCount(2, $this->lines);
        $first = json_decode($this->lines[0], true);
        $second = json_decode($this->lines[1], true);
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame('NAN INF -INF', $first['message']);
        self::assertSame(['nan' => 'NAN', 'inf' => 'INF', 'negative' => '-INF'], $first['context']);
        self::assertSame('NAN', $second['message']);
    }

    public function testWritesTheRequestId(): void
    {
        $logger = $this->logger()->withRequestId('4bf92f3577b34da6a3ce929d0e0e4736');
        $logger->info('first');
        $logger->withRequestId(null)->info('second');

        self::assertCount(2, $this->lines);
        self::assertStringContainsString('"request_id":"4bf92f3577b34da6a3ce929d0e0e4736","message":"first"', $this->lines[0]);
        self::assertStringContainsString('"request_id":null,"message":"second"', $this->lines[1]);
    }

    /**
     * The API's codes go under error_code, the library's under
     * conversion_error_code, in the exception object and in the context.
     */
    public function testLogsTheErrorCodesOfExceptionsUnderTheirKeys(): void
    {
        $library = ConcurrencyLimitException::afterWaiting(3.0, 'No slot');
        $api = new RequestException(503, 'concurrency_unavailable', 'Retry later.', [], $library);

        $this->logger()->error('Failed', ['exception' => $api]);

        $context = self::arrayAt($this->decodedLine(), 'context');
        self::assertSame('concurrency_unavailable', $context['error_code'] ?? null);
        self::assertArrayNotHasKey('conversion_error_code', $context);
        $exception = self::arrayAt($context, 'exception');
        self::assertSame(RequestException::class, $exception['class'] ?? null);
        self::assertSame('concurrency_unavailable', $exception['error_code'] ?? null);
        $previous = self::arrayAt($exception, 'previous');
        self::assertSame(ConcurrencyLimitException::class, $previous['class'] ?? null);
        self::assertSame(ErrorCode::CONCURRENCY_LIMIT, $previous['conversion_error_code'] ?? null);
        self::assertArrayNotHasKey('error_code', $previous);
    }

    public function testCopiesTheLibraryCodeOfTheExceptionUnlessTheContextNamesOne(): void
    {
        $exception = LimitExceededException::forInput(Capabilities::LIMIT_MAX_WIDTH, 8192, 9000);
        $this->logger()->info('Refused', ['exception' => $exception]);
        $this->logger()->info('Refused', ['exception' => $exception, 'conversion_error_code' => 'kept']);

        self::assertCount(2, $this->lines);
        self::assertStringContainsString('"conversion_error_code":"image_too_large"', $this->lines[0]);
        self::assertStringContainsString('"conversion_error_code":"kept"', $this->lines[1]);
    }

    public function testDropsEntriesBelowTheMinimumLevel(): void
    {
        $logger = new JsonLogger(function (string $line): void {
            $this->lines[] = $line;
        }, LogLevel::WARNING);

        $logger->debug('dropped');
        $logger->info('dropped');
        $logger->notice('dropped');
        $logger->warning('kept');
        $logger->emergency('kept');

        self::assertCount(2, $this->lines);
        self::assertTrue(JsonLogger::isLevel('warning'));
        self::assertFalse(JsonLogger::isLevel('verbose'));
    }

    public function testRefusesAnUnknownMinimumLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JsonLogger(null, 'verbose');
    }

    public function testAppendsLinesToAFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ica-log-');
        self::assertIsString($file);

        try {
            $logger = new JsonLogger(JsonLogger::writerFor($file));
            $logger->info('one');
            $logger->info('two');

            $lines = file($file, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines);
            self::assertCount(2, $lines);
            self::assertStringContainsString('"message":"two"', $lines[1]);
        } finally {
            unlink($file);
        }
    }

    public function testAFileThatCannotBeWrittenIsIgnored(): void
    {
        $writer = JsonLogger::writerFor(sys_get_temp_dir() . '/ica-missing-' . bin2hex(random_bytes(6)) . '/api.log');

        self::assertFalse($writer('{}'));
    }

    public function testWritesToTheStandardErrorStream(): void
    {
        $script = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
            . ' $l = new ' . JsonLogger::class . '(' . JsonLogger::class . '::writerFor("stderr"));'
            . ' $l->warning("to stderr");';
        $process = proc_open(
            escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($script),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));

        self::assertSame('', $stdout);
        self::assertIsString($stderr);
        self::assertMatchesRegularExpression('/\A\{"timestamp":"[^"]+","level":"warning".*"message":"to stderr","context":\{\}\}\n\z/', $stderr);
    }

    public function testSummarisesDeepValues(): void
    {
        $value = 'leaf';
        for ($i = 0; $i < 12; ++$i) {
            $value = [$value];
        }

        $this->logger()->info('deep', ['value' => $value]);

        self::assertCount(1, $this->lines);
        self::assertStringContainsString('[[[[[[[["[...]"]]]]]]]]', $this->lines[0]);
    }

    /**
     * Messages and values cannot forge other entries (log injection).
     */
    public function testNewlinesStayInsideTheEntry(): void
    {
        $this->logger()->error("a\nb", ['url' => "c\r\n{\"level\":\"emergency\"}"]);

        self::assertCount(1, $this->lines);
        self::assertStringNotContainsString("\n", $this->lines[0]);
        self::assertSame("a\nb", $this->decodedLine()['message'] ?? null);
    }

    public function testRefusesAnUnknownLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->logger()->log('verbose', 'message');
    }

    public function testAWriterThatFailsDoesNotBreakTheCaller(): void
    {
        $calls = 0;
        $logger = new JsonLogger(static function (string $line) use (&$calls): void {
            ++$calls;

            throw new \RuntimeException('disk full');
        });

        $logger->error('Lost');

        self::assertSame(1, $calls);
    }

    private function logger(): JsonLogger
    {
        return new JsonLogger(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    /**
     * @return array<mixed>
     */
    private function decodedLine(): array
    {
        self::assertCount(1, $this->lines);
        $entry = json_decode($this->lines[0], true);
        self::assertIsArray($entry);

        return $entry;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function arrayAt(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        self::assertIsArray($value, sprintf('"%s" is not an array', $key));

        return $value;
    }
}
