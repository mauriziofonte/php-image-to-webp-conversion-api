<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http\Handler;

use Mfonte\ImageConversionApi\Conversion\ConverterFactoryInterface;
use Mfonte\ImageConversionApi\Conversion\Deadline;
use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Http\InputReader;
use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Http\RequestHandlerInterface;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Mapping\ErrorMapper;
use Mfonte\ImageConversionApi\Mapping\V2RequestMapper;
use Mfonte\ImageConversionApi\Observability\JsonLogger;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /v2/convert: converts one image and answers it as the binary body
 * (findings C-6 and C-7).
 *
 * 1. The parameters are mapped to a ConversionRequest (V2RequestMapper):
 *    an invalid one is refused before the body is looked at.
 * 2. The image is found or stored on disk (InputReader), never loaded in
 *    memory.
 * 3. A converter is created for the request (ConverterFactoryInterface),
 *    working inside the request directory, with what is left of
 *    CONVERSION_TIME_LIMIT as its processing ceiling.
 * 4. The output is converted into the request directory and answered as a
 *    stream opened before the kernel removes the directory, with the
 *    X-Conversion-* headers of V2RequestMapper.
 *
 * Errors answer {"error": {"code", "message"}} with the status of the code:
 * the library's codes as ErrorMapper maps them (400, 413, 415, 422, 500,
 * 503 with Retry-After), and the API's own (400 missing_image, 415
 * unsupported_media_type). Each failure is logged with its code, under
 * ErrorMapper::LOG_KEY (conversion_error_code) for a code of the library
 * (file_too_large included, when the body reader finds it first) and
 * ERROR_LOG_KEY (error_code) for one of the API, and with the exception's
 * message as "reason": at info level for the request's errors, at error
 * level for the server's, with the exception itself (its previous one
 * included), so that the cause of a fixed client sentence such as 503
 * concurrency_unavailable reaches the log.
 */
final class V2ConvertHandler implements RequestHandlerInterface
{
    /**
     * Name of the output in the request directory.
     */
    public const OUTPUT_NAME = 'output';

    /**
     * The log context key of an error code of the API itself (missing_image,
     * unsupported_media_type...), kept apart from the library's codes under
     * ErrorMapper::LOG_KEY.
     */
    public const ERROR_LOG_KEY = JsonLogger::ERROR_CODE;

    /**
     * @var ConverterFactoryInterface
     */
    private $converters;

    /**
     * @var InputReader
     */
    private $input;

    /**
     * @var V2RequestMapper
     */
    private $mapper;

    /**
     * @var ErrorMapper
     */
    private $errors;

    /**
     * @var ResponseFactory
     */
    private $responses;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    private $timeLimitSeconds;

    /**
     * @param int $timeLimitSeconds CONVERSION_TIME_LIMIT: the longest time the request may spend converting, from
     *                              the moment PHP received it
     */
    public function __construct(
        ConverterFactoryInterface $converters,
        InputReader $input,
        V2RequestMapper $mapper,
        ErrorMapper $errors,
        ResponseFactory $responses,
        LoggerInterface $logger,
        int $timeLimitSeconds
    ) {
        $this->converters = $converters;
        $this->input = $input;
        $this->mapper = $mapper;
        $this->errors = $errors;
        $this->responses = $responses;
        $this->logger = $logger;
        $this->timeLimitSeconds = $timeLimitSeconds;
    }

    public function handle(Request $request, RequestDirectory $directory): Response
    {
        $deadline = Deadline::forRequest($request, (float) $this->timeLimitSeconds);

        try {
            $conversion = $this->mapper->toConversionRequest($request);
            $input = $this->input->read($request, $directory, $this->converters->getMaxInputBytes());
            $remaining = $deadline->remainingSeconds();
            if ($remaining <= 0.0) {
                throw new ConversionTimeoutException('The time limit of the request ran out before the conversion started.');
            }
            $converter = $this->converters->create($directory->getPath(), $remaining);
            $outputPath = $directory->getPath() . DIRECTORY_SEPARATOR . self::OUTPUT_NAME;
            $result = $converter->convert($input, $outputPath, $conversion);
            $output = @fopen($outputPath, 'rb');
            if ($output === false) {
                throw new RequestException(500, ResponseFactory::ERROR_INTERNAL, 'The request could not be processed.');
            }
            $inputBytes = @filesize($input);

            return $this->mapper->toResponse($result, $conversion, $output, $inputBytes === false ? 0 : $inputBytes);
        } catch (RequestException $exception) {
            $this->log($exception->getStatusCode() >= 500, $exception->getErrorCode(), $exception);

            return $this->responses->fromException($exception);
        } catch (LibraryException $exception) {
            $code = $this->errors->codeOf($exception);
            $this->log($this->errors->isServerError($exception), $code, $exception);

            return $this->responses->error(
                $this->errors->statusOf($code),
                $code,
                $this->errors->messageOf($exception),
                $this->errors->headersOf($exception)
            );
        }
    }

    /**
     * Logs a failure with its code: under ErrorMapper::LOG_KEY for one of
     * the library's codes, under ERROR_LOG_KEY for one of the API's.
     */
    private function log(bool $serverError, string $code, \Throwable $exception): void
    {
        $key = in_array($code, ErrorCode::all(), true) ? ErrorMapper::LOG_KEY : self::ERROR_LOG_KEY;
        $context = [$key => $code, 'route' => 'v2_convert', 'reason' => $exception->getMessage()];
        if ($serverError) {
            $context['exception'] = $exception;
            $this->logger->error('The conversion failed: {' . $key . '}', $context);
        } else {
            $this->logger->info('The conversion request was refused: {' . $key . '}', $context);
        }
    }
}
