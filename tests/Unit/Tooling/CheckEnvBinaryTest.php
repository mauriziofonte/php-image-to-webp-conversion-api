<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Tooling;

use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The Composer install hooks run "bin/image-conversion-api check-env
 * --informational", so that "composer install" never fails because the
 * service is not configured yet, while "composer check-env" fails on every
 * error.
 *
 * The binary is copied into a temporary project and run in a clean
 * environment, so that a developer's .env or exported variables never
 * influence the result.
 *
 * @coversNothing
 */
#[CoversNothing]
final class CheckEnvBinaryTest extends TestCase
{
    private const API_KEY = 'check-env-binary-key-0123456789';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var string
     */
    private $project;

    protected function set_up(): void
    {
        parent::set_up();
        $root = dirname(__DIR__, 3);
        $this->directory = new TemporaryDirectory();
        $this->project = $this->directory->path('project');

        self::assertTrue(mkdir($this->project . '/bin', 0777, true));
        self::assertTrue(mkdir($this->project . '/vendor'));
        self::assertTrue(mkdir($this->directory->path('temp')));
        self::assertTrue(copy($root . '/bin/image-conversion-api', $this->project . '/bin/image-conversion-api'));
        self::assertNotFalse(file_put_contents(
            $this->project . '/vendor/autoload.php',
            "<?php\n\nreturn require " . var_export($root . '/vendor/autoload.php', true) . ";\n"
        ));
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testInformationalRunSucceedsWithoutConfiguration(): void
    {
        list($exitCode, $output) = $this->runBinary(['--informational']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('no configuration yet', $output);
        self::assertStringContainsString('composer check-env', $output);
    }

    public function testExplicitRunFailsWithoutConfiguration(): void
    {
        list($exitCode, $output) = $this->runBinary([]);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString('Environment check failed', $output);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideInvalidConfigurations(): array
    {
        return [
            'unparsable file' => ["INVALID KEY=value\n", 'The .env file cannot be parsed'],
            'invalid value' => ['API_KEY="' . self::API_KEY . "\"\nRATE_LIMIT=\"many\"\n", 'RATE_LIMIT'],
        ];
    }

    /**
     * @dataProvider provideInvalidConfigurations
     */
    #[DataProvider('provideInvalidConfigurations')]
    public function testInformationalRunReportsErrorsWithoutFailing(string $env, string $expectedError): void
    {
        $this->writeEnv($env);

        list($exitCode, $output) = $this->runBinary(['--informational']);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString($expectedError, $output);
    }

    /**
     * @dataProvider provideInvalidConfigurations
     */
    #[DataProvider('provideInvalidConfigurations')]
    public function testExplicitRunFailsOnErrors(string $env, string $expectedError): void
    {
        $this->writeEnv($env);

        list($exitCode, $output) = $this->runBinary([]);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString($expectedError, $output);
    }

    /**
     * The environment is read first and is enough without .env; the host
     * is then checked with the real converter, whose result depends on the
     * backends of this host.
     */
    public function testTheEnvironmentWinsAndTheHostIsChecked(): void
    {
        $this->writeEnv('API_KEY="' . self::API_KEY . "\"\nRATE_LIMIT=\"many\"\n");

        list($exitCode, $output) = $this->runBinary([], [
            'RATE_LIMIT' => '60',
            'TEMP_DIR' => $this->directory->path('temp'),
            'LOCK_DIR' => $this->directory->path('locks'),
        ]);

        self::assertStringContainsString('Converter: isolation ', $output);
        self::assertStringNotContainsString('RATE_LIMIT', $output);
        self::assertSame(strpos($output, '✗') === false ? 0 : 1, $exitCode, $output);
        self::assertSame(['project', 'temp'], $this->directory->entries(), 'nothing is created');
    }

    /**
     * @return array<string, array{list<string>, int}>
     */
    public static function provideRunsWithoutDependencies(): array
    {
        return [
            'informational' => [['--informational'], 0],
            'explicit' => [[], 1],
        ];
    }

    /**
     * @dataProvider provideRunsWithoutDependencies
     *
     * @param list<string> $arguments
     */
    #[DataProvider('provideRunsWithoutDependencies')]
    public function testMissingDependenciesAreReported(array $arguments, int $expectedExitCode): void
    {
        self::assertTrue(unlink($this->project . '/vendor/autoload.php'));

        list($exitCode, $output) = $this->runBinary($arguments);

        self::assertSame($expectedExitCode, $exitCode, $output);
        self::assertStringContainsString('Dependencies are missing. Run: composer install', $output);
    }

    /**
     * Composer's platform check throws when vendor/ was resolved for another
     * PHP version; the install hooks must still succeed.
     *
     * @dataProvider provideRunsWithoutDependencies
     *
     * @param list<string> $arguments
     */
    #[DataProvider('provideRunsWithoutDependencies')]
    public function testUnloadableDependenciesAreReported(array $arguments, int $expectedExitCode): void
    {
        self::assertNotFalse(file_put_contents(
            $this->project . '/vendor/autoload.php',
            "<?php\n\nthrow new \\RuntimeException('Composer detected issues in your platform: PHP too old');\n"
        ));

        list($exitCode, $output) = $this->runBinary($arguments);

        self::assertSame($expectedExitCode, $exitCode, $output);
        self::assertStringContainsString('The dependencies cannot be loaded: Composer detected issues in your platform: PHP too old', $output);
    }

    private function writeEnv(string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->project . '/.env', $contents));
    }

    /**
     * Runs "bin/image-conversion-api check-env" of the temporary project
     * with PHP_BINARY in an environment that only carries PATH and
     * $environment, and returns its exit code and combined output.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     *
     * @return array{int, string}
     */
    private function runBinary(array $arguments, array $environment = []): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->project . '/bin/image-conversion-api') . ' check-env';
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $path = getenv('PATH');
        $process = proc_open(
            $command . ' 2>&1',
            [1 => ['pipe', 'w']],
            $pipes,
            $this->project,
            array_merge(['PATH' => is_string($path) ? $path : '/usr/bin:/bin', 'HOME' => $this->directory->path()], $environment)
        );
        self::assertIsResource($process);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return [$exitCode, is_string($output) ? $output : ''];
    }
}
