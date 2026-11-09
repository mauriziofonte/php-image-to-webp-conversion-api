<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Http;

use Mfonte\ImageConversionApi\Http\InstallationBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The server variables are those of Apache + PHP-FPM after the root
 * .htaccess rewrote the request to public/index.php.
 *
 * @covers \Mfonte\ImageConversionApi\Http\InstallationBase
 */
#[CoversClass(InstallationBase::class)]
final class InstallationBaseTest extends TestCase
{
    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function provideRequests(): array
    {
        $subdirectory = [
            'REQUEST_URI' => '/webp-converter/v2/convert?width=10',
            'SCRIPT_NAME' => '/webp-converter/public/index.php',
            'PHP_SELF' => '/webp-converter/public/index.php',
            'SCRIPT_FILENAME' => '/var/www/webp-converter/public/index.php',
        ];
        $root = [
            'REQUEST_URI' => '/v2/convert',
            'SCRIPT_NAME' => '/public/index.php',
            'PHP_SELF' => '/public/index.php',
            'SCRIPT_FILENAME' => '/var/www/public/index.php',
        ];
        $documentRoot = [
            'REQUEST_URI' => '/v2/convert',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => '/srv/app/public/index.php',
        ];

        return [
            'subdirectory, after the redirect' => [$subdirectory + ['REDIRECT_IMAGE_CONVERSION_API_BASE' => '/webp-converter/'], '/webp-converter/', '/v2/convert'],
            'subdirectory' => [$subdirectory + ['IMAGE_CONVERSION_API_BASE' => '/webp-converter/'], '/webp-converter/', '/v2/convert'],
            'nested subdirectory' => [
                ['REQUEST_URI' => '/a/b/v1/convert-now', 'SCRIPT_NAME' => '/a/b/public/index.php', 'SCRIPT_FILENAME' => '/w/a/b/public/index.php', 'REDIRECT_IMAGE_CONVERSION_API_BASE' => '/a/b/'],
                '/a/b/',
                '/v1/convert-now',
            ],
            'root of the host' => [$root, '', '/v2/convert'],
            'public/ as document root' => [$documentRoot, '', '/v2/convert'],
            'malformed prefix' => [$subdirectory + ['REDIRECT_IMAGE_CONVERSION_API_BASE' => 'webp-converter'], '', '/webp-converter/v2/convert'],
            'prefix that climbs' => [$subdirectory + ['REDIRECT_IMAGE_CONVERSION_API_BASE' => '/x/../'], '', '/webp-converter/v2/convert'],
        ];
    }

    /**
     * @dataProvider provideRequests
     *
     * @param array<string, string> $server
     */
    #[DataProvider('provideRequests')]
    public function testMatchesTheRoutesBelowThePrefix(array $server, string $base, string $path): void
    {
        $request = new Request([], [], [], [], [], $server);

        InstallationBase::apply($request);

        self::assertSame($base === '' ? null : $base, InstallationBase::of($request));
        self::assertSame($path, $request->getPathInfo());
    }

    /**
     * A client cannot pass the prefix: a request header becomes HTTP_*.
     */
    public function testAHeaderIsNotThePrefix(): void
    {
        $request = Request::create('https://api.example.test/webp-converter/v2/convert', 'POST', [], [], [], ['HTTP_IMAGE_CONVERSION_API_BASE' => '/webp-converter/']);

        InstallationBase::apply($request);

        self::assertNull(InstallationBase::of($request));
        self::assertSame('/webp-converter/v2/convert', $request->getPathInfo());
    }
}
