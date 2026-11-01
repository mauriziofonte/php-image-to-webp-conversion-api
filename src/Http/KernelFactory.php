<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Mfonte\ImageConversionApi\Auth\ApiKeyAuthenticator;
use Mfonte\ImageConversionApi\Auth\IpAllowlist;
use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Conversion\ConverterFactoryInterface;
use Mfonte\ImageConversionApi\Conversion\LibraryConverterFactory;
use Mfonte\ImageConversionApi\Http\Handler\DeprecatedHandler;
use Mfonte\ImageConversionApi\Http\Handler\HealthHandler;
use Mfonte\ImageConversionApi\Http\Handler\MetricsHandler;
use Mfonte\ImageConversionApi\Http\Handler\V1ConvertHandler;
use Mfonte\ImageConversionApi\Http\Handler\V2ConvertHandler;
use Mfonte\ImageConversionApi\Limits\ApcuRateLimitStore;
use Mfonte\ImageConversionApi\Limits\ConversionSlots;
use Mfonte\ImageConversionApi\Limits\FileRateLimitStore;
use Mfonte\ImageConversionApi\Limits\LimitingHandler;
use Mfonte\ImageConversionApi\Limits\RateLimiter;
use Mfonte\ImageConversionApi\Limits\RequestLimits;
use Mfonte\ImageConversionApi\Mapping\ErrorMapper;
use Mfonte\ImageConversionApi\Mapping\V1RequestMapper;
use Mfonte\ImageConversionApi\Mapping\V1ResponseFactory;
use Mfonte\ImageConversionApi\Mapping\V2RequestMapper;
use Mfonte\ImageConversionApi\Observability\ApcuMetricsStore;
use Mfonte\ImageConversionApi\Observability\FileMetricsStore;
use Mfonte\ImageConversionApi\Observability\Metrics;
use Mfonte\ImageConversionApi\Observability\MetricsStoreInterface;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
use Mfonte\SecureImageConverter\Isolation\IsolationDetector;
use Mfonte\SecureImageConverter\Isolation\ProcessOptions;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use Mfonte\SecureImageConverter\Validation\Limits;
use Psr\Log\LoggerInterface;

/**
 * Builds the object graph of the service from its configuration, with
 * plain constructors: no container, no service locator. public/index.php
 * calls it; the functional tests call it too, so that they exercise the
 * same wiring.
 *
 * Routes:
 *
 * | Path                    | Methods   | Access    | Handler                         |
 * | ----------------------- | --------- | --------- | ------------------------------- |
 * | /v1/{V1_SCRIPT_NAME}    | POST      | protected | V1ConvertHandler, limited       |
 * | /v2/convert             | POST      | protected | V2ConvertHandler, limited       |
 * | /healthz                | GET, HEAD | public    | HealthHandler (+ capabilities)  |
 * | /metrics                | GET, HEAD | protected | MetricsHandler (+ METRICS_KEYS) |
 *
 * /healthz is public; with an accepted API key it also publishes the
 * capabilities of the converter (HealthHandler).
 *
 * /metrics accepts the METRICS_KEYS besides the API keys, so that a
 * Prometheus scraper needs no key that converts images; IP_ALLOWLIST and
 * REQUIRE_HTTPS apply to it as to every protected route. Every route
 * counts in the same Metrics, kept in METRICS_STORE.
 *
 * The /v1 route exists only when V1_SCRIPT_NAME is set, and is deprecated:
 * its responses carry Deprecation (V1_DEPRECATED_AT), Sunset (V1_SUNSET_AT)
 * and a Link to V1_DEPRECATION_NOTICE. The conversion
 * endpoints sit behind LimitingHandler (RATE_LIMIT, MAX_BODY_BYTES) and
 * convert with the library (LibraryConverterFactory: MAX_IMAGE_BYTES,
 * CONCURRENCY_SLOTS and CONCURRENCY_WAIT, CONVERTER_CACHE_DIR, the
 * library's default backends and isolation policy) unless another
 * ConverterFactoryInterface is given.
 */
final class KernelFactory
{
    /**
     * Shortest age, in seconds, after which a request directory left by a
     * dead process is removed.
     */
    public const MIN_STALE_SECONDS = 3600;

    /**
     * Unix time of the deprecation of /v1: 2026-11-01T00:00:00Z, the 2.0.0
     * release window.
     */
    public const V1_DEPRECATED_AT = 1793491200;

    /**
     * Unix time after which /v1 may be removed: 2027-11-01T00:00:00Z.
     */
    public const V1_SUNSET_AT = 1825027200;

    /**
     * The deprecation notice of /v1 in the README.
     */
    public const V1_DEPRECATION_NOTICE = 'https://github.com/mauriziofonte/php-image-to-webp-conversion-api#deprecated-the-1x-endpoint';

    private function __construct()
    {
    }

    /**
     * @param ConverterFactoryInterface|null $converters creates the converters of the conversion endpoints; the
     *                                                   library's when null
     */
    public static function create(Config $config, LoggerInterface $logger, ?ConverterFactoryInterface $converters = null): Kernel
    {
        $responses = new ResponseFactory();
        if ($converters === null) {
            $converters = new LibraryConverterFactory(
                self::converterBuilder($config, $logger),
                (new Limits())->withMaxBytes($config->getMaxImageBytes()),
                (new ConverterOptions())->withSlotWaitSeconds((float) $config->getConcurrencyWait()),
                new ConversionSlots($config->getLockDirectory(), $config->getConcurrencySlots())
            );
        }

        $metrics = new Metrics(self::metricsStore($config));

        return new Kernel(
            new Router(self::routes($config, $responses, $converters, $logger, $metrics)),
            new TrustedProxies($config->getTrustedProxies()),
            $config->isHttpsRequired(),
            new ApiKeyAuthenticator($config->getApiKeys()),
            new IpAllowlist($config->getIpAllowlist()),
            new RequestDirectories($config->getTemporaryDirectory(), self::staleAfterSeconds($config), [$config->getLockDirectory()]),
            $responses,
            $logger,
            $metrics
        );
    }

    /**
     * The settings of the library's converters: the library's defaults,
     * the logger, and CONVERTER_CACHE_DIR for the capability cache of the
     * backends and the cache of the isolation detector, when it is set.
     */
    private static function converterBuilder(Config $config, LoggerInterface $logger): SecureConverterBuilder
    {
        $backendOptions = (new BackendOptions())->withLogger($logger);
        $builder = new SecureConverterBuilder();
        $cacheDirectory = $config->getConverterCacheDirectory();
        if ($cacheDirectory !== null) {
            $backendOptions = $backendOptions->withCapabilityCache(true, $cacheDirectory);
            $builder = $builder->withIsolationDetector(
                new IsolationDetector(new ProcessOptions(), $cacheDirectory, IsolationDetector::DEFAULT_CACHE_TTL_SECONDS, $logger)
            );
        }

        return $builder->withBackendOptions($backendOptions);
    }

    /**
     * The age after which a request directory is left over by a dead
     * process: twice CONVERSION_TIME_LIMIT, and at least an hour, well past
     * the end of any request.
     */
    private static function staleAfterSeconds(Config $config): int
    {
        return max(self::MIN_STALE_SECONDS, 2 * $config->getConversionTimeLimit());
    }

    /**
     * The store of the metrics (METRICS_STORE); null when it is off.
     */
    private static function metricsStore(Config $config): ?MetricsStoreInterface
    {
        $store = $config->getMetricsStore();
        if ($store === Config::METRICS_STORE_OFF) {
            return null;
        }
        if ($store === Config::METRICS_STORE_APCU || ($store === Config::METRICS_STORE_AUTO && ApcuMetricsStore::isAvailable())) {
            return new ApcuMetricsStore();
        }

        return new FileMetricsStore($config->getLockDirectory());
    }

    /**
     * The per-key rate limiter; null when RATE_LIMIT is 0.
     */
    private static function rateLimiter(Config $config): ?RateLimiter
    {
        if ($config->getRateLimit() === 0) {
            return null;
        }
        $store = $config->getRateLimitStore();
        if ($store === Config::RATE_LIMIT_STORE_APCU || ($store === Config::RATE_LIMIT_STORE_AUTO && ApcuRateLimitStore::isAvailable())) {
            return new RateLimiter(new ApcuRateLimitStore(), $config->getRateLimit(), $config->getRateLimitBurst());
        }

        return new RateLimiter(new FileRateLimitStore($config->getLockDirectory()), $config->getRateLimit(), $config->getRateLimitBurst());
    }

    /**
     * @return list<Route>
     */
    private static function routes(
        Config $config,
        ResponseFactory $responses,
        ConverterFactoryInterface $converters,
        LoggerInterface $logger,
        Metrics $metrics
    ): array {
        $v2 = new V2ConvertHandler(
            $converters,
            new InputReader(),
            new V2RequestMapper(),
            new ErrorMapper(),
            $responses,
            $logger,
            $config->getConversionTimeLimit(),
            $metrics
        );
        $limits = new RequestLimits($config->getMaxBodyBytes());
        $rateLimiter = self::rateLimiter($config);
        $routes = [
            new Route('v2_convert', '/v2/convert', ['POST'], new LimitingHandler($v2, $limits, $rateLimiter, $responses, $logger)),
            new Route('healthz', '/healthz', ['GET', 'HEAD'], new HealthHandler($responses, $converters, new ErrorMapper(), $logger, $config->getConversionTimeLimit()), true),
            new Route(
                'metrics',
                '/metrics',
                ['GET', 'HEAD'],
                new MetricsHandler($responses, $metrics),
                false,
                new ApiKeyAuthenticator(array_merge($config->getApiKeys(), $config->getMetricsKeys()))
            ),
        ];

        $v1ScriptName = $config->getV1ScriptName();
        if ($v1ScriptName !== null) {
            $v1 = new V1ConvertHandler(
                $converters,
                new V1RequestMapper(),
                new V1ResponseFactory(),
                new ErrorMapper(),
                $logger,
                $config->getConversionTimeLimit(),
                $config->getV1MaxFiles(),
                $metrics
            );
            $routes[] = new Route('v1', '/v1/' . $v1ScriptName, ['POST'], new DeprecatedHandler(
                new LimitingHandler($v1, $limits, $rateLimiter, $responses, $logger),
                self::V1_DEPRECATED_AT,
                self::V1_SUNSET_AT,
                self::V1_DEPRECATION_NOTICE
            ));
        }

        return $routes;
    }
}
