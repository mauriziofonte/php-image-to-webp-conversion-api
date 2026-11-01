<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http\Handler;

use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Http\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks every response of a deprecated endpoint, errors of its limits
 * included: "Deprecation" (RFC 9745, the moment of the deprecation as
 * "@<Unix time>"), "Sunset" (RFC 8594, the HTTP-date after which the
 * endpoint may be removed) and a "Link" to the documentation with the
 * relation "deprecation".
 */
final class DeprecatedHandler implements RequestHandlerInterface
{
    /**
     * @var RequestHandlerInterface
     */
    private $inner;

    /**
     * @var array<string, string>
     */
    private $headers;

    /**
     * @param int    $deprecatedAt  Unix time of the deprecation
     * @param int    $sunsetAt      Unix time after which the endpoint may be removed
     * @param string $documentation URL of the deprecation notice
     */
    public function __construct(RequestHandlerInterface $inner, int $deprecatedAt, int $sunsetAt, string $documentation)
    {
        $this->inner = $inner;
        $this->headers = [
            'Deprecation' => '@' . $deprecatedAt,
            'Sunset' => gmdate('D, d M Y H:i:s', $sunsetAt) . ' GMT',
            'Link' => '<' . $documentation . '>; rel="deprecation"; type="text/html"',
        ];
    }

    public function handle(Request $request, RequestDirectory $directory): Response
    {
        $response = $this->inner->handle($request, $directory);
        $response->headers->add($this->headers);

        return $response;
    }
}
