<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Http;

use Mfonte\ImageConversionApi\Exception\InvalidArgumentException;
use Mfonte\ImageConversionApi\Http\Handler\NotImplementedHandler;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Http\Route;
use Mfonte\ImageConversionApi\Http\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Http\Router
 */
#[CoversClass(Router::class)]
final class RouterTest extends TestCase
{
    public function testMatchesTheExactPath(): void
    {
        $convert = self::route('v2_convert', '/v2/convert');
        $health = self::route('healthz', '/healthz');
        $router = new Router([$convert, $health]);

        self::assertSame($convert, $router->match('/v2/convert'));
        self::assertSame($health, $router->match('/healthz'));
        self::assertSame([$convert, $health], $router->getRoutes());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideNearMisses(): array
    {
        return [
            'trailing slash' => ['/v2/convert/'],
            'upper case' => ['/V2/Convert'],
            'percent-encoded' => ['/v2/%63onvert'],
            'prefix' => ['/v2'],
            'longer path' => ['/v2/convert/extra'],
            'dot segment' => ['/v2/./convert'],
            'root' => ['/'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider provideNearMisses
     */
    #[DataProvider('provideNearMisses')]
    public function testMatchesNothingElse(string $path): void
    {
        self::assertNull((new Router([self::route('v2_convert', '/v2/convert')]))->match($path));
    }

    public function testRefusesTwoRoutesWithOnePath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Router([self::route('a', '/same'), self::route('b', '/same')]);
    }

    public function testRefusesTwoRoutesWithOneName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Router([self::route('same', '/a'), self::route('same', '/b')]);
    }

    private static function route(string $name, string $path): Route
    {
        return new Route($name, $path, ['GET'], new NotImplementedHandler(new ResponseFactory()));
    }
}
