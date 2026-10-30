<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit;

use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Exercises the runtime dependencies the service is built on. CI runs it
 * against the lowest and the highest installable releases on every PHP
 * version, so a dependency that does not install or load on one of them
 * fails here first.
 *
 * @coversNothing
 */
#[CoversNothing]
final class RuntimeDependencyCompatibilityTest extends TestCase
{
    /**
     * The conversion contract comes from the library installed in vendor/
     * from its git repository, not from a copy inside this project.
     */
    public function testConversionLibraryIsAutoloadedFromVendor(): void
    {
        $vendor = realpath(dirname(__DIR__, 2) . '/vendor');
        self::assertIsString($vendor);
        $file = (new \ReflectionClass(ConversionRequest::class))->getFileName();
        self::assertIsString($file);
        $file = realpath($file);
        self::assertIsString($file);

        self::assertStringStartsWith(
            $vendor . DIRECTORY_SEPARATOR . 'mfonte' . DIRECTORY_SEPARATOR . 'secure-image-converter' . DIRECTORY_SEPARATOR,
            $file
        );

        $request = ConversionRequest::fromParameters(['format' => 'avif', 'width' => '640']);
        self::assertSame('avif', $request->getFormat());
        self::assertSame(640, $request->getWidth());
        self::assertEquals($request, ConversionRequest::fromParameters($request->toParameters()));
    }
}
