<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Observability;

/**
 * Keeps each counter in an APCu entry, increased with apcu_inc(), which is
 * atomic: shared by the workers of one PHP-FPM master without touching the
 * disk. Each PHP-FPM master has its own APCu memory, so several pools or
 * hosts are scraped one by one; a restart empties the counters.
 */
final class ApcuMetricsStore implements MetricsStoreInterface
{
    /**
     * Prefix of the APCu entries.
     */
    public const PREFIX = 'image-conversion-api:metric:';

    /**
     * Whether APCu is loaded and enabled for this SAPI (apc.enable_cli for
     * the CLI).
     */
    public static function isAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function add(array $increments): bool
    {
        $stored = true;
        foreach ($increments as $name => $amount) {
            $key = self::PREFIX . $name;
            apcu_add($key, 0);
            $success = false;
            apcu_inc($key, $amount, $success);
            $stored = $stored && $success;
        }

        return $stored;
    }

    public function read(): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }
        $counters = [];
        $entries = new \APCUIterator('/\A' . preg_quote(self::PREFIX, '/') . '/', APC_ITER_KEY | APC_ITER_VALUE);
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['key'] ?? null) && is_int($entry['value'] ?? null)) {
                $counters[(string) substr($entry['key'], strlen(self::PREFIX))] = $entry['value'];
            }
        }

        return $counters;
    }
}
