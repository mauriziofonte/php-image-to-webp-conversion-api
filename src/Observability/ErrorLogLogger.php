<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Observability;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that writes one JSON object per entry to PHP's error log
 * (error_log(), so the destination is the error_log ini setting: the web
 * server's or PHP-FPM's log by default):
 *
 *     {"level":"error","channel":"image-conversion-api","message":"...","context":{...}}
 *
 * Placeholders such as "{route}" in the message are replaced with the
 * context values, as PSR-3 describes. An exception in the context is
 * reduced to its class, message, code, file and line, with its previous
 * exceptions. Writing never throws and never prints to the response.
 *
 * The interim logger of the front controller: API-7 replaces it with the
 * JSON lines logger shared with the proxy (request ID, timestamps, levels).
 *
 * The signature of log() is compatible with psr/log 1, 2 and 3: the
 * parameters are untyped, which widens those of the interface, and the
 * return type is void.
 */
final class ErrorLogLogger extends AbstractLogger
{
    public const CHANNEL = 'image-conversion-api';

    /**
     * Nesting depth after which context values are summarised.
     */
    private const MAX_DEPTH = 4;

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
     */
    public function log($level, $message, array $context = []): void
    {
        $message = self::stringify($message);
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $replacements['{' . $key . '}'] = self::stringify($value);
            }
        }

        $entry = [
            'level' => is_string($level) ? $level : 'unknown',
            'channel' => self::CHANNEL,
            'message' => strtr($message, $replacements),
            'context' => self::normalise($context, 0),
        ];
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        try {
            ($this->writer)(is_string($line) ? $line : '{"level":"error","message":"log entry not encodable"}');
        } catch (\Throwable $exception) {
            // A log that cannot be written must not break the response.
        }
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private static function normalise($value, int $depth)
    {
        if ($value instanceof \Throwable) {
            return self::describeException($value, $depth);
        }
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return sprintf('[array of %d]', count($value));
            }
            $normalised = [];
            foreach ($value as $key => $item) {
                $normalised[$key] = self::normalise($item, $depth + 1);
            }

            return $normalised;
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? self::stringify($value) : '[object ' . get_class($value) . ']';
        }
        if (is_resource($value)) {
            return '[resource]';
        }

        return $value;
    }

    /**
     * @param mixed $value a scalar, null, or an object with __toString()
     */
    private static function stringify($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeException(\Throwable $exception, int $depth): array
    {
        $description = [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile() . ':' . $exception->getLine(),
        ];
        $previous = $exception->getPrevious();
        if ($previous !== null) {
            $description['previous'] = $depth >= self::MAX_DEPTH
                ? '[' . get_class($previous) . ']'
                : self::describeException($previous, $depth + 1);
        }

        return $description;
    }
}
