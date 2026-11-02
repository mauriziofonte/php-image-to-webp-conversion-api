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
 *   which is streamed to disk, nor with the number of requests;
 * - nor, on /v1, with the size and number of the outputs, whose base64 is
 *   streamed from disk into the JSON document.
 *
 * The /v2 bodies are no images, so that the library refuses them (415)
 * after reading them whole, whatever backends the host has. The /v1 case
 * converts with CopyingConverter, whose outputs are as large as the
 * inputs, through the kernel of KernelFactory (v1-memory-router.php).
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

    public function testV1MemoryDoesNotGrowWithTheOutputs(): void
    {
        $mebibyte = 1048576;
        $server = $this->start([
            'RATE_LIMIT' => '0',
            'V1_SCRIPT_NAME' => 'convert-now',
            'MAX_IMAGE_BYTES' => (string) (8 * $mebibyte),
            'MAX_BODY_BYTES' => (string) (64 * $mebibyte),
            'PHP_INI_SCAN_DIR' => $this->iniDirectory(['upload_max_filesize = 8M', 'post_max_size = 64M', 'max_file_uploads = 20']),
        ], 'v1-memory-router.php');
        // Batches of one 1 MiB file, then of four 4 MiB files: 16 MiB of outputs, 21 MiB of base64.
        $batches = ['small' => [1, 1, 5], 'large' => [4, 4, 3]];
        $server->request('POST', '/v1/convert-now', $this->multipartHeaders(), $this->batch(1, 1024));
        $this->waitForLogLines(1);

        $peaks = ['small' => [], 'large' => []];
        foreach ($batches as $batch => [$files, $mebibytes, $count]) {
            $body = $this->batch($files, $mebibytes * $mebibyte);
            for ($request = 0; $request < $count; ++$request) {
                $before = count($this->memoryLog());
                $response = $server->request('POST', '/v1/convert-now', $this->multipartHeaders(), $body);
                self::assertSame(200, $response['status'], substr($response['body'], 0, 500) . $server->getOutput());
                $document = json_decode($response['body'], true);
                self::assertIsArray($document);
                self::assertIsArray($document['response'] ?? null);
                self::assertCount($files, $document['response']);
                foreach ($document['response'] as $entry) {
                    self::assertIsArray($entry);
                    self::assertTrue($entry['status'] ?? null, (string) json_encode($entry));
                    self::assertIsString($entry['webp_image_base64'] ?? null);
                    self::assertSame(4 * intdiv($mebibytes * $mebibyte + 2, 3), strlen($entry['webp_image_base64']));
                }
                self::assertSame((string) strlen($response['body']), $response['headers']['content-length'] ?? null);
                $this->waitForLogLines($before + 1);
                $log = $this->memoryLog();
                $peaks[$batch][] = (int) end($log);
            }
        }

        self::assertNotSame([], $peaks['small']);
        $smallest = min($peaks['small']);
        $largest = max(array_merge($peaks['small'], $peaks['large']));
        fwrite(STDERR, sprintf(
            "\n/v1 peak memory per request: %.2f MiB to %.2f MiB for 1 MiB to 16 MiB of outputs (8 requests)\n",
            $smallest / $mebibyte,
            $largest / $mebibyte
        ));
        self::assertLessThan($mebibyte, $largest - $smallest, '16 MiB of outputs cost less than 1 MiB more than 1 MiB');
        self::assertDoesNotMatchRegularExpression('/PHP (?:Fatal|Warning|Notice|Deprecated)/', $server->getOutput());
    }

    /**
     * A multipart body of $files distinct files of $bytes bytes in "images[]".
     */
    private function batch(int $files, int $bytes): string
    {
        $body = '';
        for ($file = 0; $file < $files; ++$file) {
            $body .= "--load\r\nContent-Disposition: form-data; name=\"images[]\"; filename=\"image-$file.png\"\r\n"
                . "Content-Type: image/png\r\n\r\n" . str_repeat(chr(65 + $file % 26), $bytes) . "\r\n";
        }

        return $body . "--load--\r\n";
    }

    /**
     * @return array<string, string>
     */
    private function multipartHeaders(): array
    {
        return ['Content-Type' => 'multipart/form-data; boundary=load'] + $this->headers();
    }

    /**
     * PHP_INI_SCAN_DIR that adds a file with $lines to the directories PHP
     * already scans (an empty element stands for the compiled-in one).
     *
     * @param list<string> $lines
     */
    private function iniDirectory(array $lines): string
    {
        $directory = $this->directory->path('ini');
        mkdir($directory);
        file_put_contents($directory . '/load.ini', implode("\n", $lines) . "\n");
        $current = getenv('PHP_INI_SCAN_DIR');

        return (is_string($current) ? $current : '') . PATH_SEPARATOR . $directory;
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
    private function start(array $config, string $router = 'memory-router.php'): BuiltinServer
    {
        $public = dirname(__DIR__, 3) . '/public';
        $path = getenv('PATH');
        $this->server = BuiltinServer::start(
            $public,
            dirname(__DIR__, 2) . '/Fixtures/Http/' . $router,
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
