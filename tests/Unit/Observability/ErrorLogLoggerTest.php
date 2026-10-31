<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Observability;

use Mfonte\ImageConversionApi\Observability\ErrorLogLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LogLevel;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Observability\ErrorLogLogger
 */
#[CoversClass(ErrorLogLogger::class)]
final class ErrorLogLoggerTest extends TestCase
{
    /**
     * @var list<string>
     */
    private $lines = [];

    public function testWritesOneJsonObjectPerEntryWithPlaceholdersReplaced(): void
    {
        $this->logger()->error('Failure in {route} after {attempts} attempts', ['route' => 'v2_convert', 'attempts' => 3]);

        self::assertSame(
            ['{"level":"error","channel":"image-conversion-api","message":"Failure in v2_convert after 3 attempts","context":{"route":"v2_convert","attempts":3}}'],
            $this->lines
        );
    }

    public function testReducesExceptionsToTheirDescription(): void
    {
        $previous = new \LogicException('inner', 7);
        $this->logger()->log(LogLevel::CRITICAL, 'Unhandled', ['exception' => new \RuntimeException('outer', 1, $previous)]);

        $entry = $this->decodedLine();
        self::assertSame('critical', $entry['level'] ?? null);
        $exception = self::arrayAt(self::arrayAt($entry, 'context'), 'exception');
        self::assertSame('RuntimeException', $exception['class'] ?? null);
        self::assertSame('outer', $exception['message'] ?? null);
        self::assertSame(1, $exception['code'] ?? null);
        $file = $exception['file'] ?? null;
        self::assertIsString($file);
        self::assertStringStartsWith(__FILE__ . ':', $file);
        $previous = self::arrayAt($exception, 'previous');
        self::assertSame('LogicException', $previous['class'] ?? null);
        self::assertSame('inner', $previous['message'] ?? null);
    }

    public function testSummarisesValuesThatCannotBeEncoded(): void
    {
        $stream = fopen('php://memory', 'rb');
        $this->logger()->warning('Odd context', [
            'object' => new \stdClass(),
            'resource' => $stream,
            'deep' => [[[[['too deep']]]]],
            'binary' => "\xff\xfe",
        ]);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $context = self::arrayAt($this->decodedLine(), 'context');
        self::assertSame('[object stdClass]', $context['object'] ?? null);
        self::assertSame('[resource]', $context['resource'] ?? null);
        self::assertSame([[['[array of 1]']]], $context['deep'] ?? null);
        self::assertSame("\u{fffd}\u{fffd}", $context['binary'] ?? null);
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
