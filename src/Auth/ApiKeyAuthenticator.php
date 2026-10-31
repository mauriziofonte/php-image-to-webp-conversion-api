<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Auth;

use Mfonte\ImageConversionApi\Exception\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Checks the API key of a request against the configured keys (findings
 * C-1 and C-4).
 *
 * The key is read from the first of these that the request carries:
 *
 * 1. "Authorization: Bearer <key>" (scheme case-insensitive). PHP sees the
 *    header as HTTP_AUTHORIZATION, or, under Apache with CGI or PHP-FPM
 *    where the header is passed by a mod_rewrite rule, only as
 *    REDIRECT_HTTP_AUTHORIZATION. Another scheme (such as Basic, used by
 *    the web server itself) does not count as a key;
 * 2. "X-API-Key: <key>", the 1.x header. Header names are case-insensitive
 *    (Apache passes X-API-Key, PHP-FPM reports X-Api-Key, both reach PHP as
 *    HTTP_X_API_KEY);
 * 3. "-x-api-key: <key>", what the 1.x .env.example told clients to send
 *    and the 2.x proxy sends (HTTP__X_API_KEY).
 *
 * Only that first credential is checked. It is compared with every
 * configured key, several of them allowing rotation, with hash_equals()
 * over HMAC-SHA256 digests computed with a key drawn at construction: every
 * comparison runs, on values of one length, so neither the time taken nor
 * an early exit reveals whether, or which, key matched, nor its length.
 *
 * Fail closed: with no key configured every request is refused as
 * NOT_CONFIGURED (a server misconfiguration, answered 503), never let
 * through, whatever it carries. 1.x let a request without a header in when
 * API_KEY was missing too (C-4).
 *
 * Every configured key is at least MINIMUM_KEY_LENGTH characters long: a
 * shorter key can be guessed faster than a per-key rate limit slows the
 * guessing down.
 */
final class ApiKeyAuthenticator
{
    /** The request carries one of the configured keys. */
    public const AUTHENTICATED = 'authenticated';

    /** No key is configured: every request is refused. */
    public const NOT_CONFIGURED = 'authentication_not_configured';

    /** The request carries no key. */
    public const MISSING_KEY = 'missing_api_key';

    /** The request carries a key that is not configured. */
    public const INVALID_KEY = 'invalid_api_key';

    /**
     * Shortest accepted key, in characters (counted in bytes, after trimming).
     */
    public const MINIMUM_KEY_LENGTH = 16;

    private const KEY_HEADERS = ['X-API-Key', '-X-API-Key'];

    /**
     * @var string
     */
    private $hmacKey;

    /**
     * @var list<string> raw HMAC-SHA256 digests of the configured keys
     */
    private $digests = [];

    /**
     * @param list<string> $keys the accepted keys, compared after trimming; empty refuses every request
     *
     * @throws InvalidArgumentException when a key is shorter than MINIMUM_KEY_LENGTH (blank included)
     */
    public function __construct(array $keys)
    {
        $this->hmacKey = random_bytes(32);
        foreach ($keys as $key) {
            $key = trim($key);
            if (strlen($key) < self::MINIMUM_KEY_LENGTH) {
                throw new InvalidArgumentException(sprintf('An API key must be at least %d characters long', self::MINIMUM_KEY_LENGTH));
            }
            $this->digests[] = $this->digest($key);
        }
    }

    /**
     * Whether at least one key is configured.
     */
    public function isConfigured(): bool
    {
        return $this->digests !== [];
    }

    /**
     * @return string one of AUTHENTICATED, NOT_CONFIGURED, MISSING_KEY and INVALID_KEY
     */
    public function authenticate(Request $request): string
    {
        if ($this->digests === []) {
            return self::NOT_CONFIGURED;
        }
        $presented = self::presentedKey($request);
        if ($presented === null) {
            return self::MISSING_KEY;
        }

        $digest = $this->digest($presented);
        $matched = false;
        foreach ($this->digests as $known) {
            // hash_equals() first, so that it runs for every key even after a match.
            $matched = hash_equals($known, $digest) || $matched;
        }

        return $matched ? self::AUTHENTICATED : self::INVALID_KEY;
    }

    /**
     * The key the request presents, trimmed; null when it presents none.
     */
    private static function presentedKey(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization');
        if ($authorization === null || trim($authorization) === '') {
            $redirected = $request->server->get('REDIRECT_HTTP_AUTHORIZATION');
            $authorization = is_string($redirected) ? $redirected : null;
        }
        if ($authorization !== null && preg_match('/\A\s*Bearer(?:\s+(.*))?\z/is', $authorization, $match) === 1) {
            $key = trim($match[1] ?? '');

            return $key === '' ? null : $key;
        }

        foreach (self::KEY_HEADERS as $header) {
            $key = trim((string) $request->headers->get($header));
            if ($key !== '') {
                return $key;
            }
        }

        return null;
    }

    private function digest(string $key): string
    {
        return hash_hmac('sha256', $key, $this->hmacKey, true);
    }
}
