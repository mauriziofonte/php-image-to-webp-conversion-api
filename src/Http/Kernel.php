<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a request into a response:
 *
 * 1. a path that is not a public route (every path but /healthz, unknown
 *    paths included) must have reached the service over HTTPS, as
 *    TrustedProxies tells it, unless REQUIRE_HTTPS is off: otherwise 400
 *    https_required, before anything else is revealed;
 * 2. the route is looked up by exact path: none answers 404, a route that
 *    does not answer the method answers 405 with an Allow header;
 * 3. the handler of the route runs with a temporary directory of its own,
 *    which is removed when the request ends, whatever happened (see
 *    RequestDirectories for fatal errors); an exception it throws is logged
 *    and answered with a generic 500.
 *
 * The method is the one the client sent (REQUEST_METHOD): method overrides
 * (X-HTTP-Method-Override, _method) are ignored. A HEAD request gets the
 * headers of the response without its body.
 *
 * The kernel only builds the response: it never sends it, never exits and
 * reads nothing but the Request, so that tests and other front controllers
 * can drive it.
 */
final class Kernel
{
    /**
     * @var Router
     */
    private $router;

    /**
     * @var TrustedProxies
     */
    private $proxies;

    /**
     * @var bool
     */
    private $httpsRequired;

    /**
     * @var RequestDirectories
     */
    private $directories;

    /**
     * @var ResponseFactory
     */
    private $responses;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param bool $httpsRequired whether protected paths refuse requests that did not arrive over HTTPS
     */
    public function __construct(
        Router $router,
        TrustedProxies $proxies,
        bool $httpsRequired,
        RequestDirectories $directories,
        ResponseFactory $responses,
        LoggerInterface $logger
    ) {
        $this->router = $router;
        $this->proxies = $proxies;
        $this->httpsRequired = $httpsRequired;
        $this->directories = $directories;
        $this->responses = $responses;
        $this->logger = $logger;
    }

    public function handle(Request $request): Response
    {
        $method = $request->getRealMethod();
        $response = $this->dispatch($request, $method);
        if ($method === 'HEAD') {
            // null, not '': StreamedResponse and BinaryFileResponse refuse any other content.
            $response->setContent(null);
        }

        return $response;
    }

    private function dispatch(Request $request, string $method): Response
    {
        $route = $this->router->match($request->getPathInfo());
        $public = $route !== null && $route->isPublic();
        if (!$public && $this->httpsRequired && !$this->proxies->isSecure($request)) {
            return $this->responses->httpsRequired();
        }
        if ($route === null) {
            return $this->responses->notFound();
        }
        if (!$route->allowsMethod($method)) {
            return $this->responses->methodNotAllowed($route->getMethods());
        }

        return $this->run($route, $request);
    }

    private function run(Route $route, Request $request): Response
    {
        $directory = $this->directories->forRequest();
        try {
            return $route->getHandler()->handle($request, $directory);
        } catch (\Throwable $exception) {
            $this->logger->error('Unhandled exception in the handler of route {route}', [
                'route' => $route->getName(),
                'exception' => $exception,
            ]);

            return $this->responses->internalError();
        } finally {
            $directory->remove();
        }
    }
}
