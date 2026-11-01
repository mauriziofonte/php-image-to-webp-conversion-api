<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Config;

use Mfonte\ImageConversionApi\Auth\ApiKeyAuthenticator;
use Mfonte\ImageConversionApi\Exception\InvalidArgumentException;
use Mfonte\ImageConversionApi\Exception\InvalidConfigurationException;
use Mfonte\ImageConversionApi\Http\IpRange;
use Mfonte\ImageConversionApi\Http\RequestDirectories;
use Mfonte\ImageConversionApi\Limits\ApcuRateLimitStore;
use Mfonte\ImageConversionApi\Observability\JsonLogger;
use Mfonte\SecureImageConverter\Isolation\ExecutableFinder;
use Psr\Log\LogLevel;

/**
 * The configuration of the service, immutable and already validated.
 *
 * It is read by ConfigLoader: environment variables first (getenv(), which
 * also sees the variables that PHP-FPM pools and Apache SetEnv pass to the
 * request), then the optional .env file. For every key, a missing
 * variable, the empty string and the literal "null" mean "not set", and the
 * default applies. fromArray() is the validator: the front controller and
 * the check-env command both go through it.
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
 *   Every key is at least 16 bytes long (a multibyte character counts for
 *   each of its bytes): a shorter one makes the configuration invalid,
 *   and the service answers 503 invalid_configuration. With neither set, every protected endpoint
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
 * - MAX_BODY_BYTES: the largest request body accepted by the conversion
 *   endpoints, in bytes. A larger Content-Length is refused with 413
 *   body_too_large before the body is read, and so is a multipart body
 *   larger than PHP's post_max_size (which PHP would otherwise drop
 *   silently): set post_max_size and upload_max_filesize to at least this
 *   value. A body without Content-Length (chunked) is read only up to
 *   MAX_IMAGE_BYTES (raw bodies) or post_max_size (multipart bodies,
 *   which PHP reads). Default: 22020096 (21 MiB).
 * - MAX_IMAGE_BYTES: the largest image, in bytes (the library's max_bytes
 *   limit), answered 413 file_too_large. Default: 20971520 (20 MiB).
 * - V1_MAX_FILES: the most images one /v1 request may carry, 1 to 100;
 *   more are refused with 413 too_many_files. Default: 10.
 * - CONCURRENCY_SLOTS: the most conversions that run at the same time on
 *   this host, 1 to 256, shared by every PHP process through lock files in
 *   LOCK_DIR (the library's Semaphore). Default: 4.
 * - CONCURRENCY_WAIT: the longest wait for a free slot, in whole seconds
 *   from 0 (one attempt) to below CONVERSION_TIME_LIMIT; then the request
 *   is answered 503 concurrency_limit with Retry-After. Default: 10, or
 *   CONVERSION_TIME_LIMIT - 1 when that is shorter.
 * - LOCK_DIR: absolute path of the directory of the lock files of the
 *   concurrency slots and of the file-based rate limiter; private to the
 *   PHP user (created with mode 0700, refused when others can write to it
 *   or to a directory above it without the sticky bit), on a local
 *   filesystem, and never cleaned while the service runs. It must not be
 *   TEMP_DIR, nor lie inside a directory of TEMP_DIR whose name starts
 *   with "image-conversion-api-" (RequestDirectories::PREFIX), which the
 *   removal of stale request directories deletes. Default:
 *   TEMP_DIR/image-conversion-api.locks.
 * - RATE_LIMIT: requests per minute that one API key may send to the
 *   conversion endpoints, a token bucket refilled at that rate; 0 turns
 *   rate limiting off. Above it, 429 rate_limited with Retry-After.
 *   Default: 300.
 * - RATE_LIMIT_BURST: the size of the bucket, the requests a key may send
 *   at once after a pause, 1 to 1000000. Default: 50.
 * - RATE_LIMIT_STORE: where the buckets are kept: apcu (shared memory of
 *   one PHP-FPM master, needs ext-apcu), file (one file per key in
 *   LOCK_DIR, locked with flock(), shared by every PHP process of the
 *   host) or auto (apcu when APCu is enabled, otherwise file). Default:
 *   auto.
 * - METRICS_STORE: where the counters of /metrics are kept: apcu, file
 *   (one file in LOCK_DIR, locked with flock()), auto (apcu when APCu is
 *   enabled, otherwise file) or off (nothing is counted, /metrics only
 *   answers image_conversion_api_up). Default: auto.
 * - METRICS_KEYS: further keys, separated by commas, each at least 16
 *   bytes long, accepted on /metrics only, so that a Prometheus scraper
 *   does not hold a key that converts images. The API keys are accepted
 *   on /metrics too. Default: none.
 * - CONVERTER_CACHE_DIR: absolute path of the directory where the
 *   conversion library keeps the capabilities it probed (backends and
 *   isolation level), private to the PHP user (created with mode 0700).
 *   Probing takes seconds, so on a read-only root filesystem this must
 *   point to a writable volume, or every process probes again. Default:
 *   the library's, sys_get_temp_dir()/secure-image-converter-<euid>.
 * - LOG_FILE: where the JSON log lines go: "stderr" (the standard error
 *   stream, the container log behind PHP-FPM with catch_workers_output on
 *   and decorate_workers_output off) or the absolute path of a file
 *   appended to, whose directory exists. Default: PHP's error_log().
 * - LOG_LEVEL: the least severe PSR-3 level that is logged: debug, info,
 *   notice, warning, error, critical, alert or emergency. Default: info.
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
        'MAX_BODY_BYTES',
        'MAX_IMAGE_BYTES',
        'V1_MAX_FILES',
        'CONCURRENCY_SLOTS',
        'CONCURRENCY_WAIT',
        'LOCK_DIR',
        'RATE_LIMIT',
        'RATE_LIMIT_BURST',
        'RATE_LIMIT_STORE',
        'METRICS_STORE',
        'METRICS_KEYS',
        'CONVERTER_CACHE_DIR',
        'LOG_FILE',
        'LOG_LEVEL',
    ];

    /** RATE_LIMIT_STORE: APCu when it is enabled, otherwise files. */
    public const RATE_LIMIT_STORE_AUTO = 'auto';

    /** RATE_LIMIT_STORE: APCu. */
    public const RATE_LIMIT_STORE_APCU = 'apcu';

    /** RATE_LIMIT_STORE: one file per key in LOCK_DIR. */
    public const RATE_LIMIT_STORE_FILE = 'file';

    /** METRICS_STORE: APCu when it is enabled, otherwise a file. */
    public const METRICS_STORE_AUTO = 'auto';

    /** METRICS_STORE: APCu. */
    public const METRICS_STORE_APCU = 'apcu';

    /** METRICS_STORE: one file in LOCK_DIR. */
    public const METRICS_STORE_FILE = 'file';

    /** METRICS_STORE: nothing is counted. */
    public const METRICS_STORE_OFF = 'off';

    /** LOG_FILE value of the standard error stream. */
    public const LOG_FILE_STDERR = JsonLogger::DESTINATION_STDERR;

    public const DEFAULT_LOG_LEVEL = LogLevel::INFO;

    /**
     * Name of the default LOCK_DIR in TEMP_DIR. It does not start with
     * RequestDirectories::PREFIX, so that removing stale request
     * directories never touches it.
     */
    public const DEFAULT_LOCK_DIR_NAME = 'image-conversion-api.locks';

    /**
     * Default of CONVERSION_TIME_LIMIT, in seconds: below the 60-second
     * default timeouts of nginx and Apache.
     */
    public const DEFAULT_CONVERSION_TIME_LIMIT = 50;

    /**
     * Largest CONVERSION_TIME_LIMIT, in seconds.
     */
    public const MAX_CONVERSION_TIME_LIMIT = 3600;

    public const DEFAULT_MAX_BODY_BYTES = 22020096;

    public const DEFAULT_MAX_IMAGE_BYTES = 20971520;

    /**
     * Largest MAX_IMAGE_BYTES: 1 GiB.
     */
    public const MAX_IMAGE_BYTES = 1073741824;

    public const DEFAULT_V1_MAX_FILES = 10;

    public const DEFAULT_CONCURRENCY_SLOTS = 4;

    public const DEFAULT_CONCURRENCY_WAIT = 10;

    public const DEFAULT_RATE_LIMIT = 300;

    public const DEFAULT_RATE_LIMIT_BURST = 50;

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

    /**
     * @var int
     */
    private $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES;

    /**
     * @var int
     */
    private $maxImageBytes = self::DEFAULT_MAX_IMAGE_BYTES;

    /**
     * @var int
     */
    private $v1MaxFiles = self::DEFAULT_V1_MAX_FILES;

    /**
     * @var int
     */
    private $concurrencySlots = self::DEFAULT_CONCURRENCY_SLOTS;

    /**
     * @var int
     */
    private $concurrencyWait = self::DEFAULT_CONCURRENCY_WAIT;

    /**
     * @var string
     */
    private $lockDirectory = '';

    /**
     * @var int
     */
    private $rateLimit = self::DEFAULT_RATE_LIMIT;

    /**
     * @var int
     */
    private $rateLimitBurst = self::DEFAULT_RATE_LIMIT_BURST;

    /**
     * @var string
     */
    private $rateLimitStore = self::RATE_LIMIT_STORE_AUTO;

    /**
     * @var string
     */
    private $metricsStore = self::METRICS_STORE_AUTO;

    /**
     * @var list<string>
     */
    private $metricsKeys = [];

    /**
     * @var string|null
     */
    private $converterCacheDirectory;

    /**
     * @var string|null
     */
    private $logFile;

    /**
     * @var string
     */
    private $logLevel = self::DEFAULT_LOG_LEVEL;

    private function __construct()
    {
    }

    /**
     * Reads every key from the environment, without a .env file, and
     * validates it.
     *
     * @param list<string> $webRoots directories the web server serves files from (the directory of the front
     *                               controller, DOCUMENT_ROOT); empty strings are ignored
     *
     * @throws InvalidConfigurationException listing every invalid value
     */
    public static function fromEnvironment(array $webRoots = []): self
    {
        return (new ConfigLoader(null))->load($webRoots);
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
                $errors[] = sprintf('API_KEY must be at least %d bytes long', $minimumLength);
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
                $errors[] = sprintf('API_KEYS must list keys of at least %d bytes: key %d is shorter', $minimumLength, $position);
            } elseif (!in_array($key, $config->apiKeys, true)) {
                $config->apiKeys[] = $key;
            }
        }
        $position = 0;
        foreach (explode(',', (string) self::value($values, 'METRICS_KEYS')) as $key) {
            $key = trim($key);
            if ($key === '') {
                continue;
            }
            ++$position;
            if (strlen($key) < $minimumLength) {
                $errors[] = sprintf('METRICS_KEYS must list keys of at least %d bytes: key %d is shorter', $minimumLength, $position);
            } elseif (!in_array($key, $config->metricsKeys, true)) {
                $config->metricsKeys[] = $key;
            }
        }

        $timeLimit = self::integer($values, 'CONVERSION_TIME_LIMIT', 1, self::MAX_CONVERSION_TIME_LIMIT, $errors);
        if ($timeLimit !== null) {
            $config->conversionTimeLimit = $timeLimit;
        }
        $config->maxBodyBytes = self::integer($values, 'MAX_BODY_BYTES', 1, PHP_INT_MAX, $errors) ?? $config->maxBodyBytes;
        $config->maxImageBytes = self::integer($values, 'MAX_IMAGE_BYTES', 1, self::MAX_IMAGE_BYTES, $errors) ?? $config->maxImageBytes;
        $config->v1MaxFiles = self::integer($values, 'V1_MAX_FILES', 1, 100, $errors) ?? $config->v1MaxFiles;
        $config->concurrencySlots = self::integer($values, 'CONCURRENCY_SLOTS', 1, 256, $errors) ?? $config->concurrencySlots;
        $wait = self::integer($values, 'CONCURRENCY_WAIT', 0, self::MAX_CONVERSION_TIME_LIMIT, $errors);
        if ($wait === null) {
            $config->concurrencyWait = min(self::DEFAULT_CONCURRENCY_WAIT, $config->conversionTimeLimit - 1);
        } elseif ($wait >= $config->conversionTimeLimit) {
            $errors[] = 'CONCURRENCY_WAIT must be shorter than CONVERSION_TIME_LIMIT, which includes it';
        } else {
            $config->concurrencyWait = $wait;
        }
        $config->rateLimit = self::integer($values, 'RATE_LIMIT', 0, 1000000, $errors) ?? $config->rateLimit;
        $config->rateLimitBurst = self::integer($values, 'RATE_LIMIT_BURST', 1, 1000000, $errors) ?? $config->rateLimitBurst;
        $store = self::value($values, 'RATE_LIMIT_STORE');
        if ($store !== null) {
            $store = strtolower($store);
            if (!in_array($store, [self::RATE_LIMIT_STORE_AUTO, self::RATE_LIMIT_STORE_APCU, self::RATE_LIMIT_STORE_FILE], true)) {
                $errors[] = 'RATE_LIMIT_STORE must be auto, apcu or file';
            } elseif ($store === self::RATE_LIMIT_STORE_APCU && !ApcuRateLimitStore::isAvailable()) {
                $errors[] = 'RATE_LIMIT_STORE is apcu, but APCu is not enabled in this PHP (apc.enabled, apc.enable_cli)';
            } else {
                $config->rateLimitStore = $store;
            }
        }

        $metricsStore = self::value($values, 'METRICS_STORE');
        if ($metricsStore !== null) {
            $metricsStore = strtolower($metricsStore);
            if (!in_array($metricsStore, [self::METRICS_STORE_AUTO, self::METRICS_STORE_APCU, self::METRICS_STORE_FILE, self::METRICS_STORE_OFF], true)) {
                $errors[] = 'METRICS_STORE must be auto, apcu, file or off';
            } elseif ($metricsStore === self::METRICS_STORE_APCU && !ApcuRateLimitStore::isAvailable()) {
                $errors[] = 'METRICS_STORE is apcu, but APCu is not enabled in this PHP (apc.enabled, apc.enable_cli)';
            } else {
                $config->metricsStore = $metricsStore;
            }
        }

        $config->converterCacheDirectory = self::value($values, 'CONVERTER_CACHE_DIR');
        if ($config->converterCacheDirectory !== null && !ExecutableFinder::isAbsolutePath($config->converterCacheDirectory)) {
            $errors[] = 'CONVERTER_CACHE_DIR must be an absolute path without "." or ".." segments, empty segments or a trailing "/"';
        }

        $config->logFile = self::value($values, 'LOG_FILE');
        if ($config->logFile !== null && $config->logFile !== self::LOG_FILE_STDERR && !self::isAbsolutePath($config->logFile)) {
            $errors[] = 'LOG_FILE must be "stderr" or an absolute path';
        }
        $logLevel = self::value($values, 'LOG_LEVEL');
        if ($logLevel !== null) {
            $logLevel = strtolower($logLevel);
            if (!JsonLogger::isLevel($logLevel)) {
                $errors[] = 'LOG_LEVEL must be one of debug, info, notice, warning, error, critical, alert and emergency';
            } else {
                $config->logLevel = $logLevel;
            }
        }

        $lockDirectory = self::value($values, 'LOCK_DIR');
        if ($lockDirectory === null) {
            $lockDirectory = rtrim($temporaryDirectory, '/\\') . DIRECTORY_SEPARATOR . self::DEFAULT_LOCK_DIR_NAME;
        } elseif (!self::isAbsolutePath($lockDirectory)) {
            $errors[] = 'LOCK_DIR must be an absolute path';
        } elseif (self::isAbsolutePath($temporaryDirectory)) {
            $problem = self::lockDirectoryProblem($lockDirectory, $temporaryDirectory);
            if ($problem !== null) {
                $errors[] = $problem;
            }
        }
        $config->lockDirectory = $lockDirectory;

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
     * MAX_BODY_BYTES: the largest request body of a conversion endpoint, in bytes.
     */
    public function getMaxBodyBytes(): int
    {
        return $this->maxBodyBytes;
    }

    /**
     * MAX_IMAGE_BYTES: the largest image, in bytes.
     */
    public function getMaxImageBytes(): int
    {
        return $this->maxImageBytes;
    }

    /**
     * V1_MAX_FILES: the most images of one /v1 request.
     */
    public function getV1MaxFiles(): int
    {
        return $this->v1MaxFiles;
    }

    /**
     * CONCURRENCY_SLOTS: the most conversions that run at once on the host.
     */
    public function getConcurrencySlots(): int
    {
        return $this->concurrencySlots;
    }

    /**
     * CONCURRENCY_WAIT: the longest wait for a conversion slot, in seconds.
     */
    public function getConcurrencyWait(): int
    {
        return $this->concurrencyWait;
    }

    /**
     * LOCK_DIR: the directory of the lock files and of the file-based rate limiter.
     */
    public function getLockDirectory(): string
    {
        return $this->lockDirectory;
    }

    /**
     * RATE_LIMIT: requests per minute per API key; 0 when rate limiting is off.
     */
    public function getRateLimit(): int
    {
        return $this->rateLimit;
    }

    /**
     * RATE_LIMIT_BURST: the size of the token bucket of each key.
     */
    public function getRateLimitBurst(): int
    {
        return $this->rateLimitBurst;
    }

    /**
     * RATE_LIMIT_STORE: one of the RATE_LIMIT_STORE_* constants.
     */
    public function getRateLimitStore(): string
    {
        return $this->rateLimitStore;
    }

    /**
     * METRICS_STORE: one of the METRICS_STORE_* constants.
     */
    public function getMetricsStore(): string
    {
        return $this->metricsStore;
    }

    /**
     * METRICS_KEYS: the keys accepted on /metrics only, trimmed and without
     * duplicates; empty when none is configured.
     *
     * @return list<string>
     */
    public function getMetricsKeys(): array
    {
        return $this->metricsKeys;
    }

    /**
     * CONVERTER_CACHE_DIR: the capability and isolation cache of the
     * conversion library; null for the library's default.
     */
    public function getConverterCacheDirectory(): ?string
    {
        return $this->converterCacheDirectory;
    }

    /**
     * LOG_FILE: LOG_FILE_STDERR, the absolute path of a file, or null for
     * PHP's error_log().
     */
    public function getLogFile(): ?string
    {
        return $this->logFile;
    }

    /**
     * LOG_LEVEL: the least severe PSR-3 level that is logged.
     */
    public function getLogLevel(): string
    {
        return $this->logLevel;
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
        if (preg_match('/\A[0-9]{1,18}\z/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
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

    /**
     * Why LOCK_DIR cannot be used with TEMP_DIR, or null when it can.
     *
     * RequestDirectories removes the directories of TEMP_DIR whose name
     * starts with RequestDirectories::PREFIX, with everything inside, once
     * nobody has changed them for an hour: a LOCK_DIR inside one of them
     * would be removed while the service runs, its lock files unlinked
     * while they are held. LOCK_DIR cannot be TEMP_DIR either: TEMP_DIR is
     * a scratch area (the system temporary directory by default) that
     * cleaners empty, while LOCK_DIR must never be cleaned. Both paths are
     * compared as written and with their symbolic links resolved, so that
     * neither form slips through.
     */
    private static function lockDirectoryProblem(string $lockDirectory, string $temporaryDirectory): ?string
    {
        $forms = [
            [PathResolver::lexical($lockDirectory), PathResolver::lexical($temporaryDirectory)],
            [PathResolver::resolve($lockDirectory), PathResolver::resolve($temporaryDirectory)],
        ];
        foreach ($forms as [$lock, $temporary]) {
            if ($lock === $temporary) {
                return 'LOCK_DIR must not be TEMP_DIR: the lock files need a directory of their own that nothing cleans';
            }
            $base = rtrim($temporary, '/') . '/';
            if (strpos($lock, $base) === 0 && strpos((string) substr($lock, strlen($base)), RequestDirectories::PREFIX) === 0) {
                return sprintf(
                    'LOCK_DIR must not be inside a directory of TEMP_DIR whose name starts with "%s": stale request directories are removed with everything inside',
                    RequestDirectories::PREFIX
                );
            }
        }

        return null;
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
