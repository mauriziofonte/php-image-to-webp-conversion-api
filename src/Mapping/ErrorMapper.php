<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Mapping;

use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\ErrorCode;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidRequestException;

/**
 * Maps the exceptions of the conversion library to HTTP: the status of each
 * error code (1:1, as Contract\Exception\ErrorCode and conventions.md fix
 * it), the fixed sentence of the error body and the headers that go with it.
 *
 * | Error code                   | Status | Headers     |
 * | ---------------------------- | ------ | ----------- |
 * | invalid_request              | 400    |             |
 * | unsupported_format           | 415    |             |
 * | invalid_image                | 422    |             |
 * | animation_rejected           | 422    |             |
 * | file_too_large               | 413    |             |
 * | image_too_large              | 422    |             |
 * | conversion_timeout           | 422    |             |
 * | resource_limit               | 422    |             |
 * | isolation_unavailable        | 503    |             |
 * | concurrency_limit            | 503    | Retry-After |
 * | conversion_failed            | 500    |             |
 * | output_verification_failed   | 500    |             |
 * | internal_error, any other    | 500    |             |
 *
 * The error body carries the library's error code and a fixed sentence of
 * this class, never the message of the exception: library messages are
 * meant for logs (they can name host details, such as a missing tool, and
 * the unknown parameter a client sent), and the ResponseFactory rule holds
 * that error bodies never echo request data. The one detail added is the
 * canonical name of the parameter an invalid_request is about, which is
 * one of ConversionRequest::parameterNames(), never a name the client
 * chose. Callers log the exception message with the error code under
 * LOG_KEY.
 */
final class ErrorMapper
{
    /**
     * The log context key of a library error code, shared with the proxy, which
     * logs the code the API answers under the same name.
     */
    public const LOG_KEY = 'conversion_error_code';

    private const STATUSES = [
        ErrorCode::INVALID_REQUEST => 400,
        ErrorCode::UNSUPPORTED_FORMAT => 415,
        ErrorCode::INVALID_IMAGE => 422,
        ErrorCode::ANIMATION_REJECTED => 422,
        ErrorCode::FILE_TOO_LARGE => 413,
        ErrorCode::IMAGE_TOO_LARGE => 422,
        ErrorCode::CONVERSION_TIMEOUT => 422,
        ErrorCode::RESOURCE_LIMIT => 422,
        ErrorCode::ISOLATION_UNAVAILABLE => 503,
        ErrorCode::CONCURRENCY_LIMIT => 503,
        ErrorCode::CONVERSION_FAILED => 500,
        ErrorCode::OUTPUT_VERIFICATION_FAILED => 500,
        ErrorCode::INTERNAL_ERROR => 500,
    ];

    private const MESSAGES = [
        ErrorCode::INVALID_REQUEST => 'The conversion parameters are not valid.',
        ErrorCode::UNSUPPORTED_FORMAT => 'The image format is not supported.',
        ErrorCode::INVALID_IMAGE => 'The image is malformed, truncated or not of the format its content claims.',
        ErrorCode::ANIMATION_REJECTED => 'The image is animated and the request rejects animations.',
        ErrorCode::FILE_TOO_LARGE => 'The image is larger than the maximum file size.',
        ErrorCode::IMAGE_TOO_LARGE => 'The image, or the requested output, exceeds a dimension, pixel or frame limit.',
        ErrorCode::CONVERSION_TIMEOUT => 'The conversion would exceed the processing time limit.',
        ErrorCode::RESOURCE_LIMIT => 'The conversion exceeded its memory, CPU time or file size limit.',
        ErrorCode::ISOLATION_UNAVAILABLE => 'The isolation that conversions require is not available on this server.',
        ErrorCode::CONCURRENCY_LIMIT => 'Too many conversions are running: retry later.',
        ErrorCode::CONVERSION_FAILED => 'The image could not be converted.',
        ErrorCode::OUTPUT_VERIFICATION_FAILED => 'The conversion output failed its verification.',
        ErrorCode::INTERNAL_ERROR => 'The request could not be processed.',
    ];

    /**
     * The HTTP status of a library error code; 500 for a code this version
     * does not know.
     */
    public function statusOf(string $errorCode): int
    {
        return self::STATUSES[$errorCode] ?? 500;
    }

    /**
     * The error code to answer: the exception's own, or internal_error for
     * a code this version does not know, so that the body never carries a
     * code whose status is unknown.
     */
    public function codeOf(ExceptionInterface $exception): string
    {
        $code = $exception->getErrorCode();

        return isset(self::STATUSES[$code]) ? $code : ErrorCode::INTERNAL_ERROR;
    }

    /**
     * The fixed sentence of the error body.
     */
    public function messageOf(ExceptionInterface $exception): string
    {
        $code = $this->codeOf($exception);
        $message = self::MESSAGES[$code];
        if ($exception instanceof InvalidRequestException) {
            $parameter = $exception->getParameter();
            if ($parameter !== null && in_array($parameter, ConversionRequest::parameterNames(), true)) {
                $message .= sprintf(' Check the parameter "%s".', $parameter);
            }
        }

        return $message;
    }

    /**
     * The headers that go with the error: Retry-After, in seconds, for
     * concurrency_limit.
     *
     * @return array<string, string>
     */
    public function headersOf(ExceptionInterface $exception): array
    {
        if ($exception instanceof ConcurrencyLimitException) {
            return ['Retry-After' => (string) $exception->getRetryAfterSeconds()];
        }

        return [];
    }

    /**
     * Whether the error is the server's (status 500 or more) rather than the
     * request's, for the log level.
     */
    public function isServerError(ExceptionInterface $exception): bool
    {
        return $this->statusOf($this->codeOf($exception)) >= 500;
    }
}
