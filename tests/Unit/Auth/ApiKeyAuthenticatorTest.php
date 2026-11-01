<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Auth;

use Mfonte\ImageConversionApi\Auth\ApiKeyAuthenticator;
use Mfonte\ImageConversionApi\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The header shapes that nginx with PHP-FPM and Apache with CGI or PHP-FPM
 * produce, wrong keys, key rotation and the missing configuration (findings
 * C-1 and C-4). Apache passes Authorization to CGI or PHP-FPM only with
 * CGIPassAuth On (HTTP_AUTHORIZATION) or a rewrite rule that sets
 * E=HTTP_AUTHORIZATION (REDIRECT_HTTP_AUTHORIZATION after an internal
 * redirect): the public/.htaccess of API-8 must pass it one of these ways.
 *
 * @covers \Mfonte\ImageConversionApi\Auth\ApiKeyAuthenticator
 */
#[CoversClass(ApiKeyAuthenticator::class)]
final class ApiKeyAuthenticatorTest extends TestCase
{
    private const KEY = 'an example api key, very secret';

    private const NEXT_KEY = 'f4c3b00c-rotated-key';

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function provideHeaderShapes(): array
    {
        return [
            'Bearer, HTTP_AUTHORIZATION (nginx and PHP-FPM, Apache with CGIPassAuth On)' => [['HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY]],
            'Bearer, only REDIRECT_HTTP_AUTHORIZATION (Apache rewrite rule with E=HTTP_AUTHORIZATION, internal redirect)' => [['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY]],
            'Bearer, empty HTTP_AUTHORIZATION next to REDIRECT_HTTP_AUTHORIZATION' => [['HTTP_AUTHORIZATION' => '', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY]],
            'bearer in lower case' => [['HTTP_AUTHORIZATION' => 'bearer ' . self::KEY]],
            'BEARER with extra blanks' => [['HTTP_AUTHORIZATION' => " BEARER \t " . self::KEY . '  ']],
            'X-API-Key or X-Api-Key, both HTTP_X_API_KEY' => [['HTTP_X_API_KEY' => self::KEY]],
            'X-API-Key with blanks' => [['HTTP_X_API_KEY' => ' ' . self::KEY . ' ']],
            '-x-api-key (2.x proxy, 1.x .env.example)' => [['HTTP__X_API_KEY' => self::KEY]],
            'Basic credentials for the web server, key in X-API-Key' => [['HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz', 'PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'pass', 'HTTP_X_API_KEY' => self::KEY]],
        ];
    }

    /**
     * @dataProvider provideHeaderShapes
     *
     * @param array<string, string> $server
     */
    #[DataProvider('provideHeaderShapes')]
    public function testAcceptsTheKeyInEveryHeaderShape(array $server): void
    {
        self::assertSame(ApiKeyAuthenticator::AUTHENTICATED, self::authenticator()->authenticate(self::request($server)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideHeaderNames(): array
    {
        return [
            'as documented' => ['X-API-Key'],
            'as PHP-FPM reports it' => ['X-Api-Key'],
            'lower case' => ['x-api-key'],
            'upper case' => ['X-API-KEY'],
            'the 1.x proxy name' => ['-x-api-key'],
            'Authorization, lower case' => ['authorization'],
        ];
    }

    /**
     * Header names are case-insensitive however the request was built.
     *
     * @dataProvider provideHeaderNames
     */
    #[DataProvider('provideHeaderNames')]
    public function testHeaderNamesAreCaseInsensitive(string $name): void
    {
        $request = self::request([]);
        $request->headers->set($name, strtolower($name) === 'authorization' ? 'Bearer ' . self::KEY : self::KEY);

        self::assertSame(ApiKeyAuthenticator::AUTHENTICATED, self::authenticator()->authenticate($request));
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function provideRefusedCredentials(): array
    {
        return [
            'no header' => [[], ApiKeyAuthenticator::MISSING_KEY],
            'empty X-API-Key' => [['HTTP_X_API_KEY' => ''], ApiKeyAuthenticator::MISSING_KEY],
            'blank X-API-Key' => [['HTTP_X_API_KEY' => '   '], ApiKeyAuthenticator::MISSING_KEY],
            'Bearer without a key' => [['HTTP_AUTHORIZATION' => 'Bearer'], ApiKeyAuthenticator::MISSING_KEY],
            'Bearer with a blank key' => [['HTTP_AUTHORIZATION' => 'Bearer   '], ApiKeyAuthenticator::MISSING_KEY],
            'another scheme' => [['HTTP_AUTHORIZATION' => 'Token ' . self::KEY], ApiKeyAuthenticator::MISSING_KEY],
            'scheme glued to the key' => [['HTTP_AUTHORIZATION' => 'Bearer' . self::KEY], ApiKeyAuthenticator::MISSING_KEY],
            'key in the wrong header' => [['HTTP_X_API_TOKEN' => self::KEY], ApiKeyAuthenticator::MISSING_KEY],
            'key in the query string' => [['QUERY_STRING' => 'api_key=' . rawurlencode(self::KEY)], ApiKeyAuthenticator::MISSING_KEY],
            'wrong key' => [['HTTP_X_API_KEY' => 'guess'], ApiKeyAuthenticator::INVALID_KEY],
            'prefix of the key' => [['HTTP_X_API_KEY' => substr(self::KEY, 0, -1)], ApiKeyAuthenticator::INVALID_KEY],
            'key with a suffix' => [['HTTP_X_API_KEY' => self::KEY . 'x'], ApiKeyAuthenticator::INVALID_KEY],
            'key in another case' => [['HTTP_X_API_KEY' => strtoupper(self::KEY)], ApiKeyAuthenticator::INVALID_KEY],
            'wrong Bearer key' => [['HTTP_AUTHORIZATION' => 'Bearer guess'], ApiKeyAuthenticator::INVALID_KEY],
            'wrong Bearer key next to a right X-API-Key' => [['HTTP_AUTHORIZATION' => 'Bearer guess', 'HTTP_X_API_KEY' => self::KEY], ApiKeyAuthenticator::INVALID_KEY],
            'wrong X-API-Key next to a right -x-api-key' => [['HTTP_X_API_KEY' => 'guess', 'HTTP__X_API_KEY' => self::KEY], ApiKeyAuthenticator::INVALID_KEY],
        ];
    }

    /**
     * Only the first credential the request carries is checked, so a
     * request cannot try several keys at once.
     *
     * @dataProvider provideRefusedCredentials
     *
     * @param array<string, string> $server
     */
    #[DataProvider('provideRefusedCredentials')]
    public function testRefusesMissingAndWrongKeys(array $server, string $outcome): void
    {
        self::assertSame($outcome, self::authenticator()->authenticate(self::request($server)));
    }

    public function testAcceptsEveryConfiguredKeyForRotation(): void
    {
        $authenticator = new ApiKeyAuthenticator([self::KEY, self::NEXT_KEY]);
        $retired = new ApiKeyAuthenticator([self::NEXT_KEY]);

        self::assertSame(ApiKeyAuthenticator::AUTHENTICATED, $authenticator->authenticate(self::request(['HTTP_X_API_KEY' => self::KEY])));
        self::assertSame(ApiKeyAuthenticator::AUTHENTICATED, $authenticator->authenticate(self::request(['HTTP_X_API_KEY' => self::NEXT_KEY])));
        self::assertSame(ApiKeyAuthenticator::INVALID_KEY, $retired->authenticate(self::request(['HTTP_X_API_KEY' => self::KEY])));
    }

    /**
     * Finding C-4: in 1.x a missing API_KEY and a missing header compared
     * equal and authenticated.
     *
     * @return array<string, array{array<string, string>}>
     */
    public static function provideAnyCredentials(): array
    {
        return [
            'no header' => [[]],
            'empty X-API-Key' => [['HTTP_X_API_KEY' => '']],
            'any key' => [['HTTP_X_API_KEY' => self::KEY]],
            'any Bearer key' => [['HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY]],
        ];
    }

    /**
     * @dataProvider provideAnyCredentials
     *
     * @param array<string, string> $server
     */
    #[DataProvider('provideAnyCredentials')]
    public function testFailsClosedWithoutAConfiguredKey(array $server): void
    {
        $authenticator = new ApiKeyAuthenticator([]);

        self::assertFalse($authenticator->isConfigured());
        self::assertSame(ApiKeyAuthenticator::NOT_CONFIGURED, $authenticator->authenticate(self::request($server)));
    }

    public function testRefusesABlankKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApiKeyAuthenticator([self::KEY, ' ']);
    }

    public function testRefusesAKeyShorterThanTheMinimum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An API key must be at least 16 characters long');

        new ApiKeyAuthenticator([self::KEY, ' ' . str_repeat('k', ApiKeyAuthenticator::MINIMUM_KEY_LENGTH - 1) . ' ']);
    }

    public function testAcceptsAKeyOfTheMinimumLength(): void
    {
        $key = str_repeat('k', ApiKeyAuthenticator::MINIMUM_KEY_LENGTH);

        $authenticator = new ApiKeyAuthenticator([$key]);

        self::assertSame(ApiKeyAuthenticator::AUTHENTICATED, $authenticator->authenticate(self::request(['HTTP_X_API_KEY' => $key])));
    }

    public function testConfiguredKeysAreTrimmed(): void
    {
        $authenticator = new ApiKeyAuthenticator(['  ' . self::KEY . "\n"]);

        self::assertTrue($authenticator->isConfigured());
        self::assertSame(ApiKeyAuthenticator::AUTHENTICATED, $authenticator->authenticate(self::request(['HTTP_X_API_KEY' => self::KEY])));
    }

    /**
     * The identifier of a key is the same in every process and for every
     * header shape, differs between keys, and does not contain the key.
     */
    public function testIdentifiesTheKeyForPerKeyLimits(): void
    {
        $bearer = self::authenticator()->clientId(self::request(['HTTP_AUTHORIZATION' => 'Bearer ' . self::KEY]));
        $header = (new ApiKeyAuthenticator([self::KEY]))->clientId(self::request(['HTTP_X_API_KEY' => self::KEY]));
        $other = self::authenticator()->clientId(self::request(['HTTP_X_API_KEY' => self::NEXT_KEY]));

        self::assertIsString($bearer);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $bearer);
        self::assertSame($bearer, $header);
        self::assertNotSame($bearer, $other);
        self::assertNotSame(substr(hash('sha256', self::KEY), 0, 32), $bearer, 'no plain digest of the key');
        self::assertNull(self::authenticator()->clientId(self::request([])));
    }

    private static function authenticator(): ApiKeyAuthenticator
    {
        return new ApiKeyAuthenticator([self::NEXT_KEY, self::KEY]);
    }

    /**
     * A request built from $server only, as PHP fills $_SERVER.
     *
     * @param array<string, string> $server
     */
    private static function request(array $server): Request
    {
        return new Request([], [], [], [], [], $server + ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/v2/convert', 'HTTPS' => 'on']);
    }
}
