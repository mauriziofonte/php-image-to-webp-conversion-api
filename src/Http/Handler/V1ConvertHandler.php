<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http\Handler;

use Mfonte\ImageConversionApi\Conversion\ConverterFactoryInterface;
use Mfonte\ImageConversionApi\Conversion\Deadline;
use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Http\RequestHandlerInterface;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Mapping\ErrorMapper;
use Mfonte\ImageConversionApi\Mapping\V1RequestMapper;
use Mfonte\ImageConversionApi\Mapping\V1ResponseFactory;
use Mfonte\ImageConversionApi\Observability\Metrics;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionTimeoutException;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /v1/{V1_SCRIPT_NAME}: the 1.x batch endpoint, kept for its clients
 * and deprecated (see KernelFactory for the Deprecation and Sunset
 * headers). The request and the JSON document are those of 1.x
 * (V1RequestMapper, V1ResponseFactory); the conversion is the library's,
 * with the limits of 2.x:
 *
 * - at most V1_MAX_FILES files (413 otherwise), each at most
 *   MAX_IMAGE_BYTES, within MAX_BODY_BYTES as a whole (LimitingHandler);
 * - every file is converted as /v2/convert converts one: in the request
 *   directory, taking a conversion slot, within what is left of
 *   CONVERSION_TIME_LIMIT for the whole batch;
 * - the output must be WEBP: when the library keeps the original (the
 *   WEBP file was not smaller) or falls back to another format, the file
 *   fails, so that a client that stores the result as WEBP never receives
 *   another format.
 *
 * A request refused as a whole answers {"status": false, "version",
 * "message"} with the status of the error, where 1.x answered 200: 400
 * without images or with invalid descriptors, 413 with too many files, 503
 * with Retry-After when no conversion slot frees up, the slots cannot be
 * taken (an unusable LOCK_DIR, logged at error level with its cause) or
 * the isolation is unavailable (the remaining files could not be
 * converted either). Any
 * other failure is the file's own: its entry has "status": false and the
 * 1.x message for an upload error, an extension or a failed conversion
 * ("Conversion failed."), the fixed ErrorMapper sentence for a rejected
 * image. The library's messages go to the log only, with the error code
 * under ErrorMapper::LOG_KEY. Each file handed to the converter is counted
 * in the metrics with its outcome: converted, not_webp or its error code.
 */
final class V1ConvertHandler implements RequestHandlerInterface
{
    /**
     * Name of the route, in logs and metrics.
     */
    public const ROUTE = 'v1';

    /**
     * Metrics outcome of a file whose conversion did not produce WEBP (the
     * original was kept, or another format was the fallback).
     */
    public const OUTCOME_NOT_WEBP = 'not_webp';

    /**
     * Prefix of the outputs in the request directory, followed by the index of the file.
     */
    public const OUTPUT_PREFIX = 'output-';

    /**
     * Logged code of a request without images (the body has no code: 1.x had none).
     */
    public const ERROR_NO_IMAGES = 'missing_image';

    /**
     * Logged code of a request with more than V1_MAX_FILES files.
     */
    public const ERROR_TOO_MANY_FILES = 'too_many_files';

    /**
     * Logged code of a request whose descriptors are not a JSON array.
     */
    public const ERROR_INVALID_DESCRIPTORS = 'invalid_descriptors';

    /**
     * @var ConverterFactoryInterface
     */
    private $converters;

    /**
     * @var V1RequestMapper
     */
    private $mapper;

    /**
     * @var V1ResponseFactory
     */
    private $responses;

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
     * @var int
     */
    private $maxFiles;

    /**
     * @var Metrics
     */
    private $metrics;

    /**
     * @param int          $timeLimitSeconds CONVERSION_TIME_LIMIT, for the whole batch
     * @param int          $maxFiles         V1_MAX_FILES
     * @param Metrics|null $metrics          counts the outcome of each file handed to the converter; nothing is
     *                                       counted when null
     */
    public function __construct(
        ConverterFactoryInterface $converters,
        V1RequestMapper $mapper,
        V1ResponseFactory $responses,
        ErrorMapper $errors,
        LoggerInterface $logger,
        int $timeLimitSeconds,
        int $maxFiles,
        ?Metrics $metrics = null
    ) {
        $this->converters = $converters;
        $this->mapper = $mapper;
        $this->responses = $responses;
        $this->errors = $errors;
        $this->logger = $logger;
        $this->timeLimitSeconds = $timeLimitSeconds;
        $this->maxFiles = $maxFiles;
        $this->metrics = $metrics ?? new Metrics(null);
    }

    public function handle(Request $request, RequestDirectory $directory): Response
    {
        $deadline = Deadline::forRequest($request, (float) $this->timeLimitSeconds);
        $files = $this->mapper->files($request);
        if ($files === null) {
            return $this->refuse(400, self::ERROR_NO_IMAGES, 'No images uploaded.');
        }
        if (count($files) > $this->maxFiles) {
            return $this->refuse(413, self::ERROR_TOO_MANY_FILES, sprintf('Too many images: send at most %d per request.', $this->maxFiles));
        }
        $descriptors = $this->mapper->descriptors($request);
        if ($descriptors === null) {
            return $this->refuse(400, self::ERROR_INVALID_DESCRIPTORS, 'Invalid descriptors JSON.');
        }
        $conversion = $this->mapper->toConversionRequest($request);

        $startedAt = microtime(true);
        $entries = [];
        foreach ($files as $index => $file) {
            $filename = $file !== null ? $file->getClientOriginalName() : '';
            $descriptor = $this->mapper->descriptorOf($filename, $descriptors);

            try {
                $entries[] = $this->convert($file, $filename, $descriptor, $index, $conversion, $directory, $deadline);
            } catch (RequestException $exception) {
                self::close($entries);
                $this->metrics->recordConversion(self::ROUTE, $exception->getErrorCode(), 0.0);
                $this->logRefusal($exception);

                return $this->responses->error($exception->getStatusCode(), $exception->getMessage(), $exception->getHeaders());
            } catch (LibraryException $exception) {
                $code = $this->errors->codeOf($exception);
                $this->metrics->recordConversion(self::ROUTE, $code, 0.0);
                $this->log($this->errors->isServerError($exception), $code, $exception);
                if ($this->errors->statusOf($code) === 503) {
                    self::close($entries);

                    return $this->responses->error(503, $this->errors->messageOf($exception), $this->errors->headersOf($exception));
                }
                $message = $this->errors->isServerError($exception) ? 'Conversion failed.' : $this->errors->messageOf($exception);
                $entries[] = self::failed($descriptor, $filename, $message);
            }
        }

        return $this->responses->batch($entries, microtime(true) - $startedAt);
    }

    /**
     * The entry of one file.
     *
     * @param array<mixed> $descriptor
     *
     * @throws LibraryException when the conversion fails
     * @throws RequestException when the conversion slots cannot be taken
     *
     * @return array{data: array<mixed>, image: resource|null, imageBytes: int}
     */
    private function convert(
        ?UploadedFile $file,
        string $filename,
        array $descriptor,
        int $index,
        ConversionRequest $conversion,
        RequestDirectory $directory,
        Deadline $deadline
    ): array {
        $error = $file !== null ? $file->getError() : UPLOAD_ERR_NO_FILE;
        if ($file === null || $error !== UPLOAD_ERR_OK) {
            return self::failed($descriptor, $filename, $this->mapper->uploadErrorMessage($error));
        }
        if (!$this->mapper->hasAcceptedExtension($filename)) {
            return self::failed($descriptor, $filename, 'Unsupported file extension.');
        }
        $input = $file->getPathname();
        clearstatcache(true, $input);
        $inputBytes = @filesize($input);
        if (!$file->isValid() || $inputBytes === false) {
            return self::failed($descriptor, $filename, $this->mapper->uploadErrorMessage(-1));
        }
        if ($inputBytes > $this->converters->getMaxInputBytes()) {
            return self::failed($descriptor, $filename, 'The uploaded file exceeds the maximum file size.');
        }
        $remaining = $deadline->remainingSeconds();
        if ($remaining <= 0.0) {
            throw new ConversionTimeoutException('The time limit of the request ran out before the conversion started.');
        }

        $outputPath = $directory->getPath() . DIRECTORY_SEPARATOR . self::OUTPUT_PREFIX . $index;
        $startedAt = microtime(true);
        $result = $this->converters->create($directory->getPath(), $remaining)->convert($input, $outputPath, $conversion);
        $seconds = microtime(true) - $startedAt;
        if ($result->getOutputFormat() !== ImageFormat::WEBP) {
            $this->metrics->recordConversion(self::ROUTE, self::OUTCOME_NOT_WEBP, $seconds);
            $this->logger->info('The 1.x conversion did not produce WEBP: {format}', [
                'format' => $result->getOutputFormat(),
                'original_kept' => $result->isPassthrough(),
                'route' => self::ROUTE,
            ]);

            return self::failed($descriptor, $filename, $result->isPassthrough()
                ? 'The WEBP image would not be smaller than the original.'
                : 'Conversion failed.');
        }
        $output = @fopen($outputPath, 'rb');
        if ($output === false || $inputBytes === 0) {
            $this->metrics->recordConversion(self::ROUTE, ResponseFactory::ERROR_INTERNAL, $seconds);

            return self::failed($descriptor, $filename, 'Conversion failed.');
        }
        $this->metrics->recordConversion(self::ROUTE, Metrics::CONVERTED, $seconds);
        $outputBytes = $result->getByteSize();

        return [
            'data' => array_merge($descriptor, [
                'filename' => $filename,
                'status' => true,
                'orig_filesize' => $this->mapper->formatBytes($inputBytes),
                'new_filesize' => $this->mapper->formatBytes($outputBytes),
                'compression_ratio' => $this->mapper->compressionRatio($inputBytes, $outputBytes),
                V1ResponseFactory::IMAGE_KEY => '',
            ]),
            'image' => $output,
            'imageBytes' => $outputBytes,
        ];
    }

    /**
     * @param array<mixed> $descriptor
     *
     * @return array{data: array<mixed>, image: null, imageBytes: int}
     */
    private static function failed(array $descriptor, string $filename, string $message): array
    {
        return [
            'data' => array_merge($descriptor, ['filename' => $filename, 'status' => false, 'error' => $message]),
            'image' => null,
            'imageBytes' => 0,
        ];
    }

    /**
     * Closes the outputs of the entries of a batch that is refused.
     *
     * @param list<array{data: array<mixed>, image: resource|null, imageBytes: int}> $entries
     */
    private static function close(array $entries): void
    {
        foreach ($entries as $entry) {
            if ($entry['image'] !== null) {
                fclose($entry['image']);
            }
        }
    }

    private function refuse(int $status, string $code, string $message): Response
    {
        $this->logger->info('The 1.x request was refused: {' . V2ConvertHandler::ERROR_LOG_KEY . '}', [
            V2ConvertHandler::ERROR_LOG_KEY => $code,
            'route' => self::ROUTE,
        ]);

        return $this->responses->error($status, $message);
    }

    /**
     * Logs a request refused as a whole by the API (the conversion slots
     * cannot be taken), with its cause.
     */
    private function logRefusal(RequestException $exception): void
    {
        $context = [V2ConvertHandler::ERROR_LOG_KEY => $exception->getErrorCode(), 'route' => self::ROUTE, 'reason' => $exception->getMessage()];
        if ($exception->getStatusCode() >= 500) {
            $context['exception'] = $exception;
            $this->logger->error('The conversion failed: {' . V2ConvertHandler::ERROR_LOG_KEY . '}', $context);
        } else {
            $this->logger->info('The 1.x request was refused: {' . V2ConvertHandler::ERROR_LOG_KEY . '}', $context);
        }
    }

    /**
     * Logs a failed file with its library error code.
     */
    private function log(bool $serverError, string $code, LibraryException $exception): void
    {
        $context = [ErrorMapper::LOG_KEY => $code, 'route' => self::ROUTE, 'reason' => $exception->getMessage()];
        if ($serverError) {
            $this->logger->error('The conversion failed: {' . ErrorMapper::LOG_KEY . '}', $context);
        } else {
            $this->logger->info('The conversion request was refused: {' . ErrorMapper::LOG_KEY . '}', $context);
        }
    }
}
