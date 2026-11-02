<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Limits;

use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Limits\RequestLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Limits\RequestLimits
 */
#[CoversClass(RequestLimits::class)]
final class RequestLimitsTest extends TestCase
{
    /**
     * @return array<string, array{string|null, string, bool}>
     */
    public static function provideBodies(): array
    {
        return [
            'no Content-Length (chunked)' => [null, 'image/png', true],
            'at the limit' => ['1000', 'image/png', true],
            'above the limit' => ['1001', 'image/png', false],
            'far above the limit' => ['99999999999999', 'application/octet-stream', false],
            'not a number' => ['abc', 'image/png', true],
            'multipart at post_max_size' => ['512', 'multipart/form-data; boundary=x', true],
            'multipart above post_max_size' => ['513', 'multipart/form-data; boundary=x', false],
            'raw body above post_max_size' => ['513', 'image/png', true],
        ];
    }

    /**
     * @dataProvider provideBodies
     */
    #[DataProvider('provideBodies')]
    public function testChecksTheDeclaredLength(?string $length, string $contentType, bool $allowed): void
    {
        $request = Request::create('/v2/convert', 'POST', [], [], [], ['CONTENT_TYPE' => $contentType]);
        $request->headers->remove('Content-Length');
        if ($length !== null) {
            $request->headers->set('Content-Length', $length);
        }

        try {
            (new RequestLimits(1000, '512'))->assertBodyAllowed($request);
            self::assertTrue($allowed, 'the body should have been refused');
        } catch (RequestException $exception) {
            self::assertFalse($allowed, 'the body should have been allowed');
            self::assertSame(413, $exception->getStatusCode());
            self::assertSame('body_too_large', $exception->getErrorCode());
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function provideIniSizes(): array
    {
        return [
            'bytes' => ['1024', 1024],
            'kilobytes' => ['8K', 8192],
            'megabytes' => ['8M', 8388608],
            'lower case' => ['2m', 2097152],
            'gigabytes' => ['1G', 1073741824],
            'spaces' => [' 16 M ', 16777216],
            'zero is no limit' => ['0', PHP_INT_MAX],
            'nonsense is no limit' => ['lots', PHP_INT_MAX],
            'overflow' => ['999999999999999G', PHP_INT_MAX],
        ];
    }

    /**
     * @dataProvider provideIniSizes
     */
    #[DataProvider('provideIniSizes')]
    public function testParsesIniSizes(string $value, int $bytes): void
    {
        self::assertSame($bytes, RequestLimits::parseIniBytes($value));
    }

    public function testReadsPostMaxSizeByDefault(): void
    {
        $limits = new RequestLimits(PHP_INT_MAX);
        $request = Request::create('/v2/convert', 'POST', [], [], [], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x']);
        $request->headers->set('Content-Length', (string) RequestLimits::parseIniBytes((string) ini_get('post_max_size')));

        $limits->assertBodyAllowed($request);

        self::assertSame(PHP_INT_MAX, $limits->getMaxBodyBytes());
    }
}
