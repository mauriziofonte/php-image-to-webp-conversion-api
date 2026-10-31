<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the responses the API produces itself: JSON documents, errors and
 * the Prometheus text exposition.
 *
 * Every error has a stable, machine-readable code, the same kind of code
 * the conversion library exposes with getErrorCode(), and a message for
 * humans that may change. The body of an error is
 *
 *     {"error": {"code": "not_found", "message": "No endpoint has this path."}}
 *
 * Messages are fixed sentences: they never echo request data. Every response
 * built here carries "Cache-Control: no-store" and "X-Content-Type-Options:
 * nosniff". The responses are returned, never sent.
 */
final class ResponseFactory
{
    /** 404: no route has the request path. */
    public const ERROR_NOT_FOUND = 'not_found';

    /** 405: the route exists but does not answer the request method; the Allow header lists the methods it answers. */
    public const ERROR_METHOD_NOT_ALLOWED = 'method_not_allowed';

    /** 400: the route is protected and the request did not reach the service over HTTPS. */
    public const ERROR_HTTPS_REQUIRED = 'https_required';

    /** 501: the endpoint exists but is not implemented in this version. */
    public const ERROR_NOT_IMPLEMENTED = 'not_implemented';

    /** 500: an unexpected error; the details are in the log, never in the response. */
    public const ERROR_INTERNAL = 'internal_error';

    /** 503: the configuration cannot be loaded; the details are in the log, never in the response. */
    public const ERROR_INVALID_CONFIGURATION = 'invalid_configuration';

    /**
     * 503: no API key is configured, so every protected request is refused (fail closed). A server
     * misconfiguration, not a client error: retrying with other credentials cannot help.
     */
    public const ERROR_AUTHENTICATION_NOT_CONFIGURED = 'authentication_not_configured';

    /** 401: the request carries no API key. */
    public const ERROR_MISSING_API_KEY = 'missing_api_key';

    /** 401: the request carries an API key that is not accepted. */
    public const ERROR_INVALID_API_KEY = 'invalid_api_key';

    /**
     * Media type of the Prometheus text exposition format 0.0.4.
     */
    public const PROMETHEUS_CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    private const REALM = 'image-conversion-api';

    private const COMMON_HEADERS = [
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    /**
     * A JSON document.
     *
     * @param array<mixed>          $data    encoded with JsonResponse's default flags (HTML-sensitive characters escaped)
     * @param array<string, string> $headers added to, and overriding, the common headers
     */
    public function json(array $data, int $status = 200, array $headers = []): Response
    {
        return new JsonResponse($data, $status, array_merge(self::COMMON_HEADERS, $headers));
    }

    /**
     * A JSON error.
     *
     * @param int                   $status  HTTP status, 400 to 599
     * @param string                $code    stable machine-readable code (snake_case)
     * @param string                $message fixed sentence for humans, never request data
     * @param array<string, string> $headers added to, and overriding, the common headers
     */
    public function error(int $status, string $code, string $message, array $headers = []): Response
    {
        return $this->json(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }

    public function notFound(): Response
    {
        return $this->error(404, self::ERROR_NOT_FOUND, 'No endpoint has this path.');
    }

    /**
     * @param list<string> $allowedMethods the methods of the route, for the Allow header
     */
    public function methodNotAllowed(array $allowedMethods): Response
    {
        return $this->error(
            405,
            self::ERROR_METHOD_NOT_ALLOWED,
            'This endpoint does not answer this method.',
            ['Allow' => implode(', ', $allowedMethods)]
        );
    }

    public function httpsRequired(): Response
    {
        return $this->error(400, self::ERROR_HTTPS_REQUIRED, 'This endpoint must be called over HTTPS.');
    }

    public function notImplemented(): Response
    {
        return $this->error(501, self::ERROR_NOT_IMPLEMENTED, 'This endpoint is not implemented yet.');
    }

    public function internalError(): Response
    {
        return $this->error(500, self::ERROR_INTERNAL, 'The request could not be processed.');
    }

    public function invalidConfiguration(): Response
    {
        return $this->error(503, self::ERROR_INVALID_CONFIGURATION, 'The service is not configured correctly.');
    }

    public function authenticationNotConfigured(): Response
    {
        return $this->error(503, self::ERROR_AUTHENTICATION_NOT_CONFIGURED, 'The service has no API key configured.');
    }

    /**
     * 401 for a request without an API key, with the WWW-Authenticate challenge of RFC 6750.
     */
    public function missingApiKey(): Response
    {
        return $this->error(
            401,
            self::ERROR_MISSING_API_KEY,
            'Send an API key as "Authorization: Bearer <key>" or in the X-API-Key header.',
            ['WWW-Authenticate' => 'Bearer realm="' . self::REALM . '"']
        );
    }

    /**
     * 401 for a request with an API key that is not accepted, with the WWW-Authenticate challenge of RFC 6750.
     */
    public function invalidApiKey(): Response
    {
        return $this->error(
            401,
            self::ERROR_INVALID_API_KEY,
            'The API key is not valid.',
            ['WWW-Authenticate' => 'Bearer realm="' . self::REALM . '", error="invalid_token"']
        );
    }

    /**
     * A Prometheus text exposition.
     *
     * @param string $body metric families in the text format 0.0.4, ending with a line feed
     */
    public function prometheus(string $body): Response
    {
        return new Response($body, 200, array_merge(self::COMMON_HEADERS, ['Content-Type' => self::PROMETHEUS_CONTENT_TYPE]));
    }
}
