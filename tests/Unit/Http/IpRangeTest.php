<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Http;

use Mfonte\ImageConversionApi\Exception\InvalidArgumentException;
use Mfonte\ImageConversionApi\Http\IpRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Http\IpRange
 */
#[CoversClass(IpRange::class)]
final class IpRangeTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function provideMemberships(): array
    {
        return [
            'single IPv4 address' => ['192.0.2.10', '192.0.2.10', true],
            'single IPv4 address, neighbour' => ['192.0.2.10', '192.0.2.11', false],
            'IPv4 block, first address' => ['10.0.0.0/8', '10.0.0.0', true],
            'IPv4 block, last address' => ['10.0.0.0/8', '10.255.255.255', true],
            'IPv4 block, next address' => ['10.0.0.0/8', '11.0.0.0', false],
            'IPv4 block across a byte' => ['172.16.0.0/12', '172.31.255.255', true],
            'IPv4 block across a byte, outside' => ['172.16.0.0/12', '172.32.0.0', false],
            'IPv4 block with host bits' => ['192.0.2.10/24', '192.0.2.200', true],
            'every IPv4 address' => ['0.0.0.0/0', '203.0.113.7', true],
            'every IPv4 address, not IPv6' => ['0.0.0.0/0', '2001:db8::1', false],
            'IPv4 block, IPv4-mapped client' => ['10.0.0.0/8', '::ffff:10.1.2.3', true],
            'single IPv6 address' => ['::1', '::1', true],
            'single IPv6 address, expanded form' => ['::1', '0:0:0:0:0:0:0:1', true],
            'IPv6 block' => ['2001:db8::/32', '2001:db8:ffff::1', true],
            'IPv6 block, upper case' => ['2001:db8::/32', '2001:DB8::1', true],
            'IPv6 block, leading zeros' => ['2001:db8::/32', '2001:0db8:0000::1', true],
            'IPv6 block, outside' => ['2001:db8::/32', '2001:db9::', false],
            'IPv6 block, odd prefix' => ['2001:db8::/31', '2001:db9::1', true],
            'every IPv6 address' => ['::/0', 'fe80::1', true],
            'every IPv6 address, not IPv4' => ['::/0', '192.0.2.1', false],
            'every IPv6 address, not IPv4-mapped' => ['::/0', '::ffff:192.0.2.1', false],
            'IPv4-mapped block' => ['::ffff:10.0.0.0/104', '10.200.0.1', true],
            'IPv4-mapped address' => ['::ffff:192.0.2.1', '192.0.2.1', true],
            'not an address' => ['10.0.0.0/8', 'not-an-ip', false],
            'empty address' => ['10.0.0.0/8', '', false],
            'address with a port' => ['10.0.0.0/8', '10.0.0.1:80', false],
            'address with a zone' => ['fe80::/10', 'fe80::1%eth0', false],
        ];
    }

    /**
     * @dataProvider provideMemberships
     */
    #[DataProvider('provideMemberships')]
    public function testContains(string $range, string $address, bool $expected): void
    {
        self::assertSame($expected, IpRange::fromString($range)->contains($address));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCanonicalForms(): array
    {
        return [
            'address' => [' 192.0.2.10 ', '192.0.2.10/32'],
            'host bits cleared' => ['192.0.2.10/24', '192.0.2.0/24'],
            'IPv6 compressed' => ['2001:0DB8:0000:0000::1/48', '2001:db8::/48'],
            'IPv4-mapped' => ['::ffff:10.0.0.0/104', '10.0.0.0/8'],
            'zero prefix' => ['10.1.2.3/0', '0.0.0.0/0'],
        ];
    }

    /**
     * @dataProvider provideCanonicalForms
     */
    #[DataProvider('provideCanonicalForms')]
    public function testCanonicalForm(string $range, string $canonical): void
    {
        self::assertSame($canonical, (string) IpRange::fromString($range));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidRanges(): array
    {
        return [
            'empty' => [''],
            'host name' => ['localhost'],
            'letters' => ['a.b.c.d'],
            'leading zeros' => ['010.0.0.1'],
            'five octets' => ['1.2.3.4.5'],
            'zone index' => ['fe80::1%eth0'],
            'empty prefix' => ['10.0.0.0/'],
            'prefix with a leading zero' => ['10.0.0.0/08'],
            'negative prefix' => ['10.0.0.0/-1'],
            'IPv4 prefix too long' => ['10.0.0.0/33'],
            'IPv6 prefix too long' => ['2001:db8::/129'],
            'two prefixes' => ['10.0.0.0/8/9'],
            'IPv4-mapped block shorter than /96' => ['::ffff:10.0.0.0/95'],
            'range with a port' => ['10.0.0.1:80'],
        ];
    }

    /**
     * @dataProvider provideInvalidRanges
     */
    #[DataProvider('provideInvalidRanges')]
    public function testRefusesAnInvalidRange(string $range): void
    {
        $this->expectException(InvalidArgumentException::class);

        IpRange::fromString($range);
    }

    public function testParsesACommaSeparatedList(): void
    {
        $ranges = IpRange::listFromString(' 10.0.0.0/8 , ,::1, ');

        self::assertSame(['10.0.0.0/8', '::1/128'], array_map('strval', $ranges));
        self::assertTrue(IpRange::anyContains($ranges, '10.9.8.7'));
        self::assertTrue(IpRange::anyContains($ranges, '::1'));
        self::assertFalse(IpRange::anyContains($ranges, '192.0.2.1'));
        self::assertSame([], IpRange::listFromString(''));
        self::assertFalse(IpRange::anyContains([], '10.0.0.1'));
    }

    public function testAListNamesItsInvalidEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"proxy.example" is not an IP address or a CIDR block');

        IpRange::listFromString('10.0.0.0/8,proxy.example');
    }
}
