<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Limits;

/**
 * Keeps each value in APCu, updated with compare-and-swap: shared by the
 * workers of one PHP-FPM master (or one Apache mod_php server), without
 * touching the disk. Each PHP-FPM master has its own APCu memory, so
 * several pools or hosts each count on their own; use FileRateLimitStore
 * when pools must share the buckets.
 */
final class ApcuRateLimitStore implements RateLimitStoreInterface
{
    /**
     * Prefix of the APCu entries.
     */
    public const PREFIX = 'image-conversion-api:rate-limit:';

    /**
     * Attempts at a compare-and-swap before the update is given up.
     */
    private const ATTEMPTS = 64;

    /**
     * Whether APCu is loaded and enabled for this SAPI (apc.enable_cli for
     * the CLI).
     */
    public static function isAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function update(string $key, callable $decide): bool
    {
        $name = self::PREFIX . $key;
        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            $found = false;
            $stored = apcu_fetch($name, $found);
            $current = $found && is_int($stored) ? $stored : null;
            $next = $decide($current);
            if ($next === null) {
                return true;
            }
            if ($current !== null) {
                $swapped = apcu_cas($name, $current, $next);
            } elseif ($found) {
                // Not an integer: written by something else; replaced.
                $swapped = apcu_store($name, $next);
            } else {
                $swapped = apcu_add($name, $next);
            }
            if ($swapped) {
                return true;
            }
        }

        return false;
    }
}
