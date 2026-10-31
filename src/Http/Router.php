<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Mfonte\ImageConversionApi\Exception\InvalidArgumentException;

/**
 * Finds the route of a request path. Paths are compared exactly, byte for
 * byte: no placeholders, no percent-decoding, no case folding and no
 * trailing-slash redirects, so that a path matches one route or none.
 */
final class Router
{
    /**
     * @var array<string, Route> keyed by path
     */
    private $routes = [];

    /**
     * @param list<Route> $routes
     *
     * @throws InvalidArgumentException when two routes share a path or a name
     */
    public function __construct(array $routes)
    {
        $names = [];
        foreach ($routes as $route) {
            if (isset($this->routes[$route->getPath()])) {
                throw new InvalidArgumentException(sprintf('Two routes share the path "%s"', $route->getPath()));
            }
            if (isset($names[$route->getName()])) {
                throw new InvalidArgumentException(sprintf('Two routes share the name "%s"', $route->getName()));
            }
            $this->routes[$route->getPath()] = $route;
            $names[$route->getName()] = true;
        }
    }

    /**
     * The route of $path, whatever the method; null when no route has it.
     *
     * @param string $path the path relative to the front controller (Request::getPathInfo())
     */
    public function match(string $path): ?Route
    {
        return $this->routes[$path] ?? null;
    }

    /**
     * @return list<Route>
     */
    public function getRoutes(): array
    {
        return array_values($this->routes);
    }
}
