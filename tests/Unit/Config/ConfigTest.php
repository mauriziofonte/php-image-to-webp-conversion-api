<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Config;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Config\ConfigLoader;
use Mfonte\ImageConversionApi\Exception\InvalidConfigurationException;
use Mfonte\ImageConversionApi\Limits\ApcuRateLimitStore;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Config\Config
 * @covers \Mfonte\ImageConversionApi\Exception\InvalidConfigurationException
 */
#[CoversClass(Config::class)]
#[CoversClass(InvalidConfigurationException::class)]
final class ConfigTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        mkdir($this->directory->path('public'));
    }

    protected function tear_down(): void
    {
        foreach (Config::KEYS as $key) {
            putenv($key);
        }
        $this->directory->remove();
        parent::tear_down();
    }

    public function testDefaults(): void
    {
        $config = Config::fromArray([]);

        self::assertNull($config->getV1ScriptName());
        self::assertSame(sys_get_temp_dir(), $config->getTemporaryDirectory());
        self::assertSame([], $config->getTrustedProxies());
        self::assertTrue($config->isHttpsRequired());
        self::assertSame([], $config->getApiKeys());
        self::assertSame([], $config->getIpAllowlist());
        self::assertSame(Config::DEFAULT_CONVERSION_TIME_LIMIT, $config->getConversionTimeLimit());
        self::assertSame(22020096, $config->getMaxBodyBytes());
        self::assertSame(20971520, $config->getMaxImageBytes());
        self::assertSame(10, $config->getV1MaxFiles());
        self::assertSame(4, $config->getConcurrencySlots());
        self::assertSame(10, $config->getConcurrencyWait());
        self::assertSame(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'image-conversion-api.locks', $config->getLockDirectory());
        self::assertSame(300, $config->getRateLimit());
        self::assertSame(50, $config->getRateLimitBurst());
        self::assertSame(Config::RATE_LIMIT_STORE_AUTO, $config->getRateLimitStore());
        self::assertSame(Config::METRICS_STORE_AUTO, $config->getMetricsStore());
        self::assertSame([], $config->getMetricsKeys());
        self::assertNull($config->getConverterCacheDirectory());
        self::assertNull($config->getLogFile());
        self::assertSame('info', $config->getLogLevel());
    }

    public function testReadsTheObservabilitySettings(): void
    {
        $config = Config::fromArray([
            'METRICS_STORE' => 'OFF',
            'METRICS_KEYS' => ' scraper-key-0123456789 ,, other-scraper-0123456789,scraper-key-0123456789',
            'CONVERTER_CACHE_DIR' => '/var/cache/image-conversion-api',
            'LOG_FILE' => 'stderr',
            'LOG_LEVEL' => 'Warning',
        ]);

        self::assertSame(Config::METRICS_STORE_OFF, $config->getMetricsStore());
        self::assertSame(['scraper-key-0123456789', 'other-scraper-0123456789'], $config->getMetricsKeys());
        self::assertSame([], $config->getApiKeys(), 'a metrics key is no API key');
        self::assertSame('/var/cache/image-conversion-api', $config->getConverterCacheDirectory());
        self::assertSame(Config::LOG_FILE_STDERR, $config->getLogFile());
        self::assertSame('warning', $config->getLogLevel());
        self::assertSame('/var/log/api.log', Config::fromArray(['LOG_FILE' => '/var/log/api.log'])->getLogFile());
        self::assertSame(Config::METRICS_STORE_FILE, Config::fromArray(['METRICS_STORE' => 'file'])->getMetricsStore());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function provideInvalidObservabilitySettings(): array
    {
        return [
            'metrics store' => [['METRICS_STORE' => 'redis'], 'METRICS_STORE must be auto, apcu, file or off'],
            'short metrics key' => [['METRICS_KEYS' => 'scraper-key-0123456789,tiny-key'], 'METRICS_KEYS must list keys of at least 16 bytes: key 2 is shorter'],
            'relative cache directory' => [['CONVERTER_CACHE_DIR' => 'var/cache'], 'CONVERTER_CACHE_DIR must be an absolute path without "." or ".." segments, empty segments or a trailing "/"'],
            'cache directory with ..' => [['CONVERTER_CACHE_DIR' => '/var/cache/../tmp'], 'CONVERTER_CACHE_DIR must be an absolute path without "." or ".." segments, empty segments or a trailing "/"'],
            'cache directory with a trailing slash' => [['CONVERTER_CACHE_DIR' => '/var/cache/'], 'CONVERTER_CACHE_DIR must be an absolute path without "." or ".." segments, empty segments or a trailing "/"'],
            'relative log file' => [['LOG_FILE' => 'api.log'], 'LOG_FILE must be "stderr" or an absolute path'],
            'log level' => [['LOG_LEVEL' => 'verbose'], 'LOG_LEVEL must be one of debug, info, notice, warning, error, critical, alert and emergency'],
        ];
    }

    /**
     * @dataProvider provideInvalidObservabilitySettings
     *
     * @param array<string, string> $values
     */
    #[DataProvider('provideInvalidObservabilitySettings')]
    public function testRefusesInvalidObservabilitySettings(array $values, string $error): void
    {
        try {
            Config::fromArray($values);
            self::fail('The value is refused');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame([$error], $exception->getErrors());
            self::assertStringNotContainsString('tiny-key', $exception->getMessage(), 'the error never names a key');
        }
    }

    public function testReadsTheLimits(): void
    {
        $config = Config::fromArray([
            'TEMP_DIR' => '/var/tmp/api/',
            'CONVERSION_TIME_LIMIT' => '30',
            'MAX_BODY_BYTES' => '5000000',
            'MAX_IMAGE_BYTES' => '4000000',
            'V1_MAX_FILES' => '100',
            'CONCURRENCY_SLOTS' => '256',
            'CONCURRENCY_WAIT' => '0',
            'RATE_LIMIT' => '0',
            'RATE_LIMIT_BURST' => '1',
            'RATE_LIMIT_STORE' => 'FILE',
        ]);

        self::assertSame(5000000, $config->getMaxBodyBytes());
        self::assertSame(4000000, $config->getMaxImageBytes());
        self::assertSame(100, $config->getV1MaxFiles());
        self::assertSame(256, $config->getConcurrencySlots());
        self::assertSame(0, $config->getConcurrencyWait());
        self::assertSame('/var/tmp/api' . DIRECTORY_SEPARATOR . 'image-conversion-api.locks', $config->getLockDirectory(), 'in TEMP_DIR by default');
        self::assertSame(0, $config->getRateLimit());
        self::assertSame(1, $config->getRateLimitBurst());
        self::assertSame(Config::RATE_LIMIT_STORE_FILE, $config->getRateLimitStore());
        self::assertSame('/run/api-locks', Config::fromArray(['LOCK_DIR' => '/run/api-locks'])->getLockDirectory());
    }

    public function testTheDefaultSlotWaitFitsAShortTimeLimit(): void
    {
        self::assertSame(4, Config::fromArray(['CONVERSION_TIME_LIMIT' => '5'])->getConcurrencyWait());
        self::assertSame(0, Config::fromArray(['CONVERSION_TIME_LIMIT' => '1'])->getConcurrencyWait());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function provideInvalidLimits(): array
    {
        return [
            'body size zero' => [['MAX_BODY_BYTES' => '0'], 'MAX_BODY_BYTES must be a whole number'],
            'image size with a unit' => [['MAX_IMAGE_BYTES' => '20M'], 'MAX_IMAGE_BYTES must be a whole number'],
            'image size above 1 GiB' => [['MAX_IMAGE_BYTES' => '1073741825'], 'MAX_IMAGE_BYTES must be a whole number from 1 to 1073741824'],
            'no v1 file' => [['V1_MAX_FILES' => '0'], 'V1_MAX_FILES must be a whole number from 1 to 100'],
            'too many slots' => [['CONCURRENCY_SLOTS' => '257'], 'CONCURRENCY_SLOTS must be a whole number from 1 to 256'],
            'wait as long as the time limit' => [['CONVERSION_TIME_LIMIT' => '20', 'CONCURRENCY_WAIT' => '20'], 'CONCURRENCY_WAIT must be shorter than CONVERSION_TIME_LIMIT'],
            'relative lock directory' => [['LOCK_DIR' => 'locks'], 'LOCK_DIR must be an absolute path'],
            'negative rate' => [['RATE_LIMIT' => '-1'], 'RATE_LIMIT must be a whole number from 0 to 1000000'],
            'no burst' => [['RATE_LIMIT_BURST' => '0'], 'RATE_LIMIT_BURST must be a whole number from 1 to 1000000'],
            'unknown store' => [['RATE_LIMIT_STORE' => 'redis'], 'RATE_LIMIT_STORE must be auto, apcu or file'],
        ];
    }

    /**
     * @dataProvider provideInvalidLimits
     *
     * @param array<string, string> $values
     */
    #[DataProvider('provideInvalidLimits')]
    public function testRefusesInvalidLimits(array $values, string $error): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($error);

        Config::fromArray($values);
    }

    public function testRefusesTheApcuStoreWithoutApcu(): void
    {
        if (ApcuRateLimitStore::isAvailable()) {
            self::assertSame(Config::RATE_LIMIT_STORE_APCU, Config::fromArray(['RATE_LIMIT_STORE' => 'apcu'])->getRateLimitStore());

            return;
        }
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('RATE_LIMIT_STORE is apcu, but APCu is not enabled');

        Config::fromArray(['RATE_LIMIT_STORE' => 'apcu']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideValidTimeLimits(): array
    {
        return ['smallest' => ['1'], 'typical' => ['55'], 'largest' => ['3600'], 'padded' => [' 30 ']];
    }

    /**
     * @dataProvider provideValidTimeLimits
     */
    #[DataProvider('provideValidTimeLimits')]
    public function testReadsTheConversionTimeLimit(string $value): void
    {
        self::assertSame((int) trim($value), Config::fromArray(['CONVERSION_TIME_LIMIT' => $value])->getConversionTimeLimit());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidTimeLimits(): array
    {
        return [
            'zero' => ['0'],
            'above the maximum' => ['3601'],
            'negative' => ['-5'],
            'decimal' => ['1.5'],
            'unit' => ['50s'],
            'huge' => ['99999999999999999999'],
        ];
    }

    /**
     * @dataProvider provideInvalidTimeLimits
     */
    #[DataProvider('provideInvalidTimeLimits')]
    public function testRefusesAnInvalidConversionTimeLimit(string $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('CONVERSION_TIME_LIMIT must be a whole number from 1 to 3600');

        Config::fromArray(['CONVERSION_TIME_LIMIT' => $value]);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function provideUnsetValues(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'blank' => ['  '],
            'literal null' => ['null'],
            'literal NULL' => ['NULL'],
        ];
    }

    /**
     * @dataProvider provideUnsetValues
     */
    #[DataProvider('provideUnsetValues')]
    public function testUnsetValuesTakeTheDefault(?string $value): void
    {
        $config = Config::fromArray(array_fill_keys(Config::KEYS, $value));

        self::assertNull($config->getV1ScriptName());
        self::assertSame(sys_get_temp_dir(), $config->getTemporaryDirectory());
        self::assertSame([], $config->getTrustedProxies());
        self::assertTrue($config->isHttpsRequired());
        self::assertSame([], $config->getApiKeys());
        self::assertSame([], $config->getIpAllowlist());
    }

    public function testReadsTheValues(): void
    {
        $config = Config::fromArray([
            'V1_SCRIPT_NAME' => ' convert-now ',
            'TEMP_DIR' => $this->directory->path('var/tmp'),
            'TRUSTED_PROXIES' => '10.0.0.0/8, 192.0.2.1,2001:db8::/32',
            'REQUIRE_HTTPS' => 'false',
            'IP_ALLOWLIST' => '198.51.100.0/24,::1',
        ], [$this->directory->path('public')]);

        self::assertSame('convert-now', $config->getV1ScriptName());
        self::assertSame($this->directory->path('var/tmp'), $config->getTemporaryDirectory());
        self::assertSame(['10.0.0.0/8', '192.0.2.1/32', '2001:db8::/32'], array_map('strval', $config->getTrustedProxies()));
        self::assertFalse($config->isHttpsRequired());
        self::assertSame(['198.51.100.0/24', '::1/128'], array_map('strval', $config->getIpAllowlist()));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideBooleans(): array
    {
        return [
            'true' => ['true', true],
            'TRUE' => ['TRUE', true],
            'one' => ['1', true],
            'yes' => ['yes', true],
            'on' => ['On', true],
            'false' => ['false', false],
            'zero' => ['0', false],
            'no' => ['no', false],
            'off' => ['OFF', false],
        ];
    }

    /**
     * @dataProvider provideBooleans
     */
    #[DataProvider('provideBooleans')]
    public function testReadsBooleans(string $value, bool $expected): void
    {
        self::assertSame($expected, Config::fromArray(['REQUIRE_HTTPS' => $value])->isHttpsRequired());
    }

    /**
     * API_KEY is one key, commas included (the 1.x example key has one);
     * API_KEYS adds keys separated by commas, for rotation.
     */
    public function testReadsTheApiKeys(): void
    {
        $config = Config::fromArray([
            'API_KEY' => ' an example api key, very secret ',
            'API_KEYS' => 'next-key-0123456789, ,other-key-0123456789,next-key-0123456789',
        ]);

        self::assertSame(['an example api key, very secret', 'next-key-0123456789', 'other-key-0123456789'], $config->getApiKeys());
        self::assertSame(
            ['first-key-0123456789', 'second-key-0123456789'],
            Config::fromArray(['API_KEY' => 'first-key-0123456789', 'API_KEYS' => 'first-key-0123456789,second-key-0123456789'])->getApiKeys()
        );
    }

    public function testAcceptsAKeyOfTheMinimumLength(): void
    {
        $key = str_repeat('k', 16);

        self::assertSame([$key], Config::fromArray(['API_KEY' => ' ' . $key . ' '])->getApiKeys());
        self::assertSame([$key], Config::fromArray(['API_KEYS' => $key])->getApiKeys());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function provideShortKeys(): array
    {
        $fifteen = str_repeat('s', 15);

        return [
            'API_KEY' => [['API_KEY' => 'a'], 'API_KEY must be at least 16 bytes long'],
            'API_KEY of 15 characters' => [['API_KEY' => $fifteen], 'API_KEY must be at least 16 bytes long'],
            'API_KEY of 15 characters and blanks' => [['API_KEY' => '  ' . $fifteen . "\t"], 'API_KEY must be at least 16 bytes long'],
            'second key of API_KEYS' => [
                ['API_KEYS' => 'first-key-0123456789, , ' . $fifteen . ',third-key-0123456789'],
                'API_KEYS must list keys of at least 16 bytes: key 2 is shorter',
            ],
        ];
    }

    /**
     * A key shorter than 16 characters can be guessed faster than a rate
     * limit slows the guessing down: the configuration is invalid, and the
     * error names the setting, never the key.
     *
     * @dataProvider provideShortKeys
     *
     * @param array<string, string> $values
     */
    #[DataProvider('provideShortKeys')]
    public function testRefusesShortKeys(array $values, string $error): void
    {
        try {
            Config::fromArray($values);
            self::fail('A short key is refused');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame([$error], $exception->getErrors());
            self::assertStringNotContainsString('sssss', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function provideMalformedDigests(): array
    {
        $short = 'sha256:' . str_repeat('ab', 31) . 'c';

        return [
            'API_KEY' => [['API_KEY' => $short], 'API_KEY is a SHA-256 digest that must be followed by exactly 64 hexadecimal digits after "sha256:"'],
            'second key of API_KEYS' => [
                ['API_KEYS' => 'first-key-0123456789, SHA256:' . str_repeat('g', 64)],
                'API_KEYS: key 2 is a SHA-256 digest that must be followed by exactly 64 hexadecimal digits after "sha256:"',
            ],
            'METRICS_KEYS' => [
                ['API_KEY' => 'first-key-0123456789', 'METRICS_KEYS' => $short],
                'METRICS_KEYS: key 1 is a SHA-256 digest that must be followed by exactly 64 hexadecimal digits after "sha256:"',
            ],
        ];
    }

    /**
     * An entry that starts with "sha256:" but is no digest (63 digits, a
     * letter that is no hexadecimal digit) is a configuration error, not a
     * key that its own text would open.
     *
     * @dataProvider provideMalformedDigests
     *
     * @param array<string, string> $values
     */
    #[DataProvider('provideMalformedDigests')]
    public function testRefusesMalformedDigests(array $values, string $error): void
    {
        try {
            Config::fromArray($values);
            self::fail('A malformed digest is refused');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame([$error], $exception->getErrors());
            self::assertStringNotContainsString('abab', $exception->getMessage());
            self::assertStringNotContainsString('gggg', $exception->getMessage());
        }
    }

    public function testAcceptsDigests(): void
    {
        $digest = 'sha256:' . hash('sha256', 'first-key-0123456789');

        $config = Config::fromArray(['API_KEY' => $digest, 'API_KEYS' => strtoupper($digest), 'METRICS_KEYS' => $digest]);

        self::assertSame([$digest, strtoupper($digest)], $config->getApiKeys());
        self::assertSame([$digest], $config->getMetricsKeys());
    }

    public function testRefusesAnInvalidBoolean(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('REQUIRE_HTTPS must be true or false');

        Config::fromArray(['REQUIRE_HTTPS' => 'sometimes']);
    }

    public function testRefusesAnInvalidAllowlistEntry(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('IP_ALLOWLIST must list IP addresses and CIDR blocks separated by commas: "10.0.0.0/40" has');

        Config::fromArray(['IP_ALLOWLIST' => '10.0.0.0/40']);
    }

    public function testRefusesAnInvalidTrustedProxy(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('TRUSTED_PROXIES must list IP addresses and CIDR blocks separated by commas: "lb.example" is not');

        Config::fromArray(['TRUSTED_PROXIES' => '10.0.0.1, lb.example']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidScriptNames(): array
    {
        return [
            'slash' => ['a/b'],
            'dot' => ['.'],
            'dot dot' => ['..'],
            'percent' => ['a%2Fb'],
            'space inside' => ['convert now'],
            'query' => ['convert?x=1'],
            'non-ASCII' => ["conv\u{e9}rt"],
        ];
    }

    /**
     * @dataProvider provideInvalidScriptNames
     */
    #[DataProvider('provideInvalidScriptNames')]
    public function testRefusesAScriptNameThatIsNotOnePathSegment(string $scriptName): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('V1_SCRIPT_NAME must be one URL path segment');

        Config::fromArray(['V1_SCRIPT_NAME' => $scriptName]);
    }

    public function testAcceptsTheUnreservedCharacters(): void
    {
        self::assertSame('Az09._~-', Config::fromArray(['V1_SCRIPT_NAME' => 'Az09._~-'])->getV1ScriptName());
    }

    public function testRefusesARelativeTemporaryDirectory(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('TEMP_DIR must be an absolute path');

        Config::fromArray(['TEMP_DIR' => 'temp']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideTemporaryDirectoriesInsideTheWebRoot(): array
    {
        return [
            'the web root' => ['public'],
            'below the web root' => ['public/temp'],
            'below the web root through ".."' => ['var/../public/temp'],
        ];
    }

    /**
     * Finding C-8: 1.x kept its temporary files in the web root.
     *
     * @dataProvider provideTemporaryDirectoriesInsideTheWebRoot
     */
    #[DataProvider('provideTemporaryDirectoriesInsideTheWebRoot')]
    public function testRefusesATemporaryDirectoryInsideAWebRoot(string $temporaryDirectory): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('TEMP_DIR must be outside the web root');

        Config::fromArray(
            ['TEMP_DIR' => $this->directory->path($temporaryDirectory)],
            ['', $this->directory->path('other'), $this->directory->path('public')]
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideLockDirectoriesTheSweepWouldRemove(): array
    {
        $insideARequestDirectory = 'LOCK_DIR must not be inside a directory of TEMP_DIR whose name starts with "image-conversion-api-"';

        return [
            'TEMP_DIR itself' => ['temp', 'LOCK_DIR must not be TEMP_DIR'],
            'TEMP_DIR with a trailing slash' => ['temp/', 'LOCK_DIR must not be TEMP_DIR'],
            'TEMP_DIR through ".."' => ['temp/locks/..', 'LOCK_DIR must not be TEMP_DIR'],
            'named as a request directory' => ['temp/image-conversion-api-locks', $insideARequestDirectory],
            'inside a request directory' => ['temp/image-conversion-api-0123/locks', $insideARequestDirectory],
            'named as a request directory through ".."' => ['other/../temp/image-conversion-api-locks', $insideARequestDirectory],
            'named as a request directory through a link to TEMP_DIR' => ['alias/image-conversion-api-locks', $insideARequestDirectory],
            'inside a request directory that links elsewhere' => ['temp/image-conversion-api-link/locks', $insideARequestDirectory],
        ];
    }

    /**
     * Finding F1 of the W4 review: RequestDirectories removes the stale
     * directories of TEMP_DIR named with its prefix, so a LOCK_DIR there
     * would lose its lock files while they are held.
     *
     * @dataProvider provideLockDirectoriesTheSweepWouldRemove
     */
    #[DataProvider('provideLockDirectoriesTheSweepWouldRemove')]
    public function testRefusesALockDirectoryTheSweepWouldRemove(string $lockDirectory, string $error): void
    {
        $this->makeLinkedTemporaryDirectory();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($error);

        Config::fromArray(['TEMP_DIR' => $this->directory->path('temp'), 'LOCK_DIR' => $this->directory->path($lockDirectory)]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideLockDirectoriesTheSweepLeavesAlone(): array
    {
        return [
            'the default name' => ['temp/' . Config::DEFAULT_LOCK_DIR_NAME],
            'a plain name in TEMP_DIR' => ['temp/locks'],
            'the prefix below a plain directory' => ['temp/locks/image-conversion-api-locks'],
            'a sibling of TEMP_DIR' => ['image-conversion-api-locks'],
            'the parent of TEMP_DIR' => [''],
            'a sibling whose name starts with TEMP_DIR' => ['temp-image-conversion-api-locks'],
        ];
    }

    /**
     * @dataProvider provideLockDirectoriesTheSweepLeavesAlone
     */
    #[DataProvider('provideLockDirectoriesTheSweepLeavesAlone')]
    public function testAcceptsALockDirectoryTheSweepLeavesAlone(string $lockDirectory): void
    {
        $this->makeLinkedTemporaryDirectory();
        $path = $this->directory->path($lockDirectory);

        self::assertSame($path, Config::fromArray(['TEMP_DIR' => $this->directory->path('temp'), 'LOCK_DIR' => $path])->getLockDirectory());
    }

    public function testListsEveryError(): void
    {
        try {
            Config::fromArray(['V1_SCRIPT_NAME' => 'a/b', 'TEMP_DIR' => 'relative', 'TRUSTED_PROXIES' => 'x', 'REQUIRE_HTTPS' => 'x', 'IP_ALLOWLIST' => 'x']);
            self::fail('An InvalidConfigurationException was expected');
        } catch (InvalidConfigurationException $exception) {
            self::assertCount(5, $exception->getErrors());
            self::assertStringStartsWith('Invalid configuration: V1_SCRIPT_NAME', $exception->getMessage());
        }
    }

    public function testAnExceptionWithoutErrorsStillSaysWhy(): void
    {
        self::assertSame(['The configuration is invalid'], (new InvalidConfigurationException([]))->getErrors());
    }

    public function testReadsTheEnvironment(): void
    {
        putenv('V1_SCRIPT_NAME=from-environment');
        putenv('TEMP_DIR=' . $this->directory->path('var'));

        $config = Config::fromEnvironment([$this->directory->path('public')]);

        self::assertSame('from-environment', $config->getV1ScriptName());
        self::assertSame($this->directory->path('var'), $config->getTemporaryDirectory());
    }

    /**
     * .env.example documents every key, set or commented out; what it sets
     * is a valid configuration.
     */
    public function testTheExampleDocumentsEveryKey(): void
    {
        $example = dirname(__DIR__, 3) . '/.env.example';
        preg_match_all('/^#?([A-Z][A-Z0-9_]*) = /m', (string) file_get_contents($example), $matches);
        $values = (new ConfigLoader($example, static function (string $name): ?string {
            return null;
        }))->readValues();

        self::assertSame(['V1_SCRIPT_NAME', 'API_KEY', 'API_KEYS', 'TEMP_DIR', 'TRUSTED_PROXIES', 'REQUIRE_HTTPS', 'IP_ALLOWLIST', 'CONVERSION_TIME_LIMIT', 'MAX_BODY_BYTES', 'MAX_IMAGE_BYTES', 'V1_MAX_FILES', 'CONCURRENCY_SLOTS', 'CONCURRENCY_WAIT', 'LOCK_DIR', 'RATE_LIMIT', 'RATE_LIMIT_BURST', 'RATE_LIMIT_STORE', 'METRICS_STORE', 'METRICS_KEYS', 'CONVERTER_CACHE_DIR', 'LOG_FILE', 'LOG_LEVEL'], $matches[1]);
        self::assertSame([], array_diff(Config::KEYS, $matches[1]));
        self::assertSame(['V1_SCRIPT_NAME', 'API_KEY'], array_keys(array_filter($values, 'is_string')));
        self::assertIsString($values['API_KEY']);
        self::assertSame([$values['API_KEY']], Config::fromArray($values)->getApiKeys());
    }

    /**
     * TEMP_DIR at temp/, with "alias" a link to it and the request
     * directory "image-conversion-api-link" a link to "elsewhere".
     */
    private function makeLinkedTemporaryDirectory(): void
    {
        mkdir($this->directory->path('temp'));
        mkdir($this->directory->path('elsewhere'));
        symlink($this->directory->path('temp'), $this->directory->path('alias'));
        symlink($this->directory->path('elsewhere'), $this->directory->path('temp/image-conversion-api-link'));
    }
}
