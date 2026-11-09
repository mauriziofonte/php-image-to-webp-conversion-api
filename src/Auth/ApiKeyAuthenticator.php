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
 * 1. "Authorization: Bearer <key>" (scheme case-insensitive), from
 *    HTTP_AUTHORIZATION or REDIRECT_HTTP_AUTHORIZATION. nginx passes every
 *    request header to PHP-FPM, this one as HTTP_AUTHORIZATION. Apache does
 *    not pass Authorization to CGI or PHP-FPM unless "CGIPassAuth On"
 *    (Apache 2.4.13 and later; PHP then sees HTTP_AUTHORIZATION) or a
 *    rewrite rule such as
 *    "RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]" passes
 *    it; after the internal redirect of a front-controller rewrite, the
 *    variable that rule sets reaches PHP as REDIRECT_HTTP_AUTHORIZATION.
 *    The public/.htaccess of API-8 must pass it one of these ways.
 *    Another scheme (such as Basic, used by the web server itself) does not
 *    count as a key;
 * 2. "X-API-Key: <key>", the 1.x header, which every web server passes
 *    without configuration. Header names are case-insensitive (Apache
 *    passes X-API-Key, PHP-FPM reports X-Api-Key, both reach PHP as
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
 * Every configured key is at least MINIMUM_KEY_LENGTH bytes long: a
 * shorter key can be guessed faster than a per-key rate limit slows the
 * guessing down.
 *
 * A key may be configured as its SHA-256 digest instead,
 * "sha256:<64 hexadecimal digits>" (DIGEST_PREFIX), so that the key
 * itself is not held by the configuration: the environment of PHP-FPM
 * can be read by the processes of its user, conversions included when
 * they run without the sandbox isolation. Such an entry is never taken
 * as a key; the length of the key behind it cannot be checked. An entry
 * that starts with the prefix but is no such digest is refused, never
 * taken as a key: a mistyped digest must not become a credential.
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
     * Shortest accepted key, in bytes (after trimming): a multibyte
     * character counts for each of its bytes.
     */
    public const MINIMUM_KEY_LENGTH = 16;

    /**
     * Prefix of a key configured as the SHA-256 digest of the key, in
     * hexadecimal: printf '%s' "$KEY" | sha256sum.
     */
    public const DIGEST_PREFIX = 'sha256:';

    private const KEY_HEADERS = ['X-API-Key', '-X-API-Key'];

    /**
     * Prefixed to a key before it is hashed into a client identifier, so that
     * the identifier is no plain SHA-256 of the key.
     */
    private const CLIENT_ID_CONTEXT = "image-conversion-api client\n";

    /**
     * @var string
     */
    private $hmacKey;

    /**
     * @var list<string> raw HMAC-SHA256 digests of the SHA-256 digests of the configured keys
     */
    private $digests = [];

    /**
     * @param list<string> $keys the accepted keys, compared after trimming, or their digests
     *                           ("sha256:<hex>"); empty refuses every request
     *
     * @throws InvalidArgumentException when a key is shorter than MINIMUM_KEY_LENGTH (blank included), or starts
     *                                  with DIGEST_PREFIX without being a digest
     */
    public function __construct(array $keys)
    {
        $this->hmacKey = random_bytes(32);
        foreach ($keys as $key) {
            $key = trim($key);
            if (self::isMalformedDigest($key)) {
                throw new InvalidArgumentException(sprintf('An API key that starts with "%s" must be followed by exactly 64 hexadecimal digits', self::DIGEST_PREFIX));
            }
            $sha256 = self::configuredDigest($key);
            if ($sha256 === null) {
                if (strlen($key) < self::MINIMUM_KEY_LENGTH) {
                    throw new InvalidArgumentException(sprintf('An API key must be at least %d bytes long', self::MINIMUM_KEY_LENGTH));
                }
                $sha256 = hash('sha256', $key, true);
            }
            $this->digests[] = $this->digest($sha256);
        }
    }

    /**
     * Whether $key is configured as a digest ("sha256:<hex>") rather than
     * as the key itself.
     */
    public static function isDigest(string $key): bool
    {
        return self::configuredDigest(trim($key)) !== null;
    }

    /**
     * Whether $key starts with DIGEST_PREFIX (in any case) without being a
     * digest: a configuration error, never a key.
     */
    public static function isMalformedDigest(string $key): bool
    {
        $key = trim($key);

        return strncasecmp($key, self::DIGEST_PREFIX, strlen(self::DIGEST_PREFIX)) === 0 && self::configuredDigest($key) === null;
    }

    /**
     * Whether at least one key is configured.
     */
    public function isConfigured(): bool
    {
        return $this->digests !== [];
    }

    /**
     * Whether the request presents a key (X-API-Key or a Bearer
     * Authorization), accepted or not.
     */
    public function presentsKey(Request $request): bool
    {
        return self::presentedKey($request) !== null;
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

        $digest = $this->digest(hash('sha256', $presented, true));
        $matched = false;
        foreach ($this->digests as $known) {
            // hash_equals() first, so that it runs for every key even after a match.
            $matched = hash_equals($known, $digest) || $matched;
        }

        return $matched ? self::AUTHENTICATED : self::INVALID_KEY;
    }

    /**
     * A stable identifier of the key the request presents, for per-key
     * limits: 32 hexadecimal characters of a SHA-256 digest of the key,
     * the same in every process, from which the key cannot be read back.
     * Null when the request presents no key. Call it only for an
     * AUTHENTICATED request, so that identifiers name configured keys only.
     */
    public function clientId(Request $request): ?string
    {
        $presented = self::presentedKey($request);

        return $presented === null ? null : substr(hash('sha256', self::CLIENT_ID_CONTEXT . $presented), 0, 32);
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

    /**
     * The raw SHA-256 digest a "sha256:<hex>" entry names; null for a key.
     */
    private static function configuredDigest(string $key): ?string
    {
        if (preg_match('/\A' . preg_quote(self::DIGEST_PREFIX, '/') . '([0-9a-f]{64})\z/i', $key, $match) !== 1) {
            return null;
        }
        $raw = hex2bin(strtolower($match[1]));

        return is_string($raw) ? $raw : null;
    }

    /**
     * The value compared: an HMAC of the SHA-256 digest of a key, of one
     * length whatever the key.
     */
    private function digest(string $sha256): string
    {
        return hash_hmac('sha256', $sha256, $this->hmacKey, true);
    }
}
