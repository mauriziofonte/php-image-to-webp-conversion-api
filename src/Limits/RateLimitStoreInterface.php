<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Limits;

/**
 * Keeps one integer per key for RateLimiter and updates it atomically:
 * concurrent requests of one key, in any PHP process that shares the
 * store, never read the same value and both write after it.
 */
interface RateLimitStoreInterface
{
    /**
     * Calls $decide with the value stored for $key (null when there is
     * none) and stores what it returns, unless it returns null, atomically
     * for the key. The keys are few (one per configured API key), so values
     * are kept without expiry.
     *
     * @param string                   $key    1 to 64 characters among [A-Za-z0-9_-]
     * @param callable(int|null): ?int $decide may be called more than once when a concurrent update of the key
     *                                         wins: only the value of the last call is stored, so it must not
     *                                         have other effects than its result
     *
     * @return bool false when the store could not do the update (contended past its retries)
     */
    public function update(string $key, callable $decide): bool;
}
