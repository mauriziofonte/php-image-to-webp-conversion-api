<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Limits;

use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Exception\RuntimeException;
use Mfonte\ImageConversionApi\Http\Kernel;
use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Http\RequestHandlerInterface;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request limits in front of a conversion endpoint, before it
 * reads the body:
 *
 * 1. the rate limit of the API key (RateLimiter, when RATE_LIMIT is not
 *    0): above it, 429 rate_limited with Retry-After; when its store
 *    fails, 503 rate_limit_unavailable, logged at error level (the
 *    request is refused, never let through uncounted);
 * 2. the declared size of the body (RequestLimits): above MAX_BODY_BYTES,
 *    or a multipart body above post_max_size, 413 body_too_large.
 *
 * The client is the API key the kernel authenticated, which it names in
 * the request attribute Kernel::CLIENT_ATTRIBUTE.
 */
final class LimitingHandler implements RequestHandlerInterface
{
    /**
     * @var RequestHandlerInterface
     */
    private $inner;

    /**
     * @var RequestLimits
     */
    private $limits;

    /**
     * @var RateLimiter|null
     */
    private $rateLimiter;

    /**
     * @var ResponseFactory
     */
    private $responses;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RateLimiter|null $rateLimiter null when rate limiting is off
     */
    public function __construct(
        RequestHandlerInterface $inner,
        RequestLimits $limits,
        ?RateLimiter $rateLimiter,
        ResponseFactory $responses,
        LoggerInterface $logger
    ) {
        $this->inner = $inner;
        $this->limits = $limits;
        $this->rateLimiter = $rateLimiter;
        $this->responses = $responses;
        $this->logger = $logger;
    }

    public function handle(Request $request, RequestDirectory $directory): Response
    {
        $client = $request->attributes->get(Kernel::CLIENT_ATTRIBUTE);
        if ($this->rateLimiter !== null && is_string($client)) {
            try {
                $wait = $this->rateLimiter->consume($client);
            } catch (RuntimeException $exception) {
                $this->logger->error('The rate limit store failed', ['error_code' => ResponseFactory::ERROR_RATE_LIMIT_UNAVAILABLE, 'reason' => $exception->getMessage()]);

                return $this->responses->error(
                    503,
                    ResponseFactory::ERROR_RATE_LIMIT_UNAVAILABLE,
                    'The rate limit cannot be checked: retry later.',
                    ['Retry-After' => '1']
                );
            }
            if ($wait > 0) {
                $this->logger->info('The API key exceeded its rate limit', ['error_code' => ResponseFactory::ERROR_RATE_LIMITED, 'retry_after' => $wait]);

                return $this->responses->error(
                    429,
                    ResponseFactory::ERROR_RATE_LIMITED,
                    'This API key sent too many requests: retry later.',
                    ['Retry-After' => (string) $wait]
                );
            }
        }

        try {
            $this->limits->assertBodyAllowed($request);
        } catch (RequestException $exception) {
            $this->logger->info('The request body is too large', ['error_code' => $exception->getErrorCode()]);

            return $this->responses->fromException($exception);
        }

        return $this->inner->handle($request, $directory);
    }
}
