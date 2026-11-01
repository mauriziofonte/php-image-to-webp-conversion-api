<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Mfonte\ImageConversionApi\Auth\ApiKeyAuthenticator;
use Mfonte\ImageConversionApi\Auth\IpAllowlist;
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
 * 2. such a path also needs an accepted API key (ApiKeyAuthenticator) and,
 *    when IP_ALLOWLIST is set, a client address in it: without any
 *    configured key 503 authentication_not_configured, from an address
 *    outside the allowlist 403 ip_not_allowed (before the key is looked at,
 *    so such clients cannot guess keys), without a key 401 missing_api_key,
 *    with another key 401 invalid_api_key. An unauthenticated client
 *    therefore cannot tell which paths exist, as in 1.x, which checked the
 *    key before the endpoint name. An accepted key is named in the request
 *    attribute CLIENT_ATTRIBUTE, for the per-key limits;
 * 3. the route is looked up by exact path: none answers 404, a route that
 *    does not answer the method answers 405 with an Allow header;
 * 4. the handler of the route runs with a temporary directory of its own,
 *    which is removed when the request ends, whatever happened (see
 *    RequestDirectories for fatal errors), with a warning in the log when
 *    part of it cannot be removed; an exception the handler throws is
 *    logged and answered with a generic 500.
 *
 * The method is the one the client sent (REQUEST_METHOD): method overrides
 * (X-HTTP-Method-Override, _method) are ignored. A HEAD request gets the
 * headers of the response without its body.
 *
 * Every response carries the request ID (RequestId: the X-Request-Id the
 * client sent, such as the proxy's, or a new one) in X-Request-Id, and
 * every request ends with one info entry in the log, "Request handled",
 * with the method, the route name (null when no route matched: the path
 * itself is never logged, since the /v1 path holds V1_SCRIPT_NAME), the
 * status and the duration in milliseconds.
 *
 * The kernel only builds the response: it never sends it, never exits and
 * reads nothing but the Request, so that tests and other front controllers
 * can drive it.
 */
final class Kernel
{
    /**
     * Request attribute that names the authenticated client, as
     * ApiKeyAuthenticator::clientId() identifies its API key, for per-key
     * limits; set before the handler of a protected route runs.
     */
    public const CLIENT_ATTRIBUTE = '_image_conversion_api_client';

    /**
     * Message of the access entry logged at the end of every request.
     */
    public const ACCESS_LOG_MESSAGE = 'Request handled';

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
     * @var ApiKeyAuthenticator
     */
    private $authenticator;

    /**
     * @var IpAllowlist
     */
    private $allowlist;

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
        ApiKeyAuthenticator $authenticator,
        IpAllowlist $allowlist,
        RequestDirectories $directories,
        ResponseFactory $responses,
        LoggerInterface $logger
    ) {
        $this->router = $router;
        $this->proxies = $proxies;
        $this->httpsRequired = $httpsRequired;
        $this->authenticator = $authenticator;
        $this->allowlist = $allowlist;
        $this->directories = $directories;
        $this->responses = $responses;
        $this->logger = $logger;
    }

    public function handle(Request $request): Response
    {
        $startedAt = microtime(true);
        $requestId = RequestId::of($request);
        $method = $request->getRealMethod();
        $route = $this->router->match($request->getPathInfo());
        $response = $this->dispatch($request, $method, $route);
        if ($method === 'HEAD') {
            // null, not '': StreamedResponse and BinaryFileResponse refuse any other content.
            $response->setContent(null);
        }
        $response->headers->set(RequestId::HEADER, $requestId);
        $this->logger->info(self::ACCESS_LOG_MESSAGE, [
            'method' => $method,
            'route' => $route !== null ? $route->getName() : null,
            'status' => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $response;
    }

    private function dispatch(Request $request, string $method, ?Route $route): Response
    {
        if ($route === null || !$route->isPublic()) {
            $refusal = $this->refuseAccess($request);
            if ($refusal !== null) {
                return $refusal;
            }
        }
        if ($route === null) {
            return $this->responses->notFound();
        }
        if (!$route->allowsMethod($method)) {
            return $this->responses->methodNotAllowed($route->getMethods());
        }

        return $this->run($route, $request);
    }

    /**
     * The response that refuses a request to a protected path; null when
     * the request may go on.
     */
    private function refuseAccess(Request $request): ?Response
    {
        if ($this->httpsRequired && !$this->proxies->isSecure($request)) {
            return $this->responses->httpsRequired();
        }

        if (!$this->authenticator->isConfigured()) {
            $this->logger->critical('No API key is configured (API_KEY, API_KEYS): every protected request is refused');

            return $this->responses->authenticationNotConfigured();
        }
        if (!$this->allowlist->allows($this->proxies->getClientAddress($request))) {
            return $this->responses->ipNotAllowed();
        }

        switch ($this->authenticator->authenticate($request)) {
            case ApiKeyAuthenticator::AUTHENTICATED:
                $request->attributes->set(self::CLIENT_ATTRIBUTE, $this->authenticator->clientId($request));

                return null;
            case ApiKeyAuthenticator::MISSING_KEY:
                return $this->responses->missingApiKey();
            default:
                return $this->responses->invalidApiKey();
        }
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
            if (!$directory->remove()) {
                $this->logger->warning('The temporary directory {directory} of route {route} could not be removed entirely', [
                    'route' => $route->getName(),
                    'directory' => $directory->getCreatedPath(),
                ]);
            }
        }
    }
}
