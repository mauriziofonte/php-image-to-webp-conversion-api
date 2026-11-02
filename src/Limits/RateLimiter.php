<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Limits;

use Mfonte\ImageConversionApi\Exception\RuntimeException;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;

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
 *
 * A request may cost several tokens (POST /v1 costs one per file): it is
 * allowed when the arrival time pushed by all of them but one is within
 * the tolerance, and then pushes it by all of them. The cost is capped at
 * the burst, so that a request is never refused for ever.
 *
 * Clocks: the arrival time is never pulled back towards now. When the
 * wall clock steps back by D, a key that spent its burst is refused for
 * about D more seconds: the error is on the safe side, never more
 * requests than the limit. Clamping it instead would let a client whose
 * requests meet two clocks that disagree pass every request. For the
 * same reason a store shared by several hosts (APCu is per host, but
 * LOCK_DIR could be on a shared file system) needs their clocks
 * synchronised (NTP); with clocks apart it stays safe but refuses more.
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
     * @var int the most tokens one request may cost
     */
    private $burst;

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
        $this->burst = max(1, $burst);
        $this->tolerance = $this->interval * ($this->burst - 1);
        $this->clock = $clock ?? static function (): int {
            return (int) round(microtime(true) * 1000000);
        };
    }

    /**
     * Takes $cost tokens from the bucket of $client.
     *
     * @param string $client stable identifier of the client, 1 to 64 characters among [A-Za-z0-9_-]
     * @param int    $cost   tokens the request costs, from 1 to the burst (smaller and larger values are
     *                       brought into that range)
     *
     * @throws RuntimeException when the store cannot read and write the bucket atomically, or is not usable (a
     *                          LOCK_DIR that is not private, with the library's exception as the previous one):
     *                          the caller refuses the request (fail closed) as a server error, not as a client
     *                          over its limit
     *
     * @return int 0 when the request is allowed; otherwise the seconds to wait, at least 1
     */
    public function consume(string $client, int $cost = 1): int
    {
        $now = ($this->clock)();
        $interval = $this->interval;
        $tolerance = $this->tolerance;
        $extra = $interval * (min(max(1, $cost), $this->burst) - 1);
        $wait = 0;
        $decide = static function (?int $arrival) use ($now, $interval, $tolerance, $extra, &$wait): ?int {
            $arrival = $arrival === null ? $now : max($arrival, $now);
            if ($arrival + $extra - $now > $tolerance) {
                $wait = $arrival + $extra - $now - $tolerance;

                return null;
            }
            $wait = 0;

            return $arrival + $extra + $interval;
        };

        try {
            $updated = $this->store->update($client, $decide);
        } catch (LibraryException $exception) {
            throw new RuntimeException('The rate limit store is not usable: ' . $exception->getMessage(), 0, $exception);
        }
        if (!$updated) {
            throw new RuntimeException('The rate limit store cannot update the bucket of the client.');
        }

        return $wait === 0 ? 0 : max(1, (int) ceil($wait / 1000000));
    }
}
