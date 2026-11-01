<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http\Handler;

use Mfonte\ImageConversionApi\Conversion\ConverterFactoryInterface;
use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Http\Kernel;
use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Http\RequestHandlerInterface;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Mapping\ErrorMapper;
use Mfonte\ImageConversionApi\Observability\JsonLogger;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /healthz, a public route, in two forms:
 *
 * - without a key (a load balancer, an orchestrator): a fixed document
 *   that tells the service answers and reveals nothing about the host,
 *   without touching the converter or the disk:
 *
 *       {"status": "ok"}
 *
 * - with an accepted API key (the proxy's remote mode, PRX-8): the
 *   capabilities of the converter as well, in the library's own schema
 *   (Capabilities::toArray(), which the proxy reads back with
 *   Capabilities::fromArray()): formats, backends, isolation level and
 *   limits:
 *
 *       {"status": "ok", "capabilities": {"schema": 1, "input_formats": [...],
 *        "output_formats": [...], "animated_output_formats": [...],
 *        "backends": [...], "isolation_level": "...", "limits": {...}}}
 *
 *   When the converter cannot be built (a strict isolation policy the
 *   host cannot meet, an unusable LOCK_DIR), the answer is 503
 *   {"status": "unavailable", "error": {"code", "message"}}, with the
 *   code and message of the same failure on /v2/convert, and the reason
 *   is logged.
 *
 * A wrong key is refused by the kernel (401) before this handler runs.
 * The capabilities are kept out of the public form because they name the
 * backends, the isolation level and the limits of the host: what an
 * attacker would look up first, and what no load balancer needs.
 */
final class HealthHandler implements RequestHandlerInterface
{
    public const STATUS_OK = 'ok';

    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @var ResponseFactory
     */
    private $responses;

    /**
     * @var ConverterFactoryInterface
     */
    private $converters;

    /**
     * @var ErrorMapper
     */
    private $errors;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    private $timeLimitSeconds;

    /**
     * @param int $timeLimitSeconds CONVERSION_TIME_LIMIT, given to the converter whose capabilities are read
     */
    public function __construct(
        ResponseFactory $responses,
        ConverterFactoryInterface $converters,
        ErrorMapper $errors,
        LoggerInterface $logger,
        int $timeLimitSeconds
    ) {
        $this->responses = $responses;
        $this->converters = $converters;
        $this->errors = $errors;
        $this->logger = $logger;
        $this->timeLimitSeconds = $timeLimitSeconds;
    }

    public function handle(Request $request, RequestDirectory $directory): Response
    {
        if (!$request->attributes->has(Kernel::CLIENT_ATTRIBUTE)) {
            return $this->responses->json(['status' => self::STATUS_OK]);
        }

        try {
            $capabilities = $this->converters->create($directory->getPath(), (float) $this->timeLimitSeconds)->getCapabilities();
        } catch (RequestException $exception) {
            $this->logger->error('The converter is unavailable: {reason}', [
                JsonLogger::ERROR_CODE => $exception->getErrorCode(),
                'route' => 'healthz',
                'reason' => $exception->getMessage(),
            ]);

            return $this->unavailable($exception->getErrorCode(), $exception->getMessage(), $exception->getHeaders());
        } catch (LibraryException $exception) {
            $code = $this->errors->codeOf($exception);
            $this->logger->error('The converter is unavailable: {reason}', [
                ErrorMapper::LOG_KEY => $code,
                'route' => 'healthz',
                'reason' => $exception->getMessage(),
            ]);

            return $this->unavailable($code, $this->errors->messageOf($exception), $this->errors->headersOf($exception));
        }

        return $this->responses->json(['status' => self::STATUS_OK, 'capabilities' => $capabilities->toArray()]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function unavailable(string $code, string $message, array $headers): Response
    {
        return $this->responses->json(
            ['status' => self::STATUS_UNAVAILABLE, 'error' => ['code' => $code, 'message' => $message]],
            503,
            $headers
        );
    }
}
