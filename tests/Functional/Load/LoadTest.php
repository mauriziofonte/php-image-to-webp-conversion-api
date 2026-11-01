<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\Load;

use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Http\BuiltinServer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The limits of API-6 under load, through the real front controller on
 * PHP's built-in web server (four workers where PHP supports
 * PHP_CLI_SERVER_WORKERS, 7.4 and later) and the real library:
 *
 * - concurrent requests of one key above its rate limit are refused with
 *   429 and Retry-After, and exactly the burst gets through, the bucket
 *   being shared by the server's processes;
 * - the memory a request takes does not grow with the size of its body,
 *   which is streamed to disk, nor with the number of requests.
 *
 * The bodies are no images, so that the library refuses them (415) after
 * reading them whole, whatever backends the host has.
 *
 * @coversNothing
 *
 * @group load
 */
#[CoversNothing]
#[Group('load')]
final class LoadTest extends TestCase
{
    private const KEY = 'load-test-key-0123456789';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var BuiltinServer|null
     */
    private $server;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        if ($this->server !== null) {
            $this->server->stop();
        }
        $this->directory->remove();
        parent::tear_down();
    }

    public function testRefusesConcurrentRequestsAboveTheRateLimit(): void
    {
        // One token a minute: none comes back while the test runs.
        $server = $this->start(['RATE_LIMIT' => '1', 'RATE_LIMIT_BURST' => '10', 'RATE_LIMIT_STORE' => 'file']);

        $responses = $server->requestAll(array_fill(0, 60, ['POST', '/v2/convert', $this->headers(), 'not an image']));

        $statuses = array_count_values(array_column($responses, 'status'));
        ksort($statuses);
        self::assertSame([415 => 10, 429 => 50], $statuses, $server->getOutput());
        foreach ($responses as $response) {
            if ($response['status'] === 429) {
                self::assertGreaterThanOrEqual(1, (int) ($response['headers']['retry-after'] ?? '0'));
                self::assertStringContainsString('"code":"rate_limited"', $response['body']);
            }
        }
        self::assertDoesNotMatchRegularExpression('/PHP (?:Fatal|Warning|Notice|Deprecated)/', $server->getOutput());
    }

    public function testMemoryDoesNotGrowWithTheBodyOrTheRequests(): void
    {
        $server = $this->start(['RATE_LIMIT' => '0', 'MAX_IMAGE_BYTES' => '33554432', 'MAX_BODY_BYTES' => '33554432']);
        $sizes = [1 => 20, 8 => 5, 24 => 5];
        // The first request of a server probes the host (isolation, backends) and fills the library's caches.
        $server->request('POST', '/v2/convert', $this->headers(), 'warm-up');
        $this->waitForLogLines(1);

        $peaks = [];
        foreach ($sizes as $mebibytes => $count) {
            $body = str_repeat('not an image ', (int) ($mebibytes * 1048576 / 13));
            for ($request = 0; $request < $count; ++$request) {
                $before = count($this->memoryLog());
                $response = $server->request('POST', '/v2/convert', $this->headers(), $body);
                self::assertSame(415, $response['status'], $response['body'] . $server->getOutput());
                $this->waitForLogLines($before + 1);
                $log = $this->memoryLog();
                $peaks[$mebibytes][] = (int) end($log);
            }
        }

        $smallest = min($peaks[1]);
        $largest = max(array_merge($peaks[1], $peaks[8], $peaks[24]));
        fwrite(STDERR, sprintf(
            "\nPeak memory per request: %.2f MiB to %.2f MiB for bodies of 1 to 24 MiB (%d requests)\n",
            $smallest / 1048576,
            $largest / 1048576,
            array_sum($sizes)
        ));
        self::assertLessThan(1048576, $largest - $smallest, 'a 24 MiB body costs less than 1 MiB more than a 1 MiB one');
        self::assertLessThan(1048576, max($peaks[1]) - min($peaks[1]), 'twenty requests in a row do not pile up memory');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Forwarded-Proto' => 'https', 'Authorization' => 'Bearer ' . self::KEY, 'Content-Type' => 'application/octet-stream'];
    }

    /**
     * @param array<string, string> $config
     */
    private function start(array $config): BuiltinServer
    {
        $public = dirname(__DIR__, 3) . '/public';
        $path = getenv('PATH');
        $this->server = BuiltinServer::start(
            $public,
            dirname(__DIR__, 2) . '/Fixtures/Http/memory-router.php',
            $config + [
                'TEMP_DIR' => $this->directory->path('temp'),
                'TRUSTED_PROXIES' => '127.0.0.1',
                'API_KEY' => self::KEY,
                'MEMORY_LOG' => $this->directory->path('memory.log'),
                'PHP_CLI_SERVER_WORKERS' => '4',
                'PATH' => is_string($path) ? $path : '/usr/bin:/bin',
            ],
            $this->directory->path('server.log')
        );

        return $this->server;
    }

    /**
     * @return list<string>
     */
    private function memoryLog(): array
    {
        $lines = @file($this->directory->path('memory.log'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? [] : $lines;
    }

    /**
     * The shutdown function of the router may run after the response was sent.
     */
    private function waitForLogLines(int $lines): void
    {
        $deadline = microtime(true) + 5.0;
        while (count($this->memoryLog()) < $lines && microtime(true) < $deadline) {
            usleep(5000);
        }
    }
}
