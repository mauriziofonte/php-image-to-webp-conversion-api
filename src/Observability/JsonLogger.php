<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Observability;

use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as ConversionException;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * A PSR-3 logger that writes one JSON object per line, in the log schema
 * shared with the proxy:
 *
 *     {"timestamp":"2026-10-30T10:00:00.123456+00:00","level":"error",
 *      "channel":"image-conversion-api","request_id":"4bf92f3577b34da6",
 *      "message":"...","context":{...}}
 *
 * The fields always come in that order. "request_id" is the X-Request-Id of
 * the request being served (the proxy's, when it sent one; see
 * Http\RequestId), null outside a request. The context is always an
 * object, {} when empty.
 *
 * - Placeholders such as "{route}" in the message are replaced with the
 *   context values, as PSR-3 describes (booleans as true and false, dates
 *   in RFC 3339).
 * - Error codes: an API error code (the codes of Http\ResponseFactory) is
 *   logged under ERROR_CODE ("error_code"), an error code of the
 *   conversion library under CONVERSION_ERROR_CODE
 *   ("conversion_error_code"). When the PSR-3 "exception" of the context
 *   carries one and the context does not name it yet, it is copied to the
 *   context under its key, so that log queries need not dig into the
 *   exception object.
 * - An exception is reduced to its class, message, code, file and line,
 *   its error code under the same two keys, and its previous exceptions.
 * - A float that is not finite is written as the string "NAN", "INF" or
 *   "-INF" (JSON has no such numbers), without casting NAN, which PHP 8.5
 *   reports with a warning. A date becomes RFC 3339, a JsonSerializable
 *   its serialisation, another object its string or "[object Class]", a
 *   resource "[resource]", a value nested deeper than 8 levels "[...]".
 * - Entries less severe than the minimum level are dropped; an unknown
 *   level is refused, as PSR-3 asks.
 *
 * Writing never throws and never prints to the response: a log that
 * cannot be written must not break the request. The destination is a
 * writer callable; writerFor() builds the three that LOG_FILE selects.
 *
 * The signature of log() is compatible with psr/log 1, 2 and 3: the
 * parameters are untyped, which widens those of the interface, and the
 * return type is void.
 */
final class JsonLogger extends AbstractLogger
{
    public const CHANNEL = 'image-conversion-api';

    /**
     * Context key of an error code of the API (Http\ResponseFactory::ERROR_*).
     */
    public const ERROR_CODE = 'error_code';

    /**
     * Context key of an error code of the conversion library (ErrorCode),
     * the key the proxy uses for the same codes.
     */
    public const CONVERSION_ERROR_CODE = 'conversion_error_code';

    /**
     * LOG_FILE value that selects the standard error stream of the process.
     */
    public const DESTINATION_STDERR = 'stderr';

    /**
     * Severity of each level, most severe first.
     */
    private const SEVERITIES = [
        LogLevel::EMERGENCY => 0,
        LogLevel::ALERT => 1,
        LogLevel::CRITICAL => 2,
        LogLevel::ERROR => 3,
        LogLevel::WARNING => 4,
        LogLevel::NOTICE => 5,
        LogLevel::INFO => 6,
        LogLevel::DEBUG => 7,
    ];

    /**
     * Nesting depth after which context values are summarised.
     */
    private const MAX_DEPTH = 8;

    /**
     * @var callable(string): mixed
     */
    private $writer;

    /**
     * @var int
     */
    private $minimumSeverity;

    /**
     * @var string|null
     */
    private $requestId;

    /**
     * @var string
     */
    private $channel;

    /**
     * @param (callable(string): mixed)|null $writer       receives each encoded entry, without a line feed;
     *                                                     error_log() when null
     * @param string                         $minimumLevel one of the LogLevel constants: less severe entries are
     *                                                     dropped
     * @param string|null                    $requestId    the "request_id" of every entry
     *
     * @throws InvalidArgumentException when $minimumLevel is not a PSR-3 level
     */
    public function __construct(
        ?callable $writer = null,
        string $minimumLevel = LogLevel::DEBUG,
        ?string $requestId = null,
        string $channel = self::CHANNEL
    ) {
        $this->writer = $writer ?? self::writerFor(null);
        $this->minimumSeverity = self::severityOf($minimumLevel);
        $this->requestId = $requestId;
        $this->channel = $channel;
    }

    /**
     * The writer of a LOG_FILE value:
     *
     * - null: error_log(), whose destination is the error_log ini setting
     *   (the web server's or PHP-FPM's log). PHP prefixes each line with a
     *   date when error_log names a file, and PHP-FPM decorates the lines
     *   of its workers unless decorate_workers_output is off;
     * - DESTINATION_STDERR: the standard error stream of the process, the
     *   container log behind PHP-FPM with catch_workers_output on and
     *   decorate_workers_output off;
     * - an absolute path: the file, appended under an exclusive lock. Its
     *   directory must exist (check-env reports it otherwise).
     *
     * @return callable(string): bool
     */
    public static function writerFor(?string $destination): callable
    {
        if ($destination === null) {
            return static function (string $line): bool {
                return error_log($line);
            };
        }
        if ($destination === self::DESTINATION_STDERR) {
            return static function (string $line): bool {
                $stream = @fopen('php://stderr', 'wb');
                if ($stream === false) {
                    return false;
                }
                $written = @fwrite($stream, $line . "\n");
                @fclose($stream);

                return $written !== false;
            };
        }

        return static function (string $line) use ($destination): bool {
            return @file_put_contents($destination, $line . "\n", FILE_APPEND | LOCK_EX) !== false;
        };
    }

    /**
     * A copy that writes $requestId as the "request_id" of its entries.
     */
    public function withRequestId(?string $requestId): self
    {
        $clone = clone $this;
        $clone->requestId = $requestId;

        return $clone;
    }

    /**
     * @param mixed        $level   one of the Psr\Log\LogLevel constants
     * @param mixed        $message a string or an object with __toString() (Stringable on PHP 8)
     * @param array<mixed> $context
     *
     * @throws InvalidArgumentException when $level is not a PSR-3 level
     */
    public function log($level, $message, array $context = []): void
    {
        if (!is_string($level) || !isset(self::SEVERITIES[$level])) {
            throw new InvalidArgumentException('Unknown log level: ' . (is_scalar($level) ? self::scalarString($level) : gettype($level)));
        }
        if (self::SEVERITIES[$level] > $this->minimumSeverity) {
            return;
        }
        $context = self::withErrorCodes($context);

        $line = json_encode(
            [
                'timestamp' => (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.uP'),
                'level' => $level,
                'channel' => $this->channel,
                'request_id' => $this->requestId,
                'message' => self::interpolate(self::stringOf($message), $context),
                'context' => (object) self::normaliseArray($context, 0),
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        if ($line === false) {
            return;
        }

        try {
            ($this->writer)($line);
        } catch (\Throwable $exception) {
            // A log that cannot be written must not break the response.
        }
    }

    /**
     * Whether $level is one of the PSR-3 levels.
     */
    public static function isLevel(string $level): bool
    {
        return isset(self::SEVERITIES[$level]);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function severityOf(string $level): int
    {
        if (!isset(self::SEVERITIES[$level])) {
            throw new InvalidArgumentException('Unknown log level: ' . $level);
        }

        return self::SEVERITIES[$level];
    }

    /**
     * Copies the error code of the context's exception to the context,
     * under its key, unless the context names it already.
     *
     * @param array<mixed> $context
     *
     * @return array<mixed>
     */
    private static function withErrorCodes(array $context): array
    {
        $exception = $context['exception'] ?? null;
        if (!$exception instanceof \Throwable) {
            return $context;
        }
        $code = self::errorCodeOf($exception);
        if ($code !== null && !array_key_exists($code[0], $context)) {
            $context[$code[0]] = $code[1];
        }

        return $context;
    }

    /**
     * The key and the value of the error code an exception carries, or null.
     *
     * @return array{0: string, 1: string|int}|null
     */
    private static function errorCodeOf(\Throwable $exception): ?array
    {
        if (!method_exists($exception, 'getErrorCode')) {
            return null;
        }
        $code = $exception->getErrorCode();
        if (!is_string($code) && !is_int($code)) {
            return null;
        }

        return [$exception instanceof ConversionException ? self::CONVERSION_ERROR_CODE : self::ERROR_CODE, $code];
    }

    /**
     * @param mixed $message
     */
    private static function stringOf($message): string
    {
        if (is_string($message)) {
            return $message;
        }
        if (is_scalar($message)) {
            return self::scalarString($message);
        }
        if (is_object($message) && method_exists($message, '__toString')) {
            return (string) $message;
        }

        return '';
    }

    /**
     * A scalar as a string: booleans as true and false, floats that are not
     * finite as NAN, INF and -INF without a cast (PHP 8.5 warns when NAN
     * is cast).
     *
     * @param bool|float|int|string $value
     */
    private static function scalarString($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value) && !is_finite($value)) {
            return self::nonFinite($value);
        }

        return (string) $value;
    }

    /**
     * The name of a float that is not finite.
     */
    private static function nonFinite(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }

        return $value > 0 ? 'INF' : '-INF';
    }

    /**
     * Replaces each "{key}" with the context value of key, when it can be
     * written as a string.
     *
     * @param array<mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if (strpos($message, '{') === false) {
            return $message;
        }
        $replacements = [];
        foreach ($context as $key => $value) {
            if ($value === null) {
                $replacements['{' . $key . '}'] = '';
            } elseif (is_scalar($value)) {
                $replacements['{' . $key . '}'] = self::scalarString($value);
            } elseif (is_object($value) && method_exists($value, '__toString')) {
                $replacements['{' . $key . '}'] = (string) $value;
            } elseif ($value instanceof \DateTimeInterface) {
                $replacements['{' . $key . '}'] = $value->format(\DateTimeInterface::RFC3339);
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function normaliseArray(array $values, int $depth): array
    {
        $normalised = [];
        foreach ($values as $key => $value) {
            $normalised[$key] = self::normalise($value, $depth + 1);
        }

        return $normalised;
    }

    /**
     * A JSON-friendly copy of a context value.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private static function normalise($value, int $depth)
    {
        if ($value === null || is_scalar($value)) {
            return is_float($value) && !is_finite($value) ? self::nonFinite($value) : $value;
        }
        if ($depth > self::MAX_DEPTH) {
            return '[...]';
        }
        if (is_array($value)) {
            return self::normaliseArray($value, $depth);
        }
        if ($value instanceof \Throwable) {
            $exception = [
                'class' => get_class($value),
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile() . ':' . $value->getLine(),
            ];
            $code = self::errorCodeOf($value);
            if ($code !== null) {
                $exception[$code[0]] = $code[1];
            }
            $previous = $value->getPrevious();
            if ($previous !== null) {
                $exception['previous'] = self::normalise($previous, $depth + 1);
            }

            return $exception;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::RFC3339);
        }
        if ($value instanceof \JsonSerializable) {
            return self::normalise($value->jsonSerialize(), $depth + 1);
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : '[object ' . get_class($value) . ']';
        }

        return '[' . gettype($value) . ']';
    }
}
