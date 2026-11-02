<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Observability;

use Mfonte\ImageConversionApi\Observability\MetricsStoreInterface;

/**
 * Keeps the counters in memory, and can play a store that fails.
 */
final class MemoryMetricsStore implements MetricsStoreInterface
{
    /**
     * @var array<string, int>
     */
    private $counters = [];

    /**
     * @var bool
     */
    private $failing = false;

    /**
     * @var int
     */
    private $updates = 0;

    public function fail(): void
    {
        $this->failing = true;
    }

    /**
     * How many times add() was called.
     */
    public function getUpdates(): int
    {
        return $this->updates;
    }

    public function add(array $increments): bool
    {
        ++$this->updates;
        if ($this->failing) {
            return false;
        }
        foreach ($increments as $name => $amount) {
            $this->counters[$name] = ($this->counters[$name] ?? 0) + $amount;
        }

        return true;
    }

    public function read(): ?array
    {
        return $this->failing ? null : $this->counters;
    }
}
