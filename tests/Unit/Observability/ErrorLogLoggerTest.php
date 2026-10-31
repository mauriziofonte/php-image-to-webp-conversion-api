<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Observability;

use Mfonte\ImageConversionApi\Observability\ErrorLogLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The entries follow the log schema of the proxy's JsonLogger: the same
 * fields in the same order, the context always an object, the same
 * interpolation and the same JSON-friendly values.
 *
 * @covers \Mfonte\ImageConversionApi\Observability\ErrorLogLogger
 */
#[CoversClass(ErrorLogLogger::class)]
final class ErrorLogLoggerTest extends TestCase
{
    /**
     * @var list<string>
     */
    private $lines = [];

    public function testWritesOneJsonObjectPerEntryInTheSharedSchema(): void
    {
        $this->logger()->error('Failure in {route} after {attempts} attempts', ['route' => 'v2_convert', 'attempts' => 3]);

        $entry = $this->decodedLine();
        self::assertSame(['timestamp', 'level', 'channel', 'message', 'context'], array_keys($entry));
        self::assertSame('error', $entry['level']);
        self::assertSame('image-conversion-api', $entry['channel']);
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
        self::assertStringEndsWith('"message":"No API key is configured","context":{}}', $this->lines[0]);
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
            'resource' => '[resource]',
            'binary' => "\u{fffd}\u{fffd}",
        ], $this->decodedLine()['context'] ?? null);
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
        $logger = new ErrorLogLogger(static function (string $line) use (&$calls): void {
            ++$calls;

            throw new \RuntimeException('disk full');
        });

        $logger->error('Lost');

        self::assertSame(1, $calls);
    }

    private function logger(): ErrorLogLogger
    {
        return new ErrorLogLogger(function (string $line): void {
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
