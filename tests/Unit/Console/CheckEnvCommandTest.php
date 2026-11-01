<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Console;

use Mfonte\ImageConversionApi\Config\ConfigLoader;
use Mfonte\ImageConversionApi\Console\CheckEnvCommand;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\IsolationUnavailableException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The command with a scripted environment, php.ini and converter.
 *
 * @covers \Mfonte\ImageConversionApi\Console\CheckEnvCommand
 */
#[CoversClass(CheckEnvCommand::class)]
final class CheckEnvCommandTest extends TestCase
{
    private const API_KEY = 'check-env-test-key-0123456789';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var array<string, string>
     */
    private $environment = [];

    /**
     * @var array<string, string>
     */
    private $ini = [];

    /**
     * @var list<string>
     */
    private $extensions = ['posix'];

    /**
     * @var Capabilities|\Throwable
     */
    private $capabilities;

    /**
     * @var int
     */
    private $probes = 0;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        mkdir($this->directory->path('temp'));
        $this->environment = [
            'API_KEY' => self::API_KEY,
            'TEMP_DIR' => $this->directory->path('temp'),
            'LOCK_DIR' => $this->directory->path('run/locks'),
        ];
        $this->ini = ['post_max_size' => '64M', 'upload_max_filesize' => '64M', 'max_file_uploads' => '20'];
        $this->extensions = ['posix'];
        $this->capabilities = self::capabilities(IsolationLevel::SANDBOX, [ImageFormat::WEBP]);
        $this->probes = 0;
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testTheNameAndTheOption(): void
    {
        $command = $this->command();

        self::assertSame('check-env', $command->getName());
        self::assertTrue($command->getDefinition()->hasOption('informational'));
    }

    public function testAValidHostPasses(): void
    {
        list($exitCode, $output) = $this->check();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('Converter: isolation sandbox; backends cwebp; WEBP output yes.', $output);
        self::assertStringContainsString('✓ Environment check passed.', $output);
        self::assertSame(1, $this->probes);
    }

    /**
     * Directories are checked, never created: the command may run as
     * another user than PHP-FPM.
     */
    public function testCreatesNothing(): void
    {
        $this->environment['CONVERTER_CACHE_DIR'] = $this->directory->path('run/cache');

        list($exitCode, $output) = $this->check();

        self::assertSame(0, $exitCode, $output);
        self::assertSame(['temp'], $this->directory->entries());
        self::assertSame([], $this->directory->entries('temp'));
    }

    public function testWithoutAnyConfigurationTheInformationalRunOnlyNotes(): void
    {
        $this->environment = [];

        list($exitCode, $output) = $this->check(['--informational' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('NOTE: no configuration yet', $output);
        self::assertSame(0, $this->probes);
    }

    public function testWithoutAnyConfigurationTheExplicitRunFails(): void
    {
        $this->environment = [];

        list($exitCode, $output) = $this->check();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Environment check failed: no configuration', $output);
    }

    public function testAnEnvFileIsEnough(): void
    {
        $env = $this->directory->path('.env');
        file_put_contents($env, sprintf("API_KEY=\"%s\"\nTEMP_DIR=\"%s\"\n", self::API_KEY, $this->directory->path('temp')));
        $this->environment = [];

        list($exitCode, $output) = $this->check([], $env);

        self::assertSame(0, $exitCode, $output);
    }

    public function testInvalidValuesAreListedAndFail(): void
    {
        $this->environment['RATE_LIMIT'] = 'many';
        $this->environment['METRICS_KEYS'] = 'tiny-key';

        list($exitCode, $output) = $this->check();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('✗ Environment check failed:', $output);
        self::assertStringContainsString('RATE_LIMIT', $output);
        self::assertStringContainsString('METRICS_KEYS', $output);
        self::assertStringNotContainsString('tiny-key', $output, 'a key is never printed');
        self::assertSame(0, $this->probes, 'the host is not checked with an invalid configuration');
    }

    public function testTheInformationalRunReportsErrorsWithoutFailing(): void
    {
        $this->environment['RATE_LIMIT'] = 'many';

        list($exitCode, $output) = $this->check(['--informational' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('RATE_LIMIT', $output);
        self::assertStringContainsString('run "composer check-env"', $output);
    }

    public function testAnApiKeyIsRequired(): void
    {
        unset($this->environment['API_KEY']);

        list($exitCode, $output) = $this->check();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No API key is configured', $output);
    }

    public function testTheDirectoriesMustBeUsable(): void
    {
        file_put_contents($this->directory->path('a-file'), '');
        $this->environment['LOCK_DIR'] = $this->directory->path('a-file/locks');
        $this->environment['CONVERTER_CACHE_DIR'] = $this->directory->path('a-file');
        $this->environment['LOG_FILE'] = $this->directory->path('missing/api.log');

        list($exitCode, $output) = $this->check();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('LOCK_DIR ' . $this->directory->path('a-file/locks') . ' does not exist and cannot be created', $output);
        self::assertStringContainsString('CONVERTER_CACHE_DIR ' . $this->directory->path('a-file') . ' is not a directory', $output);
        self::assertStringContainsString('LOG_FILE ' . $this->directory->path('missing/api.log') . ' cannot be written', $output);
    }

    public function testAConverterThatCannotBeBuiltFails(): void
    {
        $this->capabilities = new IsolationUnavailableException('bwrap cannot create a user namespace.');

        list($exitCode, $output) = $this->check();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('The converter cannot be built (isolation_unavailable): bwrap cannot create a user namespace.', $output);
    }

    public function testAConverterWithoutWebpFails(): void
    {
        $this->capabilities = self::capabilities(IsolationLevel::NONE, [ImageFormat::JPEG]);

        list($exitCode, $output) = $this->check();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No backend of this host writes WEBP', $output);
        self::assertStringContainsString('without isolation', $output);
    }

    public function testWarnsAboutTheHost(): void
    {
        $this->environment['LOCK_DIR'] = '/tmp/image-conversion-api.check-env-test';
        $this->environment['REQUIRE_HTTPS'] = 'false';
        $this->environment['V1_SCRIPT_NAME'] = 'convert-now';
        $this->extensions = [];
        $this->ini = ['post_max_size' => '8M', 'upload_max_filesize' => '2M', 'max_file_uploads' => '5'];

        list($exitCode, $output) = $this->check();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('ext-posix is missing', $output);
        self::assertStringContainsString('systemd-tmpfiles', $output);
        self::assertStringContainsString('/run/image-conversion-api', $output);
        self::assertStringContainsString('REQUIRE_HTTPS is off', $output);
        self::assertStringContainsString('post_max_size is 8M', $output);
        self::assertStringContainsString('upload_max_filesize is 2M', $output);
        self::assertStringContainsString('max_file_uploads is 5', $output);
        self::assertStringContainsString('✓ Environment check passed.', $output);
    }

    /**
     * Without the sandbox, a conversion runs as the PHP user and can read
     * the keys from the environment: digests are recommended.
     */
    public function testWarnsAboutPlainKeysWithoutTheSandbox(): void
    {
        $this->capabilities = self::capabilities(IsolationLevel::RLIMIT, [ImageFormat::WEBP]);
        $this->environment['METRICS_KEYS'] = 'scraper-key-0123456789';

        list($exitCode, $output) = $this->check();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('2 key(s) of API_KEY, API_KEYS and METRICS_KEYS are configured as themselves', $output);
        self::assertStringContainsString('without the sandbox isolation (rlimit)', $output);
        self::assertStringNotContainsString(self::API_KEY, $output);
        self::assertStringNotContainsString('scraper-key-0123456789', $output);

        $this->environment['API_KEY'] = 'sha256:' . hash('sha256', self::API_KEY);
        $this->environment['METRICS_KEYS'] = 'sha256:' . hash('sha256', 'scraper-key-0123456789');
        list(, $output) = $this->check();
        self::assertStringNotContainsString('configured as themselves', $output, 'digests only');

        $this->environment['API_KEY'] = self::API_KEY;
        $this->capabilities = self::capabilities(IsolationLevel::SANDBOX, [ImageFormat::WEBP]);
        list(, $output) = $this->check();
        self::assertStringNotContainsString('configured as themselves', $output, 'with the sandbox');
    }

    public function testUnlimitedIniValuesPass(): void
    {
        $this->environment['V1_SCRIPT_NAME'] = 'convert-now';
        $this->ini = ['post_max_size' => '0', 'upload_max_filesize' => '1G', 'max_file_uploads' => '10'];

        list(, $output) = $this->check();

        self::assertStringNotContainsString('php.ini', $output);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function check(array $input = [], ?string $envFile = null): array
    {
        $tester = new CommandTester($this->command($envFile));
        $exitCode = $tester->execute($input);

        return [$exitCode, $tester->getDisplay()];
    }

    private function command(?string $envFile = null): CheckEnvCommand
    {
        $environment = $this->environment;

        return new CheckEnvCommand(
            new ConfigLoader($envFile, static function (string $name) use ($environment): ?string {
                return $environment[$name] ?? null;
            }),
            [],
            function (): Capabilities {
                ++$this->probes;
                if ($this->capabilities instanceof \Throwable) {
                    throw $this->capabilities;
                }

                return $this->capabilities;
            },
            function (string $extension): bool {
                return in_array($extension, $this->extensions, true);
            },
            function (string $name): string {
                return $this->ini[$name] ?? '';
            }
        );
    }

    /**
     * @param list<string> $outputs
     */
    private static function capabilities(string $isolation, array $outputs): Capabilities
    {
        return new Capabilities(
            [ImageFormat::PNG, ImageFormat::JPEG],
            $outputs,
            [],
            ['cwebp'],
            $isolation,
            array_fill_keys(Capabilities::limitNames(), 100000)
        );
    }
}
