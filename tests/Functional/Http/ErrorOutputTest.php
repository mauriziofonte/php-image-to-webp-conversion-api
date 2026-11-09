<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\Http;

use Mfonte\ImageConversionApi\Http\FrontController;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Http\BuiltinServer;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Finding C-5: no PHP notice, warning, deprecation, fatal error message or
 * stray output can reach a response body. FrontController serves routes
 * that misbehave (tests/Fixtures/Http/noisy-router.php) in PHP's built-in
 * web server started with the worst php.ini for this purpose
 * (display_errors, html_errors, error_reporting E_ALL), and the routes even
 * turn display_errors back on: every body is exactly the response, and
 * every error is in the JSON log with the request ID.
 *
 * @covers \Mfonte\ImageConversionApi\Http\FrontController
 */
#[CoversClass(FrontController::class)]
final class ErrorOutputTest extends TestCase
{
    private const INTERNAL_ERROR = '{"error":{"code":"internal_error","message":"The request could not be processed."}}';

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
        $root = dirname(__DIR__, 3);
        $path = getenv('PATH');
        $this->server = BuiltinServer::start(
            $root . '/public',
            $root . '/tests/Fixtures/Http/noisy-router.php',
            [
                'TEMP_DIR' => $this->directory->path('temp'),
                'API_KEY' => 'error-output-key-0123456789',
                'LOG_FILE' => $this->directory->path('api.log'),
                'LOG_LEVEL' => 'debug',
                'PATH' => is_string($path) ? $path : '/usr/bin:/bin',
            ],
            $this->directory->path('server.log'),
            ['display_errors' => '1', 'display_startup_errors' => '1', 'html_errors' => '1', 'error_reporting' => '-1']
        );
    }

    protected function tear_down(): void
    {
        if ($this->server !== null) {
            $this->server->stop();
        }
        $this->directory->remove();
        parent::tear_down();
    }

    public function testNoticesWarningsDeprecationsAndStrayOutputAreLoggedNotSent(): void
    {
        $response = $this->server()->request('GET', '/noisy', ['X-Request-Id' => 'proxy-noisy-1']);

        self::assertSame(200, $response['status'], $this->server()->getOutput());
        self::assertSame('{"ok":true}', $response['body']);
        self::assertSame('proxy-noisy-1', $response['headers']['x-request-id'] ?? null);

        $log = $this->logEntries('proxy-noisy-1');
        $messages = array_column($log, 'message');
        self::assertContains('PHP E_USER_NOTICE: a probe notice', $messages);
        self::assertContains('PHP E_USER_WARNING: a probe warning', $messages);
        self::assertContains('PHP E_USER_DEPRECATED: a probe deprecation', $messages);
        self::assertCount(1, preg_grep('#\APHP E_WARNING: file_get_contents\(/nonexistent/noisy-router/missing\): [Ff]ailed to open stream#', $messages) ?: []);
        self::assertContains('Discarded 12 bytes of unexpected output', $messages);
        self::assertSame([], preg_grep('/silenced/', $messages) ?: [], 'an error silenced with "@" is not logged');
        self::assertStringNotContainsString('probe', $this->server()->getOutput(), 'nothing goes to PHP\'s own error log either');
    }

    public function testAFatalErrorIsAGeneric500WithoutItsMessage(): void
    {
        $response = $this->server()->request('GET', '/fatal', ['X-Request-Id' => 'proxy-fatal-1']);

        self::assertSame(500, $response['status'], $this->server()->getOutput());
        self::assertSame(self::INTERNAL_ERROR, $response['body']);
        self::assertSame('proxy-fatal-1', $response['headers']['x-request-id'] ?? null);
        self::assertSame('application/json', $response['headers']['content-type'] ?? null);

        $fatal = array_values(array_filter($this->logEntries('proxy-fatal-1'), static function (array $entry): bool {
            return $entry['level'] === 'critical';
        }));
        self::assertCount(1, $fatal);
        self::assertMatchesRegularExpression('/\APHP E_COMPILE_ERROR: Cannot (?:re)?declare (?:final )?class NoisyRouterTwice/', $fatal[0]['message']);
    }

    public function testAnExceptionIsAGeneric500(): void
    {
        $response = $this->server()->request('GET', '/exception');

        self::assertSame(500, $response['status'], $this->server()->getOutput());
        self::assertSame(self::INTERNAL_ERROR, $response['body']);
        self::assertStringNotContainsString('a probe exception', $response['body']);
    }

    public function testAWarningWhileTheBodyStreamsIsLoggedNotSent(): void
    {
        $response = $this->server()->request('GET', '/stream', ['X-Request-Id' => 'proxy-stream-1']);

        self::assertSame(200, $response['status'], $this->server()->getOutput());
        self::assertSame('streamed', $response['body']);
        self::assertCount(1, preg_grep('#\APHP E_WARNING: file_get_contents\(/nonexistent/noisy-router/streamed\)#', array_column($this->logEntries('proxy-stream-1'), 'message')) ?: []);
    }

    private function server(): BuiltinServer
    {
        self::assertNotNull($this->server);

        return $this->server;
    }

    /**
     * The entries of the JSON log that carry $requestId.
     *
     * @return list<array{level: string, message: string}>
     */
    private function logEntries(string $requestId): array
    {
        $lines = file($this->directory->path('api.log'), FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, 'the JSON log was written');
        $entries = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            self::assertIsArray($entry, 'every line is JSON: ' . $line);
            self::assertIsString($entry['level'] ?? null);
            self::assertIsString($entry['message'] ?? null);
            if (($entry['request_id'] ?? null) === $requestId) {
                $entries[] = ['level' => $entry['level'], 'message' => $entry['message']];
            }
        }

        return $entries;
    }
}
