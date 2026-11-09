<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Observability;

use Mfonte\ImageConversionApi\Observability\Metrics;
use Mfonte\ImageConversionApi\Tests\Fixtures\Observability\MemoryMetricsStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Observability\Metrics
 */
#[CoversClass(Metrics::class)]
final class MetricsTest extends TestCase
{
    public function testRendersTheTextExposition(): void
    {
        $store = new MemoryMetricsStore();
        $metrics = new Metrics($store);
        $metrics->recordRequest('v2_convert', 200, 0.3);
        $metrics->recordConversion('v2_convert', Metrics::CONVERTED, 0.2125);
        self::assertTrue($metrics->flush());
        $metrics->recordRequest(null, 404, 0.001);
        $metrics->recordConversion('v2_convert', 'invalid_image', 0.0);
        self::assertTrue($metrics->flush());

        $buckets = static function (string $name, string $labels, array $counts): string {
            /** @var list<int> $counts */
            $bounds = ['0.005', '0.025', '0.1', '0.25', '0.5', '1', '2.5', '5', '10', '30', '60', '+Inf'];
            $lines = '';
            foreach ($bounds as $index => $bound) {
                $lines .= $name . '_bucket{' . $labels . ',le="' . $bound . '"} ' . $counts[$index] . "\n";
            }

            return $lines;
        };
        self::assertSame(
            "# HELP image_conversion_api_up Whether the conversion API answers (always 1 in a scrape that succeeds).\n"
            . "# TYPE image_conversion_api_up gauge\n"
            . "image_conversion_api_up 1\n"
            . "# HELP image_conversion_api_metrics_available Whether the counters could be read from METRICS_STORE (0 when they are missing from this scrape).\n"
            . "# TYPE image_conversion_api_metrics_available gauge\n"
            . "image_conversion_api_metrics_available 1\n"
            . "# HELP image_conversion_api_requests_total Requests answered, by route and HTTP status.\n"
            . "# TYPE image_conversion_api_requests_total counter\n"
            . "image_conversion_api_requests_total{route=\"none\",status=\"404\"} 1\n"
            . "image_conversion_api_requests_total{route=\"v2_convert\",status=\"200\"} 1\n"
            . "# HELP image_conversion_api_request_duration_seconds Time from the start of the kernel to the response, by route.\n"
            . "# TYPE image_conversion_api_request_duration_seconds histogram\n"
            . $buckets('image_conversion_api_request_duration_seconds', 'route="none"', [1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1])
            . "image_conversion_api_request_duration_seconds_sum{route=\"none\"} 0.001\n"
            . "image_conversion_api_request_duration_seconds_count{route=\"none\"} 1\n"
            . $buckets('image_conversion_api_request_duration_seconds', 'route="v2_convert"', [0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1])
            . "image_conversion_api_request_duration_seconds_sum{route=\"v2_convert\"} 0.3\n"
            . "image_conversion_api_request_duration_seconds_count{route=\"v2_convert\"} 1\n"
            . "# HELP image_conversion_api_conversions_total Conversions attempted, by route and outcome (converted, or the error code).\n"
            . "# TYPE image_conversion_api_conversions_total counter\n"
            . "image_conversion_api_conversions_total{route=\"v2_convert\",outcome=\"converted\"} 1\n"
            . "image_conversion_api_conversions_total{route=\"v2_convert\",outcome=\"invalid_image\"} 1\n"
            . "# HELP image_conversion_api_conversion_duration_seconds Duration of the conversions that produced an output, by route.\n"
            . "# TYPE image_conversion_api_conversion_duration_seconds histogram\n"
            . $buckets('image_conversion_api_conversion_duration_seconds', 'route="v2_convert"', [0, 0, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1])
            . "image_conversion_api_conversion_duration_seconds_sum{route=\"v2_convert\"} 0.2125\n"
            . "image_conversion_api_conversion_duration_seconds_count{route=\"v2_convert\"} 1\n",
            $metrics->render()
        );
    }

    /**
     * The increments of a request reach the store in one update, and only
     * once.
     */
    public function testFlushWritesOnceAndForgets(): void
    {
        $store = new MemoryMetricsStore();
        $metrics = new Metrics($store);

        self::assertTrue($metrics->flush(), 'nothing to write');
        $metrics->recordRequest('healthz', 200, 0.01);
        $metrics->recordRequest('healthz', 200, 0.01);
        self::assertTrue($metrics->flush());
        self::assertTrue($metrics->flush());

        self::assertSame(1, $store->getUpdates());
        self::assertStringContainsString("image_conversion_api_requests_total{route=\"healthz\",status=\"200\"} 2\n", $metrics->render());
        self::assertStringContainsString("image_conversion_api_request_duration_seconds_sum{route=\"healthz\"} 0.02\n", $metrics->render());
    }

    public function testAStoreThatFailsIsReported(): void
    {
        $store = new MemoryMetricsStore();
        $metrics = new Metrics($store);
        $metrics->recordRequest('healthz', 200, 0.01);
        $store->fail();

        self::assertFalse($metrics->flush());
        self::assertStringEndsWith("image_conversion_api_up 1\n# HELP image_conversion_api_metrics_available Whether the counters could be read from METRICS_STORE (0 when they are missing from this scrape).\n# TYPE image_conversion_api_metrics_available gauge\nimage_conversion_api_metrics_available 0\n", $metrics->render());
    }

    public function testWithoutAStoreNothingIsCounted(): void
    {
        $metrics = new Metrics(null);
        $metrics->recordRequest('healthz', 200, 0.01);

        self::assertTrue($metrics->flush());
        self::assertStringEndsWith("image_conversion_api_metrics_available 1\n", $metrics->render());
        self::assertStringNotContainsString('requests_total', $metrics->render());
    }

    public function testEscapesLabelValues(): void
    {
        $metrics = new Metrics(new MemoryMetricsStore());
        $metrics->recordConversion('v1', "a\"b\\c\nd", 0.0);
        $metrics->flush();

        self::assertStringContainsString('image_conversion_api_conversions_total{route="v1",outcome="a\\"b\\\\c\\nd"} 1', $metrics->render());
    }

    public function testANegativeDurationCountsAsZero(): void
    {
        $metrics = new Metrics(new MemoryMetricsStore());
        $metrics->recordRequest('healthz', 200, -1.0);
        $metrics->flush();

        self::assertStringContainsString("image_conversion_api_request_duration_seconds_bucket{route=\"healthz\",le=\"0.005\"} 1\n", $metrics->render());
        self::assertStringContainsString("image_conversion_api_request_duration_seconds_sum{route=\"healthz\"} 0\n", $metrics->render());
    }
}
