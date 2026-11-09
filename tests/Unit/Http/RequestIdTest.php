<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Http;

use Mfonte\ImageConversionApi\Http\RequestId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Http\RequestId
 */
#[CoversClass(RequestId::class)]
final class RequestIdTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function provideAdoptedIds(): array
    {
        return [
            'uuid' => ['0f8c2e4a-9b1d-4e7f-8a2b-3c4d5e6f7a8b'],
            'nginx $request_id' => ['3f2504e04f8941d39a0c0305e82c3301'],
            'trace header' => ['Root=1-67891233-abcdef012345678912345678'],
            'one character' => ['a'],
            '128 characters' => [str_repeat('x', 128)],
            'every allowed character' => ['aZ09._:/+=@-'],
        ];
    }

    /**
     * @dataProvider provideAdoptedIds
     */
    #[DataProvider('provideAdoptedIds')]
    public function testAdoptsAValidIdOfTheClient(string $id): void
    {
        $request = Request::create('/healthz', 'GET', [], [], [], ['HTTP_X_REQUEST_ID' => $id]);

        self::assertSame($id, RequestId::of($request));
        self::assertSame($id, $request->attributes->get(RequestId::ATTRIBUTE));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideReplacedIds(): array
    {
        return [
            'empty' => [''],
            'too long' => [str_repeat('x', 129)],
            'space' => ['two words'],
            'quote' => ['a"b'],
            'line break' => ["a\nb"],
            'control character' => ["a\x01b"],
            'non-ASCII' => ['caffè'],
            'braces' => ['{level}'],
        ];
    }

    /**
     * @dataProvider provideReplacedIds
     */
    #[DataProvider('provideReplacedIds')]
    public function testReplacesAnIdThatIsNotAcceptable(string $id): void
    {
        $request = Request::create('/healthz', 'GET', [], [], [], ['HTTP_X_REQUEST_ID' => $id]);

        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', RequestId::of($request));
    }

    public function testTheIdIsFixedForTheRequest(): void
    {
        $request = Request::create('/healthz');

        $first = RequestId::of($request);

        self::assertSame($first, RequestId::of($request));
        self::assertNotSame($first, RequestId::of(Request::create('/healthz')));
    }

    public function testGeneratesRandomHexIds(): void
    {
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', RequestId::generate());
        self::assertNotSame(RequestId::generate(), RequestId::generate());
        self::assertTrue(RequestId::isValid(RequestId::generate()));
    }
}
