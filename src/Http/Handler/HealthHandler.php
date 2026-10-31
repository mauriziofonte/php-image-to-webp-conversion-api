<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http\Handler;

use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Http\RequestHandlerInterface;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /healthz: tells a load balancer or an orchestrator that the service
 * answers. Public route, so the body is a fixed document that reveals
 * nothing about the configuration:
 *
 *     {"status": "ok"}
 *
 * API-7 extends it with the checks and capabilities the proxy reads.
 */
final class HealthHandler implements RequestHandlerInterface
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
        return $this->responses->json(['status' => 'ok']);
    }
}
