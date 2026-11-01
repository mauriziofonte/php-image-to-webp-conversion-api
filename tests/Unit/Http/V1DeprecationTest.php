<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Http;

use Mfonte\ImageConversionApi\Http\V1Deprecation;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The README and .env.example announce the schedule the /v1 responses
 * carry.
 *
 * @covers \Mfonte\ImageConversionApi\Http\V1Deprecation
 */
#[CoversClass(V1Deprecation::class)]
final class V1DeprecationTest extends TestCase
{
    public function testTheReadmeAnnouncesTheSchedule(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/README.md');

        self::assertStringContainsString('`Deprecation: @' . V1Deprecation::DEPRECATED_AT . '` (' . gmdate('Y-m-d', V1Deprecation::DEPRECATED_AT) . ')', $readme);
        self::assertStringContainsString('`Sunset: ' . gmdate('D, d M Y H:i:s', V1Deprecation::SUNSET_AT) . ' GMT`', $readme);
        self::assertStringContainsString("\n## Deprecated: the 1.x endpoint\n", $readme);
        self::assertStringEndsWith('#deprecated-the-1x-endpoint', V1Deprecation::NOTICE_URL);
    }

    public function testTheExampleConfigurationAnnouncesTheSunset(): void
    {
        $example = (string) file_get_contents(dirname(__DIR__, 3) . '/.env.example');

        self::assertStringContainsString('with a sunset on ' . gmdate('Y-m-d', V1Deprecation::SUNSET_AT), $example);
    }

    public function testTheSunsetFollowsTheDeprecation(): void
    {
        self::assertGreaterThan(V1Deprecation::DEPRECATED_AT, V1Deprecation::SUNSET_AT);
        self::assertSame('2026-11-01T00:00:00+00:00', gmdate('c', V1Deprecation::DEPRECATED_AT));
        self::assertSame('2027-11-01T00:00:00+00:00', gmdate('c', V1Deprecation::SUNSET_AT));
    }
}
