<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http\Handler;

use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Http\RequestHandlerInterface;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Placeholder of the conversion endpoints until they are implemented
 * (POST /v1/{V1_SCRIPT_NAME} until API-5): answers
 * 501 with the error code "not_implemented", after the same checks as the
 * finished endpoint (HTTPS, method, credentials).
 */
final class NotImplementedHandler implements RequestHandlerInterface
{
    /**
     * @var ResponseFactory
     */
    private $responses;

    public function __construct(ResponseFactory $responses)
    {
        $this->responses = $responses;
    }

    public function handle(Request $request, RequestDirectory $directory): Response
    {
        return $this->responses->notImplemented();
    }
}
