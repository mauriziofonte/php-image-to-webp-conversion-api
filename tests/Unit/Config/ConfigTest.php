<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Config;

use Mfonte\ImageConversionApi\Config\Config;
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
            'API_KEY' => [['API_KEY' => 'a'], 'API_KEY must be at least 16 characters long'],
            'API_KEY of 15 characters' => [['API_KEY' => $fifteen], 'API_KEY must be at least 16 characters long'],
            'API_KEY of 15 characters and blanks' => [['API_KEY' => '  ' . $fifteen . "\t"], 'API_KEY must be at least 16 characters long'],
            'second key of API_KEYS' => [
                ['API_KEYS' => 'first-key-0123456789, , ' . $fifteen . ',third-key-0123456789'],
                'API_KEYS must list keys of at least 16 characters: key 2 is shorter',
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
     * .env.example documents every key, set or commented out, plus the 1.x
     * SCRIPT_NAME that process.php still reads; what it sets is a valid
     * configuration.
     */
    public function testTheExampleDocumentsEveryKey(): void
    {
        $example = dirname(__DIR__, 3) . '/.env.example';
        preg_match_all('/^;?([A-Z][A-Z0-9_]*) = /m', (string) file_get_contents($example), $matches);
        $values = parse_ini_file($example);

        self::assertSame(['V1_SCRIPT_NAME', 'SCRIPT_NAME', 'API_KEY', 'API_KEYS', 'TEMP_DIR', 'TRUSTED_PROXIES', 'REQUIRE_HTTPS', 'IP_ALLOWLIST', 'CONVERSION_TIME_LIMIT', 'MAX_BODY_BYTES', 'MAX_IMAGE_BYTES', 'V1_MAX_FILES', 'CONCURRENCY_SLOTS', 'CONCURRENCY_WAIT', 'LOCK_DIR', 'RATE_LIMIT', 'RATE_LIMIT_BURST', 'RATE_LIMIT_STORE'], $matches[1]);
        self::assertSame([], array_diff(Config::KEYS, $matches[1]));
        self::assertIsArray($values);
        self::assertSame(['V1_SCRIPT_NAME', 'API_KEY'], array_keys($values));
        $strings = [];
        foreach ($values as $key => $value) {
            self::assertIsString($value);
            $strings[(string) $key] = $value;
        }
        self::assertSame([$strings['API_KEY']], Config::fromArray($strings)->getApiKeys());
    }
}
