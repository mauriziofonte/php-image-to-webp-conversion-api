<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Limits;

use Mfonte\ImageConversionApi\Exception\RuntimeException;

/**
 * A token bucket per API key: RATE_LIMIT requests per minute on average,
 * up to RATE_LIMIT_BURST at once after a pause.
 *
 * It is computed as the generic cell rate algorithm, which behaves as a
 * token bucket but keeps one integer per key, the theoretical arrival time
 * of the next request in microseconds, so that a store can update it with
 * one compare-and-swap (APCu) or one locked write (files). A request is
 * allowed when that time is at most (burst - 1) intervals ahead of now,
 * and then pushes it one interval further; a refused request changes
 * nothing, so a client that keeps hammering does not lock itself out
 * beyond the refill. The wait it is told (Retry-After) is the time until
 * the next token, rounded up to whole seconds.
 */
final class RateLimiter
{
    /**
     * @var RateLimitStoreInterface
     */
    private $store;

    /**
     * @var int microseconds between two tokens
     */
    private $interval;

    /**
     * @var int microseconds the arrival time may run ahead of now
     */
    private $tolerance;

    /**
     * @var callable(): int
     */
    private $clock;

    /**
     * @param int                    $perMinute requests per minute, at least 1
     * @param int                    $burst     requests at once, at least 1
     * @param (callable(): int)|null $clock     the current time in microseconds; microtime() when null
     */
    public function __construct(RateLimitStoreInterface $store, int $perMinute, int $burst, ?callable $clock = null)
    {
        $this->store = $store;
        $this->interval = max(1, intdiv(60000000, max(1, $perMinute)));
        $this->tolerance = $this->interval * (max(1, $burst) - 1);
        $this->clock = $clock ?? static function (): int {
            return (int) round(microtime(true) * 1000000);
        };
    }

    /**
     * Takes a token from the bucket of $client.
     *
     * @param string $client stable identifier of the client, 1 to 64 characters among [A-Za-z0-9_-]
     *
     * @throws RuntimeException when the store cannot read and write the bucket atomically: the caller refuses the
     *                          request (fail closed) as a server error, not as a client over its limit
     *
     * @return int 0 when the request is allowed; otherwise the seconds to wait, at least 1
     */
    public function consume(string $client): int
    {
        $now = ($this->clock)();
        $interval = $this->interval;
        $tolerance = $this->tolerance;
        $wait = 0;
        $updated = $this->store->update($client, static function (?int $arrival) use ($now, $interval, $tolerance, &$wait): ?int {
            $arrival = $arrival === null ? $now : max($arrival, $now);
            if ($arrival - $now > $tolerance) {
                $wait = $arrival - $now - $tolerance;

                return null;
            }
            $wait = 0;

            return $arrival + $interval;
        });
        if (!$updated) {
            throw new RuntimeException('The rate limit store cannot update the bucket of the client.');
        }

        return $wait === 0 ? 0 : max(1, (int) ceil($wait / 1000000));
    }
}
