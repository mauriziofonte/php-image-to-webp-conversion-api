<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Tooling;

use Mfonte\ImageConversionApi\Tests\StrictErrorHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Tests\StrictErrorHandler
 */
#[CoversClass(StrictErrorHandler::class)]
final class StrictErrorHandlerTest extends TestCase
{
    private const VENDOR = '/srv/app/vendor';

    /**
     * @var int
     */
    private $errorReporting;

    protected function set_up(): void
    {
        parent::set_up();
        $this->errorReporting = error_reporting();
        error_reporting(E_ALL);
    }

    protected function tear_down(): void
    {
        error_reporting($this->errorReporting);
        parent::tear_down();
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function provideReportedErrors(): array
    {
        return [
            'deprecation in src/' => [E_DEPRECATED, '/srv/app/src/Kernel.php'],
            'user deprecation in tests/' => [E_USER_DEPRECATED, '/srv/app/tests/Unit/KernelTest.php'],
            'warning in src/' => [E_WARNING, '/srv/app/src/Kernel.php'],
            'notice in src/' => [E_NOTICE, '/srv/app/src/Kernel.php'],
            'warning in vendor/' => [E_WARNING, self::VENDOR . '/symfony/console/Application.php'],
            'deprecation in a sibling of vendor/' => [E_DEPRECATED, '/srv/app/vendor-old/lib.php'],
            'deprecation in a vendor/ directory inside tests/' => [E_DEPRECATED, '/srv/app/tests/Fixtures/vendor/lib.php'],
        ];
    }

    /**
     * @dataProvider provideReportedErrors
     */
    #[DataProvider('provideReportedErrors')]
    public function testReportedErrorsBecomeExceptions(int $severity, string $file): void
    {
        $handler = new StrictErrorHandler(self::VENDOR);

        try {
            $handler($severity, 'Something happened', $file, 42);
            self::fail('An ErrorException was expected');
        } catch (\ErrorException $exception) {
            self::assertSame('Something happened', $exception->getMessage());
            self::assertSame($severity, $exception->getSeverity());
            self::assertSame($file, $exception->getFile());
            self::assertSame(42, $exception->getLine());
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideDeprecationSeverities(): array
    {
        return [
            'E_DEPRECATED' => [E_DEPRECATED],
            'E_USER_DEPRECATED' => [E_USER_DEPRECATED],
        ];
    }

    /**
     * @dataProvider provideDeprecationSeverities
     */
    #[DataProvider('provideDeprecationSeverities')]
    public function testDeprecationsRaisedInsideVendorAreRecordedNotThrown(int $severity): void
    {
        $handler = new StrictErrorHandler(self::VENDOR . '/');
        $file = self::VENDOR . '/symfony/console/Application.php';

        self::assertTrue($handler($severity, 'Deprecated', $file, 1));
        self::assertTrue($handler($severity, 'Deprecated', $file, 1));
        self::assertTrue($handler($severity, 'Also deprecated', $file, 2));

        self::assertSame(
            [
                'Deprecated in ' . $file . ':1' => 2,
                'Also deprecated in ' . $file . ':2' => 1,
            ],
            $handler->getVendorDeprecations()
        );
    }

    public function testTheReportListsEachVendorDeprecationOnceWithItsCount(): void
    {
        $handler = new StrictErrorHandler(self::VENDOR);
        $file = self::VENDOR . '/psr/log/src/LoggerInterface.php';
        $handler(E_DEPRECATED, 'Old API', $file, 7);
        $handler(E_DEPRECATED, 'Old API', $file, 7);

        self::assertSame(
            PHP_EOL . '1 distinct deprecation(s) raised inside vendor/ (not fatal):' . PHP_EOL
            . '  [2x] Old API in ' . $file . ':7' . PHP_EOL,
            $this->report($handler)
        );
    }

    public function testTheReportIsSilentWithoutVendorDeprecations(): void
    {
        self::assertSame('', $this->report(new StrictErrorHandler(self::VENDOR)));
    }

    public function testInstallMakesTheHandlerTheCurrentOne(): void
    {
        $handler = StrictErrorHandler::install(self::VENDOR);
        $current = set_error_handler(static function (): bool {
            return false;
        });
        restore_error_handler();
        restore_error_handler();

        self::assertSame($handler, $current);
    }

    public function testErrorsExcludedFromErrorReportingAreLeftToPhp(): void
    {
        $handler = new StrictErrorHandler(self::VENDOR);
        error_reporting(E_ALL & ~E_WARNING);

        self::assertFalse($handler(E_WARNING, 'Silenced', '/srv/app/src/Kernel.php', 1));
    }

    public function testErrorsSilencedWithTheAtOperatorAreLeftToPhp(): void
    {
        error_clear_last();

        @trigger_error('Silenced', E_USER_WARNING);

        $last = error_get_last();
        self::assertNotNull($last);
        self::assertSame('Silenced', $last['message']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function provideUserErrorSeverities(): array
    {
        return [
            'E_USER_NOTICE' => [E_USER_NOTICE],
            'E_USER_WARNING' => [E_USER_WARNING],
            'E_USER_DEPRECATED' => [E_USER_DEPRECATED],
        ];
    }

    /**
     * The handler installed by tests/bootstrap.php is still the current one
     * while a test runs: PHPUnit has not replaced it with its own, which only
     * reports deprecations.
     *
     * @dataProvider provideUserErrorSeverities
     */
    #[DataProvider('provideUserErrorSeverities')]
    public function testTheBootstrapHandlerIsActiveWhileTestsRun(int $severity): void
    {
        try {
            trigger_error('Raised from tests/', $severity);
        } catch (\ErrorException $exception) {
            self::assertSame('Raised from tests/', $exception->getMessage());
            self::assertSame($severity, $exception->getSeverity());
            self::assertSame(__FILE__, $exception->getFile());

            return;
        }

        self::fail('The error did not become an ErrorException: the handler of tests/bootstrap.php is not active.');
    }

    private function report(StrictErrorHandler $handler): string
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $handler->reportVendorDeprecations($stream);
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return is_string($contents) ? $contents : '';
    }
}
