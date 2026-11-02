<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Http;

use Mfonte\ImageConversionApi\Auth\ApiKeyAuthenticator;
use Mfonte\ImageConversionApi\Auth\IpAllowlist;
use Mfonte\ImageConversionApi\Http\Kernel;
use Mfonte\ImageConversionApi\Http\RequestDirectories;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Http\Route;
use Mfonte\ImageConversionApi\Http\Router;
use Mfonte\ImageConversionApi\Http\TrustedProxies;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds a Kernel around routes chosen by a test, with the production
 * collaborators otherwise; KernelFactory builds the production routes.
 */
final class TestKernel
{
    /**
     * The only API key the kernel accepts.
     */
    public const API_KEY = 'test-api-key-0123456789';

    private function __construct()
    {
    }

    /**
     * @param list<Route> $routes
     * @param string      $temporaryDirectory parent of the request directories
     */
    public static function create(array $routes, string $temporaryDirectory, ?LoggerInterface $logger = null): Kernel
    {
        return new Kernel(
            new Router($routes),
            new TrustedProxies(),
            true,
            new ApiKeyAuthenticator([self::API_KEY]),
            new IpAllowlist(),
            new RequestDirectories($temporaryDirectory),
            new ResponseFactory(),
            $logger ?? new NullLogger()
        );
    }
}
