<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit;

use ErrorException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Proves that the error handler installed by tests/bootstrap.php is the one
 * active while tests run, on every PHPUnit version of the matrix, and that it
 * fails on project errors while tolerating deprecations from vendor/.
 *
 * @coversNothing
 */
#[CoversNothing]
final class BootstrapErrorHandlerTest extends TestCase
{
    /**
     * @dataProvider userErrorLevels
     */
    #[DataProvider('userErrorLevels')]
    public function testErrorsRaisedByTestsBecomeErrorExceptions(int $level): void
    {
        try {
            trigger_error('bootstrap error handler probe', $level);
        } catch (ErrorException $exception) {
            self::assertSame($level, $exception->getSeverity());
            self::assertSame(__FILE__, $exception->getFile());

            return;
        }

        self::fail('The error was not converted into an ErrorException: the bootstrap handler is not active.');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function userErrorLevels(): array
    {
        return [
            'deprecation' => [E_USER_DEPRECATED],
            'notice' => [E_USER_NOTICE],
            'warning' => [E_USER_WARNING],
        ];
    }

    /**
     * @dataProvider projectFiles
     */
    #[DataProvider('projectFiles')]
    public function testEngineDeprecationsRaisedByProjectFilesAreFatal(string $relativePath): void
    {
        $handler = self::installedHandler();
        $file = self::projectRoot() . DIRECTORY_SEPARATOR . $relativePath;

        $this->expectException(ErrorException::class);
        $handler(E_DEPRECATED, 'engine deprecation probe', $file, 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function projectFiles(): array
    {
        return [
            'src' => ['src' . DIRECTORY_SEPARATOR . 'Example.php'],
            'tests' => ['tests' . DIRECTORY_SEPARATOR . 'Unit' . DIRECTORY_SEPARATOR . 'ExampleTest.php'],
            'vendor-like directory inside tests' => [
                'tests' . DIRECTORY_SEPARATOR . 'Fixtures' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'Example.php',
            ],
        ];
    }

    public function testDeprecationsRaisedInsideVendorAreLeftToPhp(): void
    {
        $handler = self::installedHandler();
        $file = self::vendorFile();

        self::assertFalse($handler(E_DEPRECATED, 'vendor deprecation probe', $file, 1));
        self::assertFalse($handler(E_USER_DEPRECATED, 'vendor deprecation probe', $file, 1));
    }

    public function testWarningsRaisedInsideVendorAreFatal(): void
    {
        $handler = self::installedHandler();

        $this->expectException(ErrorException::class);
        $handler(E_WARNING, 'vendor warning probe', self::vendorFile(), 1);
    }

    public function testErrorsSilencedWithTheAtOperatorAreLeftToPhp(): void
    {
        error_clear_last();

        @trigger_error('silenced probe', E_USER_WARNING);

        $last = error_get_last();
        self::assertNotNull($last);
        self::assertSame('silenced probe', $last['message']);
    }

    /**
     * Returns the error handler installed by tests/bootstrap.php, which stays
     * installed.
     */
    private static function installedHandler(): callable
    {
        $handler = set_error_handler(static function (): bool {
            return false;
        });
        restore_error_handler();

        self::assertIsCallable($handler, 'tests/bootstrap.php did not install an error handler.');

        return $handler;
    }

    private static function projectRoot(): string
    {
        $root = realpath(dirname(__DIR__, 2));
        self::assertIsString($root);

        return $root;
    }

    private static function vendorFile(): string
    {
        return self::projectRoot() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'acme'
            . DIRECTORY_SEPARATOR . 'legacy' . DIRECTORY_SEPARATOR . 'Legacy.php';
    }
}
