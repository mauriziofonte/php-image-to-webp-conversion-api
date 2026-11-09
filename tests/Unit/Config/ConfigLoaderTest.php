<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Config;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Config\ConfigLoader;
use Mfonte\ImageConversionApi\Exception\InvalidConfigurationException;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Environment first, then the optional .env file (plan section 4), as the
 * proxy's ConfigLoader reads them.
 *
 * @covers \Mfonte\ImageConversionApi\Config\ConfigLoader
 */
#[CoversClass(ConfigLoader::class)]
final class ConfigLoaderTest extends TestCase
{
    private const KEY = 'loader-test-key-0123456789';

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testTheEnvironmentWinsOverTheEnvFile(): void
    {
        $loader = $this->loader("API_KEY = \"from-file-0123456789\"\nV1_SCRIPT_NAME = from-file\nRATE_LIMIT = 10\n", [
            'API_KEY' => self::KEY,
            'V1_SCRIPT_NAME' => '',
            'RATE_LIMIT' => 'null',
        ]);

        $config = $loader->load();

        self::assertSame([self::KEY], $config->getApiKeys());
        self::assertSame('from-file', $config->getV1ScriptName(), 'an empty variable falls back to .env');
        self::assertSame(10, $config->getRateLimit(), '"null" in the environment falls back to .env');
        self::assertSame(['API_KEY' => self::KEY], $loader->readEnvironmentValues());
        self::assertTrue($loader->hasEnvFile());
    }

    public function testWorksWithoutAnEnvFile(): void
    {
        $loader = new ConfigLoader($this->directory->path('.env'), self::environment(['API_KEY' => self::KEY]));

        self::assertFalse($loader->hasEnvFile());
        self::assertSame($this->directory->path('.env'), $loader->getEnvFile());
        self::assertSame([self::KEY], $loader->load()->getApiKeys());
        self::assertSame([self::KEY], (new ConfigLoader(null, self::environment(['API_KEY' => self::KEY])))->load()->getApiKeys());
    }

    public function testReadsOnlyTheKnownKeys(): void
    {
        $values = $this->loader('API_KEY = ' . self::KEY . "\nPATH = /evil\nSCRIPT_NAME = old\n", ['HOME' => '/root'])->readValues();

        self::assertSame(Config::KEYS, array_keys($values));
        self::assertSame(self::KEY, $values['API_KEY']);
        self::assertNull($values['V1_SCRIPT_NAME']);
    }

    /**
     * The .env file is parsed into an array: it never changes the process
     * environment, which the conversion processes inherit.
     */
    public function testNeverChangesTheEnvironment(): void
    {
        $this->loader('API_KEY = ' . self::KEY . "\nLOG_LEVEL = debug\n", [])->load();

        self::assertFalse(getenv('API_KEY'));
        self::assertFalse(getenv('LOG_LEVEL'));
        self::assertArrayNotHasKey('API_KEY', $_ENV);
        self::assertArrayNotHasKey('API_KEY', $_SERVER);
    }

    /**
     * The 1.x .env file was read with parse_ini_file(), whose comments
     * start with ";".
     */
    public function testSkipsTheCommentsOfThe1xFile(): void
    {
        $values = $this->loader("; The 1.x comment\n  ; indented\n# A comment\nAPI_KEY = \"" . self::KEY . "\"\n", [])->readValues();

        self::assertSame(self::KEY, $values['API_KEY']);
    }

    /**
     * phpdotenv quotes the text it cannot parse, which can be a key: the
     * error keeps the reason only.
     */
    public function testAnEnvFileThatCannotBeParsedIsAnErrorWithoutItsText(): void
    {
        try {
            $this->loader("API_KEY = secret with spaces\n", [])->readValues();
            self::fail('The .env file is refused');
        } catch (InvalidConfigurationException $exception) {
            self::assertSame(['The .env file cannot be parsed: Failed to parse dotenv file. Encountered unexpected whitespace.'], $exception->getErrors());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function testInvalidValuesOfTheFileAreConfigurationErrors(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('RATE_LIMIT must be a whole number');

        $this->loader("RATE_LIMIT = many\n", [])->load();
    }

    /**
     * @param array<string, string> $environment
     */
    private function loader(string $envFile, array $environment): ConfigLoader
    {
        $path = $this->directory->path('.env');
        file_put_contents($path, $envFile);

        return new ConfigLoader($path, self::environment($environment));
    }

    /**
     * @param array<string, string> $variables
     *
     * @return callable(string): ?string
     */
    private static function environment(array $variables): callable
    {
        return static function (string $name) use ($variables): ?string {
            return $variables[$name] ?? null;
        };
    }
}
