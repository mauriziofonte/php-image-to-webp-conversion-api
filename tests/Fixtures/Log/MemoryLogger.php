<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Log;

use Psr\Log\AbstractLogger;

/**
 * Keeps every log entry in memory, for assertions.
 */
final class MemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<mixed>}>
     */
    private $records = [];

    /**
     * @param mixed        $level
     * @param mixed        $message a string or an object with __toString() (Stringable on PHP 8)
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : '',
            'message' => is_string($message) || (is_object($message) && method_exists($message, '__toString')) ? (string) $message : '',
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }
}
