<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Mfonte\ImageConversionApi\Auth\ApiKeyAuthenticator;
use Mfonte\ImageConversionApi\Auth\IpAllowlist;
use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Conversion\ConverterFactoryInterface;
use Mfonte\ImageConversionApi\Conversion\LibraryConverterFactory;
use Mfonte\ImageConversionApi\Http\Handler\HealthHandler;
use Mfonte\ImageConversionApi\Http\Handler\MetricsHandler;
use Mfonte\ImageConversionApi\Http\Handler\NotImplementedHandler;
use Mfonte\ImageConversionApi\Http\Handler\V2ConvertHandler;
use Mfonte\ImageConversionApi\Limits\ApcuRateLimitStore;
use Mfonte\ImageConversionApi\Limits\ConversionSlots;
use Mfonte\ImageConversionApi\Limits\FileRateLimitStore;
use Mfonte\ImageConversionApi\Limits\LimitingHandler;
use Mfonte\ImageConversionApi\Limits\RateLimiter;
use Mfonte\ImageConversionApi\Limits\RequestLimits;
use Mfonte\ImageConversionApi\Mapping\ErrorMapper;
use Mfonte\ImageConversionApi\Mapping\V2RequestMapper;
use Mfonte\SecureImageConverter\Backend\BackendOptions;
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
 * | /v1/{V1_SCRIPT_NAME}    | POST      | protected | 501 until API-5                 |
 * | /v2/convert             | POST      | protected | V2ConvertHandler, limited       |
 * | /healthz                | GET, HEAD | public    | HealthHandler                   |
 * | /metrics                | GET, HEAD | protected | MetricsHandler                  |
 *
 * The /v1 route exists only when V1_SCRIPT_NAME is set. The conversion
 * endpoints sit behind LimitingHandler (RATE_LIMIT, MAX_BODY_BYTES) and
 * convert with the library (LibraryConverterFactory: MAX_IMAGE_BYTES,
 * CONCURRENCY_SLOTS and CONCURRENCY_WAIT, the library's default backends
 * and isolation policy) unless another ConverterFactoryInterface is given.
 */
final class KernelFactory
{
    /**
     * Shortest age, in seconds, after which a request directory left by a
     * dead process is removed.
     */
    public const MIN_STALE_SECONDS = 3600;

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
                (new SecureConverterBuilder())->withBackendOptions((new BackendOptions())->withLogger($logger)),
                (new Limits())->withMaxBytes($config->getMaxImageBytes()),
                (new ConverterOptions())->withSlotWaitSeconds((float) $config->getConcurrencyWait()),
                new ConversionSlots($config->getLockDirectory(), $config->getConcurrencySlots())
            );
        }

        return new Kernel(
            new Router(self::routes($config, $responses, $converters, $logger)),
            new TrustedProxies($config->getTrustedProxies()),
            $config->isHttpsRequired(),
            new ApiKeyAuthenticator($config->getApiKeys()),
            new IpAllowlist($config->getIpAllowlist()),
            new RequestDirectories($config->getTemporaryDirectory(), self::staleAfterSeconds($config)),
            $responses,
            $logger
        );
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
    private static function routes(Config $config, ResponseFactory $responses, ConverterFactoryInterface $converters, LoggerInterface $logger): array
    {
        $notImplemented = new NotImplementedHandler($responses);
        $v2 = new V2ConvertHandler(
            $converters,
            new InputReader(),
            new V2RequestMapper(),
            new ErrorMapper(),
            $responses,
            $logger,
            $config->getConversionTimeLimit()
        );
        $limits = new RequestLimits($config->getMaxBodyBytes());
        $rateLimiter = self::rateLimiter($config);
        $routes = [
            new Route('v2_convert', '/v2/convert', ['POST'], new LimitingHandler($v2, $limits, $rateLimiter, $responses, $logger)),
            new Route('healthz', '/healthz', ['GET', 'HEAD'], new HealthHandler($responses), true),
            new Route('metrics', '/metrics', ['GET', 'HEAD'], new MetricsHandler($responses)),
        ];

        $v1ScriptName = $config->getV1ScriptName();
        if ($v1ScriptName !== null) {
            $routes[] = new Route('v1', '/v1/' . $v1ScriptName, ['POST'], $notImplemented);
        }

        return $routes;
    }
}
