<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Http;

use Mfonte\ImageConversionApi\Http\IpRange;
use Mfonte\ImageConversionApi\Http\TrustedProxies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Http\TrustedProxies
 */
#[CoversClass(TrustedProxies::class)]
final class TrustedProxiesTest extends TestCase
{
    private const PROXIES = '10.0.0.0/8, 2001:db8::/32';

    public function testTrustsOnlyTheConfiguredRanges(): void
    {
        $proxies = self::proxies();

        self::assertTrue($proxies->isTrusted('10.1.2.3'));
        self::assertTrue($proxies->isTrusted('::ffff:10.1.2.3'));
        self::assertTrue($proxies->isTrusted('2001:db8::5'));
        self::assertFalse($proxies->isTrusted('192.0.2.1'));
        self::assertFalse($proxies->isTrusted('garbage'));
        self::assertFalse($proxies->isTrusted(null));
        self::assertFalse((new TrustedProxies())->isTrusted('10.1.2.3'), 'nobody is trusted by default');
    }

    /**
     * @return array<string, array{array<string, string>, bool}>
     */
    public static function provideSchemes(): array
    {
        return [
            'HTTPS on' => [['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => 'on'], true],
            'HTTPS 1' => [['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => '1'], true],
            'HTTPS off' => [['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => 'off'], false],
            'HTTPS OFF' => [['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => 'OFF'], false],
            'HTTPS empty' => [['REMOTE_ADDR' => '192.0.2.1', 'HTTPS' => ''], false],
            'no HTTPS' => [['REMOTE_ADDR' => '192.0.2.1'], false],
            'untrusted peer sends https' => [['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_PROTO' => 'https'], false],
            'trusted peer sends https' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'], true],
            'trusted IPv6 peer sends HTTPS' => [['REMOTE_ADDR' => '2001:db8::5', 'HTTP_X_FORWARDED_PROTO' => 'HTTPS'], true],
            'trusted IPv4-mapped peer' => [['REMOTE_ADDR' => '::ffff:10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'], true],
            'trusted peer sends http over its own TLS' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTPS' => 'on', 'HTTP_X_FORWARDED_PROTO' => 'http'], false],
            'trusted peer, chain appended http' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https, http'], false],
            'trusted peer, chain of https' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https,https'], true],
            'trusted peer sends an unknown value' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'wss'], false],
            'trusted peer without the header' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTPS' => 'on'], true],
            'trusted peer with an empty header' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => ' , '], false],
        ];
    }

    /**
     * @dataProvider provideSchemes
     *
     * @param array<string, string> $server
     */
    #[DataProvider('provideSchemes')]
    public function testIsSecure(array $server, bool $secure): void
    {
        self::assertSame($secure, self::proxies()->isSecure(self::request($server)));
    }

    public function testEveryOccurrenceOfTheHeaderCounts(): void
    {
        $request = self::request(['REMOTE_ADDR' => '10.0.0.5']);
        $request->headers->set('X-Forwarded-Proto', ['https', 'http']);

        self::assertFalse(self::proxies()->isSecure($request));
    }

    /**
     * @return array<string, array{array<string, string>, string|null}>
     */
    public static function provideClientAddresses(): array
    {
        return [
            'direct client' => [['REMOTE_ADDR' => '192.0.2.1'], '192.0.2.1'],
            'direct client sends X-Forwarded-For' => [['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1'], '192.0.2.1'],
            'trusted peer without X-Forwarded-For' => [['REMOTE_ADDR' => '10.0.0.5'], '10.0.0.5'],
            'one proxy' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], '198.51.100.7'],
            'two proxies' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.9'], '198.51.100.7'],
            'spoofed entries on the left' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 203.0.113.9, 198.51.100.7'], '198.51.100.7'],
            'every entry trusted' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '10.0.0.1,10.0.0.2'], '10.0.0.1'],
            'IPv4 with a port' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7:51234'], '198.51.100.7'],
            'IPv6 in brackets with a port' => [['REMOTE_ADDR' => '2001:db8::5', 'HTTP_X_FORWARDED_FOR' => '[2001:db9::7]:443'], '2001:db9::7'],
            'IPv6 in brackets' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '[2001:db9::7]'], '2001:db9::7'],
            'garbage where the proxy writes' => [['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, unknown'], null],
            'no REMOTE_ADDR' => [[], null],
        ];
    }

    /**
     * @dataProvider provideClientAddresses
     *
     * @param array<string, string> $server
     */
    #[DataProvider('provideClientAddresses')]
    public function testGetClientAddress(array $server, ?string $client): void
    {
        self::assertSame($client, self::proxies()->getClientAddress(self::request($server)));
    }

    /**
     * Symfony's static trusted proxies stay untouched, so the rules apply to
     * this object only.
     */
    public function testLeavesSymfonysProcessWideSettingsAlone(): void
    {
        $before = Request::getTrustedProxies();
        $request = self::request(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https']);

        self::assertTrue(self::proxies()->isSecure($request));
        self::assertSame($before, Request::getTrustedProxies());
        self::assertFalse($request->isSecure());
    }

    private static function proxies(): TrustedProxies
    {
        return new TrustedProxies(IpRange::listFromString(self::PROXIES));
    }

    /**
     * A request built from $server only: Request::create() would add
     * REMOTE_ADDR and HTTPS values of its own.
     *
     * @param array<string, string> $server
     */
    private static function request(array $server): Request
    {
        return new Request([], [], [], [], [], $server + ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/v2/convert']);
    }
}
