<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Auth;

use Mfonte\ImageConversionApi\Auth\IpAllowlist;
use Mfonte\ImageConversionApi\Http\IpRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Auth\IpAllowlist
 */
#[CoversClass(IpAllowlist::class)]
final class IpAllowlistTest extends TestCase
{
    public function testAnEmptyListAllowsEveryAddress(): void
    {
        $allowlist = new IpAllowlist();

        self::assertFalse($allowlist->isEnabled());
        self::assertTrue($allowlist->allows('192.0.2.1'));
        self::assertTrue($allowlist->allows('2001:db8::1'));
        self::assertTrue($allowlist->allows(null));
    }

    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function provideAddresses(): array
    {
        return [
            'listed address' => ['198.51.100.7', true],
            'address in a listed IPv4 block' => ['10.20.30.40', true],
            'IPv4-mapped address in a listed block' => ['::ffff:10.20.30.40', true],
            'address in a listed IPv6 block' => ['2001:db8:1::1', true],
            'other IPv4 address' => ['198.51.100.8', false],
            'other IPv6 address' => ['2001:db9::1', false],
            'unknown address' => [null, false],
            'not an address' => ['unknown', false],
        ];
    }

    /**
     * @dataProvider provideAddresses
     */
    #[DataProvider('provideAddresses')]
    public function testAllowsOnlyTheListedAddresses(?string $address, bool $allowed): void
    {
        $allowlist = new IpAllowlist(IpRange::listFromString('198.51.100.7, 10.0.0.0/8, 2001:db8::/32'));

        self::assertTrue($allowlist->isEnabled());
        self::assertSame($allowed, $allowlist->allows($address));
    }
}
