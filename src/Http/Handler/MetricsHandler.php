<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http\Handler;

use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Http\RequestHandlerInterface;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Observability\Metrics;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /metrics: the Prometheus text exposition of Metrics (requests,
 * conversions and their durations, counted by every PHP process of the
 * host). A protected route: it needs an API key, or one of METRICS_KEYS,
 * which are accepted here only (see KernelFactory).
 */
final class MetricsHandler implements RequestHandlerInterface
{
    /**
     * @var ResponseFactory
     */
    private $responses;

    /**
     * @var Metrics
     */
    private $metrics;

    public function __construct(ResponseFactory $responses, Metrics $metrics)
    {
        $this->responses = $responses;
        $this->metrics = $metrics;
    }

    public function handle(Request $request, RequestDirectory $directory): Response
    {
        return $this->responses->prometheus($this->metrics->render());
    }
}
