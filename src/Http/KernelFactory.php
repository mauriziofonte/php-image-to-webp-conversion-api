<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Http\Handler\HealthHandler;
use Mfonte\ImageConversionApi\Http\Handler\MetricsHandler;
use Mfonte\ImageConversionApi\Http\Handler\NotImplementedHandler;
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
 * | /v2/convert             | POST      | protected | 501 until API-4                 |
 * | /healthz                | GET, HEAD | public    | HealthHandler                   |
 * | /metrics                | GET, HEAD | protected | MetricsHandler                  |
 *
 * The /v1 route exists only when V1_SCRIPT_NAME is set.
 */
final class KernelFactory
{
    private function __construct()
    {
    }

    public static function create(Config $config, LoggerInterface $logger): Kernel
    {
        $responses = new ResponseFactory();

        return new Kernel(
            new Router(self::routes($config, $responses)),
            new RequestDirectories($config->getTemporaryDirectory()),
            $responses,
            $logger
        );
    }

    /**
     * @return list<Route>
     */
    private static function routes(Config $config, ResponseFactory $responses): array
    {
        $notImplemented = new NotImplementedHandler($responses);
        $routes = [
            new Route('v2_convert', '/v2/convert', ['POST'], $notImplemented),
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
