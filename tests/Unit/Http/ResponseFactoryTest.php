<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Http;

use Mfonte\ImageConversionApi\Http\ResponseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Http\ResponseFactory
 */
#[CoversClass(ResponseFactory::class)]
final class ResponseFactoryTest extends TestCase
{
    /**
     * @return array<string, array{string, int, string}>
     */
    public static function provideErrors(): array
    {
        return [
            'not found' => ['notFound', 404, ResponseFactory::ERROR_NOT_FOUND],
            'HTTPS required' => ['httpsRequired', 400, ResponseFactory::ERROR_HTTPS_REQUIRED],
            'not implemented' => ['notImplemented', 501, ResponseFactory::ERROR_NOT_IMPLEMENTED],
            'internal error' => ['internalError', 500, ResponseFactory::ERROR_INTERNAL],
            'invalid configuration' => ['invalidConfiguration', 503, ResponseFactory::ERROR_INVALID_CONFIGURATION],
            'authentication not configured' => ['authenticationNotConfigured', 503, ResponseFactory::ERROR_AUTHENTICATION_NOT_CONFIGURED],
            'missing API key' => ['missingApiKey', 401, ResponseFactory::ERROR_MISSING_API_KEY],
            'invalid API key' => ['invalidApiKey', 401, ResponseFactory::ERROR_INVALID_API_KEY],
            'IP not allowed' => ['ipNotAllowed', 403, ResponseFactory::ERROR_IP_NOT_ALLOWED],
        ];
    }

    /**
     * @dataProvider provideErrors
     */
    #[DataProvider('provideErrors')]
    public function testErrorsCarryAStatusAndAStableCode(string $method, int $status, string $code): void
    {
        $response = (new ResponseFactory())->{$method}();
        self::assertInstanceOf(Response::class, $response);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame($code, self::decode($response)['error']['code']);
        self::assertIsString(self::decode($response)['error']['message']);
        self::assertCommonHeaders($response);
    }

    public function testMethodNotAllowedListsTheAllowedMethods(): void
    {
        $response = (new ResponseFactory())->methodNotAllowed(['GET', 'HEAD']);

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->headers->get('Allow'));
        self::assertSame(ResponseFactory::ERROR_METHOD_NOT_ALLOWED, self::decode($response)['error']['code']);
    }

    public function testUnauthorizedResponsesCarryTheBearerChallenge(): void
    {
        $factory = new ResponseFactory();

        self::assertSame('Bearer realm="image-conversion-api"', $factory->missingApiKey()->headers->get('WWW-Authenticate'));
        self::assertSame('Bearer realm="image-conversion-api", error="invalid_token"', $factory->invalidApiKey()->headers->get('WWW-Authenticate'));
    }

    public function testErrorBodyShape(): void
    {
        $response = (new ResponseFactory())->error(422, 'invalid_image', 'The image is malformed.', ['Retry-After' => '5']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('{"error":{"code":"invalid_image","message":"The image is malformed."}}', $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('5', $response->headers->get('Retry-After'));
        self::assertCommonHeaders($response);
    }

    public function testJsonEscapesHtmlSensitiveCharacters(): void
    {
        $response = (new ResponseFactory())->json(['value' => '<script>&\'"']);

        self::assertSame('{"value":"\u003Cscript\u003E\u0026\u0027\u0022"}', $response->getContent());
    }

    public function testPrometheusExposition(): void
    {
        $response = (new ResponseFactory())->prometheus("metric 1\n");

        self::assertSame(200, $response->getStatusCode());
        self::assertSame("metric 1\n", $response->getContent());
        self::assertSame(ResponseFactory::PROMETHEUS_CONTENT_TYPE, $response->headers->get('Content-Type'));
        self::assertCommonHeaders($response);
    }

    private static function assertCommonHeaders(Response $response): void
    {
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * @return array{error: array{code: string, message: mixed}}
     */
    private static function decode(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['error'] ?? null);
        self::assertIsString($data['error']['code'] ?? null);

        /** @var array{error: array{code: string, message: mixed}} $data */
        return $data;
    }
}
