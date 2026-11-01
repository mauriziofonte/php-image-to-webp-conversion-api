<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Config;

use Mfonte\ImageConversionApi\Auth\ApiKeyAuthenticator;
use Mfonte\ImageConversionApi\Exception\InvalidArgumentException;
use Mfonte\ImageConversionApi\Exception\InvalidConfigurationException;
use Mfonte\ImageConversionApi\Http\IpRange;

/**
 * The configuration of the service, immutable and already validated.
 *
 * It is read from environment variables (fromEnvironment(), with getenv(),
 * which also sees the variables that PHP-FPM pools and Apache SetEnv pass to
 * the request). For every key, a missing variable, the empty string and the
 * literal "null" mean "not set", and the default applies. API-7 adds the
 * optional .env file, the compiled cache and check-env on top of
 * fromArray().
 *
 * Keys:
 *
 * - V1_SCRIPT_NAME: the last path segment of the 1.x endpoint, which 2.x
 *   serves at /v1/{V1_SCRIPT_NAME}; unreserved URL characters only. Not set:
 *   no /v1 endpoint. 1.x called it SCRIPT_NAME, a name that cannot be read
 *   from the environment because the web server sets the CGI variable
 *   SCRIPT_NAME (the path of the front controller) for every request.
 * - TEMP_DIR: absolute path of the directory that receives the per-request
 *   temporary directories; created on first use when missing. It must be
 *   outside the web roots given to the factory methods (finding C-8).
 *   Default: the system temporary directory.
 * - TRUSTED_PROXIES: comma-separated IPs and CIDR blocks (IPv4 and IPv6)
 *   of the reverse proxies and load balancers whose X-Forwarded-Proto and
 *   X-Forwarded-For headers are believed. Default: none, the headers are
 *   ignored. The proxy CDN reads the same key with the same meaning.
 * - REQUIRE_HTTPS: whether the protected endpoints refuse requests that did
 *   not reach the service (or its trusted proxy) over HTTPS: true, false,
 *   1, 0, yes, no, on or off. Default: true.
 * - API_KEY: one accepted API key, taken as it is (trimmed), commas
 *   included, as in 1.x.
 * - API_KEYS: further accepted keys, separated by commas, for rotation: add
 *   the new key, move the clients, then remove the old one.
 *   Every key is at least 16 characters long (counted in bytes): a shorter
 *   one makes the configuration invalid, and the service answers 503
 *   invalid_configuration. With neither set, every protected endpoint
 *   answers 503 authentication_not_configured: the service fails closed.
 * - IP_ALLOWLIST: comma-separated IPs and CIDR blocks (IPv4 and IPv6) of
 *   the clients allowed on the protected endpoints, in addition to the API
 *   key; the client address is taken from X-Forwarded-For only behind
 *   TRUSTED_PROXIES. Default: none, every address is allowed.
 * - CONVERSION_TIME_LIMIT: the longest time, in whole seconds from 1 to
 *   3600, that one conversion request may spend once its body has
 *   arrived: waiting for a conversion slot and converting. The processing
 *   budget of the library is capped to what is left of it, so that a
 *   conversion is stopped by the library (conversion_timeout, 422) before
 *   the web server or PHP-FPM gives up on the request and kills the worker.
 *   Keep it below every timeout on the way: nginx fastcgi_read_timeout and
 *   proxy_read_timeout (60 s by default), Apache Timeout and ProxyTimeout
 *   (60 s), PHP-FPM request_terminate_timeout, and the timeout of the
 *   client (the proxy's API timeout). Default: 50.
 */
final class Config
{
    /**
     * Every configuration key, in the order of the documentation above.
     */
    public const KEYS = [
        'V1_SCRIPT_NAME',
        'TEMP_DIR',
        'TRUSTED_PROXIES',
        'REQUIRE_HTTPS',
        'API_KEY',
        'API_KEYS',
        'IP_ALLOWLIST',
        'CONVERSION_TIME_LIMIT',
    ];

    /**
     * Default of CONVERSION_TIME_LIMIT, in seconds: below the 60-second
     * default timeouts of nginx and Apache.
     */
    public const DEFAULT_CONVERSION_TIME_LIMIT = 50;

    /**
     * Largest CONVERSION_TIME_LIMIT, in seconds.
     */
    public const MAX_CONVERSION_TIME_LIMIT = 3600;

    private const BOOLEANS = [
        'true' => true,
        '1' => true,
        'yes' => true,
        'on' => true,
        'false' => false,
        '0' => false,
        'no' => false,
        'off' => false,
    ];

    /**
     * @var string|null
     */
    private $v1ScriptName;

    /**
     * @var string
     */
    private $temporaryDirectory;

    /**
     * @var list<IpRange>
     */
    private $trustedProxies = [];

    /**
     * @var bool
     */
    private $httpsRequired = true;

    /**
     * @var list<string>
     */
    private $apiKeys = [];

    /**
     * @var list<IpRange>
     */
    private $ipAllowlist = [];

    /**
     * @var int
     */
    private $conversionTimeLimit = self::DEFAULT_CONVERSION_TIME_LIMIT;

    private function __construct()
    {
    }

    /**
     * Reads every key from the environment and validates it.
     *
     * @param list<string> $webRoots directories the web server serves files from (the directory of the front
     *                               controller, DOCUMENT_ROOT); empty strings are ignored
     *
     * @throws InvalidConfigurationException listing every invalid value
     */
    public static function fromEnvironment(array $webRoots = []): self
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $value = getenv($key);
            $values[$key] = is_string($value) ? $value : null;
        }

        return self::fromArray($values, $webRoots);
    }

    /**
     * Validates raw values and builds the configuration.
     *
     * @param array<string, string|null> $values   raw values keyed by KEYS; a missing key, null, "" and "null" mean
     *                                             "not set"; other keys are ignored
     * @param list<string>               $webRoots directories the web server serves files from; empty strings are
     *                                             ignored
     *
     * @throws InvalidConfigurationException listing every invalid value
     */
    public static function fromArray(array $values, array $webRoots = []): self
    {
        $errors = [];
        $config = new self();

        $config->v1ScriptName = self::value($values, 'V1_SCRIPT_NAME');
        if ($config->v1ScriptName !== null && !self::isPathSegment($config->v1ScriptName)) {
            $errors[] = 'V1_SCRIPT_NAME must be one URL path segment of letters, digits, ".", "_", "~" and "-" (not "." or "..")';
        }

        $temporaryDirectory = self::value($values, 'TEMP_DIR');
        if ($temporaryDirectory === null) {
            $temporaryDirectory = sys_get_temp_dir();
        }
        $config->temporaryDirectory = $temporaryDirectory;
        if (!self::isAbsolutePath($temporaryDirectory)) {
            $errors[] = 'TEMP_DIR must be an absolute path';
        } else {
            foreach ($webRoots as $webRoot) {
                if ($webRoot !== '' && PathResolver::isInside($temporaryDirectory, $webRoot)) {
                    $errors[] = 'TEMP_DIR must be outside the web root, where the web server could serve the uploaded files';

                    break;
                }
            }
        }

        try {
            $config->trustedProxies = IpRange::listFromString((string) self::value($values, 'TRUSTED_PROXIES'));
        } catch (InvalidArgumentException $exception) {
            $errors[] = 'TRUSTED_PROXIES must list IP addresses and CIDR blocks separated by commas: ' . $exception->getMessage();
        }

        $httpsRequired = self::value($values, 'REQUIRE_HTTPS');
        if ($httpsRequired !== null) {
            $boolean = self::BOOLEANS[strtolower($httpsRequired)] ?? null;
            if ($boolean === null) {
                $errors[] = 'REQUIRE_HTTPS must be true or false';
            } else {
                $config->httpsRequired = $boolean;
            }
        }

        try {
            $config->ipAllowlist = IpRange::listFromString((string) self::value($values, 'IP_ALLOWLIST'));
        } catch (InvalidArgumentException $exception) {
            $errors[] = 'IP_ALLOWLIST must list IP addresses and CIDR blocks separated by commas: ' . $exception->getMessage();
        }

        $minimumLength = ApiKeyAuthenticator::MINIMUM_KEY_LENGTH;
        $apiKey = self::value($values, 'API_KEY');
        if ($apiKey !== null) {
            if (strlen($apiKey) < $minimumLength) {
                $errors[] = sprintf('API_KEY must be at least %d characters long', $minimumLength);
            }
            $config->apiKeys[] = $apiKey;
        }
        $position = 0;
        foreach (explode(',', (string) self::value($values, 'API_KEYS')) as $key) {
            $key = trim($key);
            if ($key === '') {
                continue;
            }
            ++$position;
            if (strlen($key) < $minimumLength) {
                // The position, never the key: the message ends up in the log.
                $errors[] = sprintf('API_KEYS must list keys of at least %d characters: key %d is shorter', $minimumLength, $position);
            } elseif (!in_array($key, $config->apiKeys, true)) {
                $config->apiKeys[] = $key;
            }
        }

        $timeLimit = self::integer($values, 'CONVERSION_TIME_LIMIT', 1, self::MAX_CONVERSION_TIME_LIMIT, $errors);
        if ($timeLimit !== null) {
            $config->conversionTimeLimit = $timeLimit;
        }

        if ($errors !== []) {
            throw new InvalidConfigurationException($errors);
        }

        return $config;
    }

    /**
     * V1_SCRIPT_NAME: the path segment of the 1.x endpoint; null when /v1 is disabled.
     */
    public function getV1ScriptName(): ?string
    {
        return $this->v1ScriptName;
    }

    /**
     * TEMP_DIR: the parent of the per-request temporary directories.
     */
    public function getTemporaryDirectory(): string
    {
        return $this->temporaryDirectory;
    }

    /**
     * TRUSTED_PROXIES: the proxies whose forwarding headers are believed.
     *
     * @return list<IpRange>
     */
    public function getTrustedProxies(): array
    {
        return $this->trustedProxies;
    }

    /**
     * REQUIRE_HTTPS: whether the protected endpoints refuse plain HTTP.
     */
    public function isHttpsRequired(): bool
    {
        return $this->httpsRequired;
    }

    /**
     * API_KEY and API_KEYS: the accepted API keys, trimmed and without
     * duplicates; empty when none is configured.
     *
     * @return list<string>
     */
    public function getApiKeys(): array
    {
        return $this->apiKeys;
    }

    /**
     * IP_ALLOWLIST: the clients allowed on the protected endpoints; empty
     * when every address is.
     *
     * @return list<IpRange>
     */
    public function getIpAllowlist(): array
    {
        return $this->ipAllowlist;
    }

    /**
     * CONVERSION_TIME_LIMIT: the longest time a conversion request may spend
     * waiting for a slot and converting, in seconds.
     */
    public function getConversionTimeLimit(): int
    {
        return $this->conversionTimeLimit;
    }

    /**
     * Reads a whole number from $min to $max written in decimal digits.
     *
     * @param array<string, string|null> $values
     * @param list<string>               $errors receives the problem, when there is one
     *
     * @return int|null null when the key is not set or invalid
     */
    private static function integer(array $values, string $key, int $min, int $max, array &$errors): ?int
    {
        $value = self::value($values, $key);
        if ($value === null) {
            return null;
        }
        if (preg_match('/\A[0-9]{1,10}\z/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
            $errors[] = sprintf('%s must be a whole number from %d to %d', $key, $min, $max);

            return null;
        }

        return (int) $value;
    }

    /**
     * @param array<string, string|null> $values
     */
    private static function value(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }

    private static function isPathSegment(string $value): bool
    {
        return $value !== '.' && $value !== '..' && preg_match('/\A[A-Za-z0-9._~-]+\z/', $value) === 1;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return strpos($path, '/') === 0
            || strpos($path, '\\') === 0
            || preg_match('/\A[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
