<?php

declare(strict_types=1);

/*
 * Increments one value of a FileRateLimitStore many times, for the
 * atomicity test of FileRateLimitStoreTest, which runs several of these
 * processes at once.
 *
 * Usage: php increment-worker.php <directory> <key> <count>
 */

use Mfonte\ImageConversionApi\Limits\FileRateLimitStore;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$store = new FileRateLimitStore((string) $argv[1]);
$failures = 0;
for ($i = 0; $i < (int) $argv[3]; ++$i) {
    $updated = $store->update((string) $argv[2], static function (?int $value): int {
        return ($value ?? 0) + 1;
    });
    $failures += $updated ? 0 : 1;
}

echo $failures;
