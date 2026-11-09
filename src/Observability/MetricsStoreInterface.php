<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Observability;

/**
 * Keeps the counters of Metrics, shared by every PHP process that serves
 * the API, so that a scrape of /metrics sees the requests of all of them.
 * The counters only grow, until the store is emptied (a PHP-FPM restart
 * empties APCu), which Prometheus reads as a counter reset.
 */
interface MetricsStoreInterface
{
    /**
     * Adds each increment to its counter, created at 0 when missing.
     *
     * @param array<string, int> $increments counter name => amount to add, at least 0
     *
     * @return bool false when the store could not be updated (the increments are lost)
     */
    public function add(array $increments): bool;

    /**
     * Every counter.
     *
     * @return array<string, int>|null counter name => value; null when the store cannot be read
     */
    public function read(): ?array;
}
