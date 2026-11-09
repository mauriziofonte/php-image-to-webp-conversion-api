<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Observability;

/**
 * The Prometheus metrics of the service, counted in a MetricsStoreInterface
 * shared by every PHP process and rendered in the text exposition format
 * 0.0.4 by /metrics:
 *
 * | Metric                                            | Type      | Labels          |
 * | ------------------------------------------------- | --------- | --------------- |
 * | image_conversion_api_up                           | gauge     |                 |
 * | image_conversion_api_metrics_available            | gauge     |                 |
 * | image_conversion_api_requests_total               | counter   | route, status   |
 * | image_conversion_api_request_duration_seconds     | histogram | route           |
 * | image_conversion_api_conversions_total            | counter   | route, outcome  |
 * | image_conversion_api_conversion_duration_seconds  | histogram | route           |
 *
 * - route: the route name (v2_convert, v1, healthz, metrics), or "none"
 *   when no route matched the path; status: the HTTP status.
 * - outcome: "converted", or the error code of the library that refused
 *   or failed the conversion (ErrorCode), or "not_webp" for a /v1 file
 *   whose output is not WEBP. Every label value comes from a fixed set, so
 *   the series cannot grow with the requests.
 * - conversion_duration_seconds measures the conversions that produced an
 *   output, from the converter's call to its return (the wait for a slot
 *   included).
 * - metrics_available is 0 when the store cannot be read: the counters are
 *   then missing from the scrape, not reset.
 *
 * The increments of one request are kept in this object and written to the
 * store in one update by flush(), which the kernel calls once the response
 * is ready. Without a store (METRICS_STORE=off), nothing is counted and
 * only the two gauges are rendered.
 */
final class Metrics
{
    public const PREFIX = 'image_conversion_api_';

    /**
     * Route label of a request whose path matched no route.
     */
    public const NO_ROUTE = 'none';

    /**
     * Outcome label of a conversion that produced an output.
     */
    public const CONVERTED = 'converted';

    /**
     * Upper bounds of the histogram buckets, in seconds: conversions take
     * tens of milliseconds to the whole CONVERSION_TIME_LIMIT.
     */
    public const BUCKETS = [0.005, 0.025, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0];

    private const FAMILIES = [
        'requests_total' => ['counter', 'Requests answered, by route and HTTP status.'],
        'request_duration_seconds' => ['histogram', 'Time from the start of the kernel to the response, by route.'],
        'conversions_total' => ['counter', 'Conversions attempted, by route and outcome (converted, or the error code).'],
        'conversion_duration_seconds' => ['histogram', 'Duration of the conversions that produced an output, by route.'],
    ];

    /**
     * Suffix of the counters that hold histogram sums, in microseconds.
     */
    private const SUM_SUFFIX = '_sum_us';

    /**
     * @var MetricsStoreInterface|null
     */
    private $store;

    /**
     * @var array<string, int>
     */
    private $pending = [];

    /**
     * @param MetricsStoreInterface|null $store null when nothing is counted (METRICS_STORE=off)
     */
    public function __construct(?MetricsStoreInterface $store)
    {
        $this->store = $store;
    }

    /**
     * Counts a request that was answered.
     *
     * @param string|null $route   the name of the route; null when no route matched
     * @param float       $seconds time the request took
     */
    public function recordRequest(?string $route, int $status, float $seconds): void
    {
        $route = $route ?? self::NO_ROUTE;
        $this->increment('requests_total', ['route' => $route, 'status' => (string) $status]);
        $this->observe('request_duration_seconds', ['route' => $route], $seconds);
    }

    /**
     * Counts a conversion.
     *
     * @param string $outcome CONVERTED, an error code of the library, or another code from a fixed set
     * @param float  $seconds time the conversion took; observed for CONVERTED only
     */
    public function recordConversion(string $route, string $outcome, float $seconds): void
    {
        $this->increment('conversions_total', ['route' => $route, 'outcome' => $outcome]);
        if ($outcome === self::CONVERTED) {
            $this->observe('conversion_duration_seconds', ['route' => $route], $seconds);
        }
    }

    /**
     * Writes what was recorded since the last flush to the store.
     *
     * @return bool false when the store could not be updated
     */
    public function flush(): bool
    {
        $pending = $this->pending;
        $this->pending = [];
        if ($this->store === null || $pending === []) {
            return true;
        }

        return $this->store->add($pending);
    }

    /**
     * The text exposition of every metric.
     */
    public function render(): string
    {
        $counters = $this->store !== null ? $this->store->read() : [];
        $lines = [
            '# HELP ' . self::PREFIX . 'up Whether the conversion API answers (always 1 in a scrape that succeeds).',
            '# TYPE ' . self::PREFIX . 'up gauge',
            self::PREFIX . 'up 1',
            '# HELP ' . self::PREFIX . 'metrics_available Whether the counters could be read from METRICS_STORE (0 when they are missing from this scrape).',
            '# TYPE ' . self::PREFIX . 'metrics_available gauge',
            self::PREFIX . 'metrics_available ' . ($counters === null ? '0' : '1'),
        ];
        if ($counters === null || $this->store === null) {
            return implode("\n", $lines) . "\n";
        }
        ksort($counters, SORT_STRING);
        foreach (self::FAMILIES as $family => list($type, $help)) {
            $name = self::PREFIX . $family;
            $samples = $type === 'counter' ? self::counterSamples($name, $counters) : self::histogramSamples($name, $counters);
            if ($samples === []) {
                continue;
            }
            $lines[] = '# HELP ' . $name . ' ' . $help;
            $lines[] = '# TYPE ' . $name . ' ' . $type;
            foreach ($samples as $sample) {
                $lines[] = $sample;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The samples of the counter family $name.
     *
     * @param array<string, int> $counters
     *
     * @return list<string>
     */
    private static function counterSamples(string $name, array $counters): array
    {
        $samples = [];
        foreach ($counters as $key => $value) {
            if (strpos($key, $name . '{') === 0) {
                $samples[] = $key . ' ' . $value;
            }
        }

        return $samples;
    }

    /**
     * The samples of the histogram family $name, for each label set: the
     * buckets in increasing order, then the sum and the count.
     *
     * @param array<string, int> $counters
     *
     * @return list<string>
     */
    private static function histogramSamples(string $name, array $counters): array
    {
        $samples = [];
        foreach ($counters as $key => $count) {
            if (strpos($key, $name . '_count{') !== 0) {
                continue;
            }
            $labels = (string) substr($key, strlen($name . '_count'));
            $open = (string) substr($labels, 0, -1) . ',le="';
            $bounds = [];
            foreach (self::BUCKETS as $bound) {
                $bounds[] = self::bound($bound);
            }
            $bounds[] = '+Inf';
            foreach ($bounds as $bound) {
                $bucket = $name . '_bucket' . $open . $bound . '"}';
                $samples[] = $bucket . ' ' . ($counters[$bucket] ?? 0);
            }
            $samples[] = $name . '_sum' . $labels . ' ' . self::seconds($counters[$name . self::SUM_SUFFIX . $labels] ?? 0);
            $samples[] = $key . ' ' . $count;
        }

        return $samples;
    }

    /**
     * Microseconds as seconds, in the shortest exact decimal form.
     */
    private static function seconds(int $microseconds): string
    {
        $text = sprintf('%d.%06d', intdiv($microseconds, 1000000), $microseconds % 1000000);

        return rtrim(rtrim($text, '0'), '.');
    }

    /**
     * @param array<string, string> $labels
     */
    private function increment(string $family, array $labels, int $amount = 1): void
    {
        $key = self::PREFIX . $family . self::labels($labels);
        $this->pending[$key] = ($this->pending[$key] ?? 0) + $amount;
    }

    /**
     * Counts one observation of a histogram: every bucket whose bound it
     * does not exceed, the +Inf bucket, the count and the sum.
     *
     * @param array<string, string> $labels
     */
    private function observe(string $family, array $labels, float $seconds): void
    {
        $seconds = max(0.0, $seconds);
        foreach (self::BUCKETS as $bound) {
            if ($seconds <= $bound) {
                $this->increment($family . '_bucket', $labels + ['le' => self::bound($bound)]);
            }
        }
        $this->increment($family . '_bucket', $labels + ['le' => '+Inf']);
        $this->increment($family . '_count', $labels);
        $this->increment($family . self::SUM_SUFFIX, $labels, (int) round($seconds * 1000000));
    }

    private static function bound(float $bound): string
    {
        return rtrim(rtrim(sprintf('%.3F', $bound), '0'), '.');
    }

    /**
     * The label set as the exposition format writes it, values escaped.
     *
     * @param array<string, string> $labels
     */
    private static function labels(array $labels): string
    {
        $pairs = [];
        foreach ($labels as $name => $value) {
            $pairs[] = $name . '="' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n']) . '"';
        }

        return '{' . implode(',', $pairs) . '}';
    }
}
