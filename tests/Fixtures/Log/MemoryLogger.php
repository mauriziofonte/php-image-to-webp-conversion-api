<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Log;

use Mfonte\ImageConversionApi\Http\Kernel;
use Psr\Log\AbstractLogger;

/**
 * Keeps every log entry in memory, for assertions. getRecords() leaves out
 * the access entry the kernel writes at the end of every request
 * (Kernel::ACCESS_LOG_MESSAGE) unless asked for it.
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
     * @param bool $withAccessEntries whether to include the kernel's access entries
     *
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function getRecords(bool $withAccessEntries = false): array
    {
        if ($withAccessEntries) {
            return $this->records;
        }

        return array_values(array_filter($this->records, static function (array $record): bool {
            return $record['message'] !== Kernel::ACCESS_LOG_MESSAGE;
        }));
    }
}
