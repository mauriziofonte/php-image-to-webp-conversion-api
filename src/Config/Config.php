<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Config;

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
    ];

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
