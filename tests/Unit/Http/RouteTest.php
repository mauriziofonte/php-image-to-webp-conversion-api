<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Http;

use Mfonte\ImageConversionApi\Exception\InvalidArgumentException;
use Mfonte\ImageConversionApi\Http\Handler\NotImplementedHandler;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Http\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Http\Route
 */
#[CoversClass(Route::class)]
final class RouteTest extends TestCase
{
    public function testExposesItsDefinition(): void
    {
        $handler = new NotImplementedHandler(new ResponseFactory());
        $route = new Route('v2_convert', '/v2/convert', ['POST', 'POST'], $handler);

        self::assertSame('v2_convert', $route->getName());
        self::assertSame('/v2/convert', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
        self::assertSame($handler, $route->getHandler());
        self::assertTrue($route->allowsMethod('POST'));
        self::assertFalse($route->allowsMethod('post'));
        self::assertFalse($route->allowsMethod('GET'));
        self::assertFalse($route->isPublic());
        self::assertTrue((new Route('healthz', '/healthz', ['GET'], $handler, true))->isPublic());
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function provideInvalidDefinitions(): array
    {
        return [
            'empty name' => ['', '/a', ['GET']],
            'upper-case name' => ['V1', '/a', ['GET']],
            'relative path' => ['a', 'a', ['GET']],
            'trailing slash' => ['a', '/a/', ['GET']],
            'empty segment' => ['a', '/a//b', ['GET']],
            'placeholder' => ['a', '/v1/{name}', ['GET']],
            'percent-encoded path' => ['a', '/a%20b', ['GET']],
            'no method' => ['a', '/a', []],
            'lower-case method' => ['a', '/a', ['get']],
        ];
    }

    /**
     * @dataProvider provideInvalidDefinitions
     *
     * @param list<string> $methods
     */
    #[DataProvider('provideInvalidDefinitions')]
    public function testRefusesAMalformedDefinition(string $name, string $path, array $methods): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Route($name, $path, $methods, new NotImplementedHandler(new ResponseFactory()));
    }

    public function testTheRootPathIsAllowed(): void
    {
        self::assertSame('/', (new Route('root', '/', ['GET'], new NotImplementedHandler(new ResponseFactory())))->getPath());
    }
}
