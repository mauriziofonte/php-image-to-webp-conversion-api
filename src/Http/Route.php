<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Mfonte\ImageConversionApi\Auth\ApiKeyAuthenticator;
use Mfonte\ImageConversionApi\Exception\InvalidArgumentException;

/**
 * One endpoint: an exact path, the methods it answers and its handler.
 *
 * A public route is exempt from the access checks (HTTPS, credentials)
 * that the kernel applies to every other path, so it must not reveal
 * anything about the configuration. A protected route may name its own
 * authenticator, which then replaces the kernel's (/metrics accepts the
 * METRICS_KEYS as well as the API keys).
 *
 * Immutable value object.
 */
final class Route
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $path;

    /**
     * @var list<string>
     */
    private $methods;

    /**
     * @var RequestHandlerInterface
     */
    private $handler;

    /**
     * @var bool
     */
    private $public;

    /**
     * @var ApiKeyAuthenticator|null
     */
    private $authenticator;

    /**
     * @param string                   $name          identifier used in logs, such as "v2_convert"
     * @param string                   $path          exact path relative to the front controller, starting with "/" (no
     *                                                placeholders, no trailing slash except for "/" itself)
     * @param list<string>             $methods       HTTP methods the route answers, in the order of the Allow header
     * @param bool                     $public        whether the route answers without HTTPS and without credentials
     * @param ApiKeyAuthenticator|null $authenticator the keys of this route, in place of the kernel's; null for the
     *                                                kernel's
     *
     * @throws InvalidArgumentException when the name, the path or a method is malformed
     */
    public function __construct(
        string $name,
        string $path,
        array $methods,
        RequestHandlerInterface $handler,
        bool $public = false,
        ?ApiKeyAuthenticator $authenticator = null
    ) {
        if (preg_match('/\A[a-z0-9_]+\z/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Route name "%s" must match [a-z0-9_]+', $name));
        }
        if (preg_match('#\A(?:/[A-Za-z0-9._~-]+)+\z|\A/\z#', $path) !== 1) {
            throw new InvalidArgumentException(sprintf('Route path "%s" must be "/" or "/" followed by unreserved characters', $path));
        }
        if ($methods === []) {
            throw new InvalidArgumentException(sprintf('Route "%s" must answer at least one method', $name));
        }
        foreach ($methods as $method) {
            if (preg_match('/\A[A-Z]+\z/', $method) !== 1) {
                throw new InvalidArgumentException(sprintf('Route "%s" has an invalid method "%s"', $name, $method));
            }
        }

        $this->name = $name;
        $this->path = $path;
        $this->methods = array_values(array_unique($methods));
        $this->handler = $handler;
        $this->public = $public;
        $this->authenticator = $authenticator;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return list<string>
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    public function allowsMethod(string $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    public function getHandler(): RequestHandlerInterface
    {
        return $this->handler;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * The keys of this route, or null when the kernel's apply.
     */
    public function getAuthenticator(): ?ApiKeyAuthenticator
    {
        return $this->authenticator;
    }
}
