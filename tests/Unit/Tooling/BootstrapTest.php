<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * tests/bootstrap.php installs the strict error handler before it requires
 * the Composer autoloader. The bootstrap is copied into a temporary project
 * whose vendor/autoload.php raises deprecations, and run on its own: a
 * deprecation raised inside vendor/ is reported at shutdown, one raised by a
 * project file that the autoloader loads (a "files" entry) is fatal.
 *
 * @coversNothing
 */
#[CoversNothing]
final class BootstrapTest extends TestCase
{
    private const FILES = [
        '/tests/bootstrap.php',
        '/tests/StrictErrorHandler.php',
        '/vendor/autoload.php',
        '/src/functions.php',
    ];

    private const DIRECTORIES = ['/tests', '/vendor', '/src', ''];

    /**
     * @var string
     */
    private $project;

    protected function set_up(): void
    {
        parent::set_up();

        $root = dirname(__DIR__, 3);
        $this->project = sys_get_temp_dir() . '/bootstrap-test-' . bin2hex(random_bytes(6));

        foreach (['/tests', '/vendor', '/src'] as $directory) {
            self::assertTrue(mkdir($this->project . $directory, 0777, true));
        }
        foreach (['/tests/bootstrap.php', '/tests/StrictErrorHandler.php'] as $file) {
            self::assertTrue(copy($root . $file, $this->project . $file));
        }
    }

    protected function tear_down(): void
    {
        foreach (self::FILES as $file) {
            if (is_file($this->project . $file)) {
                unlink($this->project . $file);
            }
        }
        foreach (self::DIRECTORIES as $directory) {
            if (is_dir($this->project . $directory)) {
                rmdir($this->project . $directory);
            }
        }

        parent::tear_down();
    }

    public function testVendorDeprecationsRaisedWhileAutoloadingAreReportedAtShutdown(): void
    {
        $this->writeFile('/vendor/autoload.php', "trigger_error('Deprecated while autoloading', E_USER_DEPRECATED);\n");

        list($exitCode, $output) = $this->runBootstrap();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('1 distinct deprecation(s) raised inside vendor/ (not fatal):', $output);
        self::assertStringContainsString('[1x] Deprecated while autoloading in ', $output);
        self::assertStringContainsString('/vendor/autoload.php:3', $output);
    }

    public function testProjectDeprecationsRaisedWhileAutoloadingAreFatal(): void
    {
        $this->writeFile('/src/functions.php', "trigger_error('Deprecated in a files entry', E_USER_DEPRECATED);\n");
        $this->writeFile('/vendor/autoload.php', "require __DIR__ . '/../src/functions.php';\n");

        list($exitCode, $output) = $this->runBootstrap();

        self::assertSame(255, $exitCode, $output);
        self::assertStringContainsString('Uncaught ErrorException: Deprecated in a files entry', $output);
    }

    private function writeFile(string $file, string $code): void
    {
        self::assertNotFalse(file_put_contents($this->project . $file, "<?php\n\n" . $code));
    }

    /**
     * Runs the copied bootstrap with PHP_BINARY in an environment that only
     * carries PATH, and returns its exit code and combined output.
     *
     * @return array{int, string}
     */
    private function runBootstrap(): array
    {
        $command = escapeshellarg(PHP_BINARY)
            . ' -d display_errors=1 -d log_errors=0 -d error_reporting=-1 '
            . escapeshellarg($this->project . '/tests/bootstrap.php');

        $path = getenv('PATH');
        $process = proc_open(
            $command . ' 2>&1',
            [1 => ['pipe', 'w']],
            $pipes,
            $this->project,
            ['PATH' => is_string($path) ? $path : '/usr/bin:/bin']
        );
        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return [$exitCode, is_string($output) ? $output : ''];
    }
}
