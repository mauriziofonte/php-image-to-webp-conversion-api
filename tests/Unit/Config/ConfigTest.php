<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Config;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Exception\InvalidConfigurationException;
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
        $config = Config::fromArray(['V1_SCRIPT_NAME' => $value, 'TEMP_DIR' => $value]);

        self::assertNull($config->getV1ScriptName());
        self::assertSame(sys_get_temp_dir(), $config->getTemporaryDirectory());
    }

    public function testReadsTheValues(): void
    {
        $config = Config::fromArray([
            'V1_SCRIPT_NAME' => ' convert-now ',
            'TEMP_DIR' => $this->directory->path('var/tmp'),
        ], [$this->directory->path('public')]);

        self::assertSame('convert-now', $config->getV1ScriptName());
        self::assertSame($this->directory->path('var/tmp'), $config->getTemporaryDirectory());
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
            Config::fromArray(['V1_SCRIPT_NAME' => 'a/b', 'TEMP_DIR' => 'relative']);
            self::fail('An InvalidConfigurationException was expected');
        } catch (InvalidConfigurationException $exception) {
            self::assertCount(2, $exception->getErrors());
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
}
