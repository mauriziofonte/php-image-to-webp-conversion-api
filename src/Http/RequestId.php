<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The ID of a request, which correlates the log entries of the proxy CDN
 * and of this service (risk L-20): every log entry carries it as
 * "request_id" and every response sends it back in X-Request-Id.
 *
 * The ID the client sends in X-Request-Id is adopted when it is 1 to 128
 * characters among letters, digits and "._:/+=@-" (UUIDs, the hex IDs of
 * nginx's $request_id, the proxy's IDs, trace IDs). Otherwise, or when
 * there is none, a random one is generated: 32 hexadecimal digits. The ID
 * is a correlation token, not a credential: it is accepted from any client,
 * before authentication, so that the refusals are logged with it too. The
 * check keeps it free of spaces, quotes and line breaks, so that it can
 * never split a header or a log line.
 *
 * The ID is kept in the request attribute ATTRIBUTE, so that the front
 * controller, which builds the logger before the kernel runs, and the
 * kernel, which answers it, use the same one.
 */
final class RequestId
{
    /**
     * The header that carries the ID, in requests and responses.
     */
    public const HEADER = 'X-Request-Id';

    /**
     * Request attribute that holds the ID once of() has fixed it.
     */
    public const ATTRIBUTE = '_image_conversion_api_request_id';

    private const PATTERN = '/\A[A-Za-z0-9._:\/+=@-]{1,128}\z/';

    private function __construct()
    {
    }

    /**
     * The ID of $request: the one already fixed for it, else the valid ID
     * it carries in X-Request-Id, else a new random one; the result is
     * fixed in the request attribute ATTRIBUTE.
     */
    public static function of(Request $request): string
    {
        $fixed = $request->attributes->get(self::ATTRIBUTE);
        if (is_string($fixed) && self::isValid($fixed)) {
            return $fixed;
        }
        $sent = $request->headers->get(self::HEADER);
        $id = is_string($sent) && self::isValid($sent) ? $sent : self::generate();
        $request->attributes->set(self::ATTRIBUTE, $id);

        return $id;
    }

    /**
     * Whether $id may be adopted as a request ID.
     */
    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }

    /**
     * A new random ID: 32 hexadecimal digits (128 bits).
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
