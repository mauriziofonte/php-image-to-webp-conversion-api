<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http\Handler;

use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Http\RequestHandlerInterface;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /metrics: the Prometheus text exposition. For now it only exposes
 * image_conversion_api_up; API-7 adds the request and conversion metrics.
 */
final class MetricsHandler implements RequestHandlerInterface
{
    private const BODY = "# HELP image_conversion_api_up Whether the conversion API answers (always 1 in a scrape that succeeds).\n"
        . "# TYPE image_conversion_api_up gauge\n"
        . "image_conversion_api_up 1\n";

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
        return $this->responses->prometheus(self::BODY);
    }
}
