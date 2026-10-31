<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Observability;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * A PSR-3 logger that writes one JSON object per entry to PHP's error log
 * (error_log(), so the destination is the error_log ini setting: the web
 * server's or PHP-FPM's log by default), with the schema of the proxy's
 * JsonLogger:
 *
 *     {"timestamp":"2026-10-30T10:00:00.123456+00:00","level":"error",
 *      "channel":"image-conversion-api","message":"...","context":{...}}
 *
 * The fields come in that order; the context is always an object, {} when
 * empty. Placeholders such as "{route}" in the message are replaced with the
 * context values, as PSR-3 describes (booleans as true and false, dates in
 * RFC 3339). Context values are made JSON-friendly as JsonLogger makes them:
 * an exception is reduced to its class, message, code, file and line, with
 * its previous exceptions; a date to RFC 3339; an object to its string or
 * its class; values nested deeper than 8 levels to "[...]". An unknown level
 * is refused, as PSR-3 asks; writing itself never throws and never prints to
 * the response.
 *
 * The interim logger of the front controller: API-7 replaces it with the
 * JSON lines logger shared with the proxy (request ID, file destination,
 * minimum level).
 *
 * The signature of log() is compatible with psr/log 1, 2 and 3: the
 * parameters are untyped, which widens those of the interface, and the
 * return type is void.
 */
final class ErrorLogLogger extends AbstractLogger
{
    public const CHANNEL = 'image-conversion-api';

    private const LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
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
     * @param (callable(string): mixed)|null $writer receives each encoded entry; error_log() by default
     */
    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer ?? static function (string $line): bool {
            return error_log($line);
        };
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
        if (!is_string($level) || !in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('Unknown log level: ' . (is_scalar($level) ? (string) $level : gettype($level)));
        }

        $line = json_encode(
            [
                'timestamp' => (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.uP'),
                'level' => $level,
                'channel' => self::CHANNEL,
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
     * @param mixed $message
     */
    private static function stringOf($message): string
    {
        if (is_string($message)) {
            return $message;
        }
        if (is_scalar($message) || (is_object($message) && method_exists($message, '__toString'))) {
            return (string) $message;
        }

        return '';
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
            if (is_bool($value)) {
                $replacements['{' . $key . '}'] = $value ? 'true' : 'false';
            } elseif ($value === null || is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $replacements['{' . $key . '}'] = self::stringOf($value);
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
            return is_float($value) && !is_finite($value) ? (string) $value : $value;
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
            return method_exists($value, '__toString') ? self::stringOf($value) : '[object ' . get_class($value) . ']';
        }

        return '[' . gettype($value) . ']';
    }
}
