<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Integration;

use Mfonte\ImageConversionApi\Http\V1Deprecation;
use Mfonte\ImageConversionApi\Tests\Fixtures\Http\HttpClient;
use Mfonte\ImageConversionApi\Tests\Fixtures\Image\Png;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The API behind real web servers, as tests/Integration/docker/run.sh
 * starts them (official images, the shipped .htaccess files and nginx
 * configuration), with a php.ini that prints every error:
 *
 * - apache: public/ is the document root (public/.htaccess);
 * - apache-subdir: the installation directory is served as
 *   /webp-converter/ (the root .htaccess), as 1.x was installed;
 * - nginx: deploy/nginx/image-conversion-api.conf over TLS;
 * - image: docker/compose.yaml, the hardened image behind nginx.
 *
 * Skipped unless run.sh passes the addresses (ICA_INTEGRATION_*).
 *
 * @coversNothing
 *
 * @group integration
 */
#[CoversNothing]
#[Group('integration')]
final class WebServerTest extends TestCase
{
    /**
     * Text PHP prints for an error when display_errors is on.
     */
    private const PHP_NOISE = '/(?:<br\s*\/?>|<b>|\b(?:Warning|Notice|Deprecated|Fatal error|Parse error|Stack trace)\b)/i';

    /**
     * Files of the installation, by their path below the base URL.
     */
    private const INSTALLATION_FILES = [
        '/.env',
        '/.env.example',
        '/.htaccess',
        '/composer.json',
        '/README.md',
        '/bin/image-conversion-api',
        '/public/.htaccess',
        '/public/index.php',
        '/src/Http/Kernel.php',
        '/vendor/autoload.php',
        '/vendor/composer/installed.json',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function provideTargets(): array
    {
        $targets = [];
        foreach (['apache', 'apache-subdir', 'nginx', 'image'] as $name) {
            if (self::env(self::variable($name, 'URL')) !== null) {
                $targets[$name] = [$name];
            }
        }

        return $targets !== [] ? $targets : ['not configured' => ['']];
    }

    /**
     * @dataProvider provideTargets
     */
    #[DataProvider('provideTargets')]
    public function testHealthzIsPublicAndEchoesTheRequestId(string $target): void
    {
        $id = 'integration-' . bin2hex(random_bytes(6));

        $response = $this->send($target, 'GET', '/healthz', ['X-Request-Id' => $id], '', false);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame('{"status":"ok"}', $response['body']);
        self::assertSame([$id], $response['headers']['x-request-id'] ?? null);
        self::assertSame(['nosniff'], $response['headers']['x-content-type-options'] ?? null, 'sent once');
    }

    /**
     * The web server passes the Authorization header to PHP-FPM: a Bearer
     * key opens the capabilities of /healthz.
     *
     * @dataProvider provideTargets
     */
    #[DataProvider('provideTargets')]
    public function testTheKeyReachesTheApi(string $target): void
    {
        foreach (['Authorization' => 'Bearer ' . self::apiKey(), 'X-API-Key' => self::apiKey()] as $header => $value) {
            $response = $this->send($target, 'GET', '/healthz', [$header => $value]);

            self::assertSame(200, $response['status'], $header . ': ' . $response['body']);
            $data = json_decode($response['body'], true);
            self::assertIsArray($data);
            self::assertSame('ok', $data['status'] ?? null);
            self::assertIsArray($data['capabilities'] ?? null, $header);
        }

        $wrong = $this->send($target, 'GET', '/healthz', ['Authorization' => 'Bearer wrong-key-0123456789abcdef']);
        self::assertSame(401, $wrong['status']);
    }

    /**
     * @dataProvider provideTargets
     */
    #[DataProvider('provideTargets')]
    public function testMetricsAnswersTheScraperKey(string $target): void
    {
        $this->send($target, 'GET', '/healthz', [], '', false);

        $response = $this->send($target, 'GET', '/metrics', ['Authorization' => 'Bearer ' . self::metricsKey()]);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringStartsWith('text/plain; version=0.0.4', ($response['headers']['content-type'] ?? [''])[0]);
        self::assertStringContainsString("image_conversion_api_up 1\n", $response['body']);
        self::assertStringContainsString('image_conversion_api_requests_total{route="healthz",status="200"}', $response['body']);

        $convert = $this->send($target, 'POST', '/v2/convert', ['Authorization' => 'Bearer ' . self::metricsKey(), 'Content-Type' => 'image/png'], Png::gradient(8, 8));
        self::assertSame(401, $convert['status'], 'a metrics key does not convert');
    }

    /**
     * Whatever the path, the web server hands the request to the API, which
     * answers 404: no file of the installation is ever served.
     *
     * @dataProvider provideTargets
     */
    #[DataProvider('provideTargets')]
    public function testNoFileOfTheInstallationIsServed(string $target): void
    {
        foreach (self::INSTALLATION_FILES as $path) {
            foreach ([[], ['X-API-Key' => self::apiKey()]] as $headers) {
                $response = $this->send($target, 'GET', $path, $headers);

                self::assertContains($response['status'], [401, 404], $path . ': ' . $response['body']);
                self::assertSame(['application/json'], $response['headers']['content-type'] ?? null, $path);
                foreach (['<?php', 'API_KEY', 'RewriteEngine', '"packages"'] as $leak) {
                    self::assertStringNotContainsString($leak, $response['body'], $path);
                }
            }
        }
    }

    /**
     * The php.ini of the harness prints every error into the response; the
     * API answers its own bodies only, for a refused image, a refused
     * parameter, an unknown path and the deprecated endpoint.
     *
     * @dataProvider provideTargets
     */
    #[DataProvider('provideTargets')]
    public function testNoPhpErrorReachesABody(string $target): void
    {
        $key = ['X-API-Key' => self::apiKey()];
        $responses = [
            $this->send($target, 'POST', '/v2/convert', $key + ['Content-Type' => 'image/png'], 'not an image at all'),
            $this->send($target, 'POST', '/v2/convert?quality=999', $key + ['Content-Type' => 'image/png'], Png::gradient(4, 4)),
            $this->send($target, 'POST', '/v2/convert', $key + ['Content-Type' => 'application/x-www-form-urlencoded'], 'a=1'),
            $this->send($target, 'GET', '/v2/convert', $key),
            $this->send($target, 'POST', '/v2/convert', $key + ['Content-Type' => 'image/png'], Png::gradient(48, 32)),
        ];
        list($multipart, $type) = HttpClient::multipart(['descriptors' => '{not json'], []);
        $responses[] = $this->send($target, 'POST', '/v1/' . self::v1ScriptName(), $key + ['Content-Type' => $type], $multipart);

        foreach ($responses as $index => $response) {
            self::assertDoesNotMatchRegularExpression(self::PHP_NOISE, $response['body'], 'response ' . $index);
            $type = ($response['headers']['content-type'] ?? [''])[0];
            if (strpos($type, 'application/json') === 0) {
                self::assertIsArray(json_decode($response['body'], true), 'response ' . $index . ': ' . $response['body']);
            } else {
                self::assertSame(200, $response['status'], 'response ' . $index . ' is an image');
                self::assertStringStartsWith('image/', $type);
            }
        }
        self::assertSame(415, $responses[0]['status'], $responses[0]['body']);
        self::assertSame(400, $responses[1]['status']);
        self::assertSame(405, $responses[3]['status']);
    }

    /**
     * @dataProvider provideTargets
     */
    #[DataProvider('provideTargets')]
    public function testTheDeprecatedEndpointAnswersBehindTheWebServer(string $target): void
    {
        list($body, $type) = HttpClient::multipart(['m' => '4'], []);

        $response = $this->send($target, 'POST', '/v1/' . self::v1ScriptName(), ['X-API-Key' => self::apiKey(), 'Content-Type' => $type], $body);

        self::assertSame(400, $response['status'], $response['body']);
        self::assertSame('{"status":false,"version":"1.0.0","message":"No images uploaded."}', $response['body']);
        self::assertSame(['@' . V1Deprecation::DEPRECATED_AT], $response['headers']['deprecation'] ?? null);
    }

    /**
     * Every request ends with one JSON log line that carries the request ID
     * the client (the proxy) sent.
     *
     * @dataProvider provideTargets
     */
    #[DataProvider('provideTargets')]
    public function testTheLogCarriesTheRequestIdOfTheClient(string $target): void
    {
        $log = self::env(self::variable($target, 'LOG'));
        if ($log === null) {
            self::markTestSkipped('The log of ' . $target . ' is checked by run.sh.');
        }
        $id = 'proxy-' . bin2hex(random_bytes(6));

        $this->send($target, 'POST', '/v2/convert', ['X-API-Key' => self::apiKey(), 'X-Request-Id' => $id, 'Content-Type' => 'image/png'], 'not an image');

        $entries = [];
        $deadline = microtime(true) + 5;
        while (true) {
            foreach (file($log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry) && ($entry['request_id'] ?? null) === $id) {
                    $entries[] = $entry;
                }
            }
            if ($entries !== [] || microtime(true) > $deadline) {
                break;
            }
            usleep(100000);
        }

        self::assertNotSame([], $entries, 'no log line carries ' . $id);
        $messages = array_column($entries, 'message');
        self::assertContains('Request handled', $messages);
        foreach ($entries as $entry) {
            self::assertSame(['timestamp', 'level', 'channel', 'request_id', 'message', 'context'], array_keys($entry));
        }
    }

    /**
     * Over plain HTTP without a trusted proxy saying otherwise, protected
     * paths are refused (Apache), or redirected to HTTPS by nginx.
     *
     * @dataProvider provideTargets
     */
    #[DataProvider('provideTargets')]
    public function testPlainHttpIsRefused(string $target): void
    {
        if ($target === 'nginx' || $target === 'image') {
            $http = self::env(self::variable($target, 'HTTP_URL'));
            if ($http === null) {
                self::markTestSkipped('No plain HTTP port for ' . $target . '.');
            }
            $response = (new HttpClient())->request('GET', $http . '/v2/convert');
            self::assertSame(301, $response['status']);
            self::assertStringStartsWith('https://', ($response['headers']['location'] ?? [''])[0]);

            return;
        }
        if ($target === '') {
            self::markTestSkipped('Run through tests/Integration/docker/run.sh.');
        }

        $response = $this->send($target, 'POST', '/v2/convert', ['X-API-Key' => self::apiKey(), 'Content-Type' => 'image/png'], Png::gradient(4, 4), false);

        self::assertSame(400, $response['status'], $response['body']);
        self::assertStringContainsString('"https_required"', $response['body']);
    }

    /**
     * A body above MAX_BODY_BYTES (21 MiB) is refused before it is
     * converted, by nginx (client_max_body_size) or by the API.
     *
     * @dataProvider provideTargets
     */
    #[DataProvider('provideTargets')]
    public function testABodyAboveTheLimitIsRefused(string $target): void
    {
        $response = $this->send($target, 'POST', '/v2/convert', ['X-API-Key' => self::apiKey(), 'Content-Type' => 'image/png'], str_repeat("\0", 23 * 1024 * 1024));

        self::assertSame(413, $response['status']);
        self::assertDoesNotMatchRegularExpression(self::PHP_NOISE, $response['body']);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    private function send(string $target, string $method, string $path, array $headers = [], string $body = '', bool $secure = true): array
    {
        $url = self::env(self::variable($target, 'URL'));
        if ($url === null) {
            self::markTestSkipped('Run through tests/Integration/docker/run.sh.');
        }
        if ($secure && strpos($url, 'http://') === 0) {
            // Apache speaks plain HTTP in the harness, behind a "trusted proxy".
            $headers += ['X-Forwarded-Proto' => 'https'];
        }
        $client = strpos($url, 'https://') === 0
            ? new HttpClient(self::env('ICA_INTEGRATION_CA_FILE'), self::env('ICA_INTEGRATION_SERVER_NAME'))
            : new HttpClient();

        return $client->request($method, $url . $path, $headers, $body);
    }

    private static function variable(string $target, string $suffix): string
    {
        return 'ICA_INTEGRATION_' . strtoupper(str_replace('-', '_', $target)) . '_' . $suffix;
    }

    private static function apiKey(): string
    {
        return self::env('ICA_INTEGRATION_API_KEY') ?? 'integration-api-key-0123456789';
    }

    private static function metricsKey(): string
    {
        return self::env('ICA_INTEGRATION_METRICS_KEY') ?? 'integration-metrics-key-0123456789';
    }

    private static function v1ScriptName(): string
    {
        return self::env('ICA_INTEGRATION_V1_SCRIPT_NAME') ?? 'convert-now';
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
