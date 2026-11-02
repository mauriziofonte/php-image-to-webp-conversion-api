<?php

declare(strict_types=1);

/*
 * Router of the built-in web server for LoadTest: runs the real front
 * controller and, at the end of each request, appends the request's peak
 * memory (memory_get_peak_usage(), in bytes) as one line to the file named
 * by the MEMORY_LOG environment variable.
 */

register_shutdown_function(static function (): void {
    $log = getenv('MEMORY_LOG');
    if (is_string($log) && $log !== '') {
        file_put_contents($log, memory_get_peak_usage() . "\n", FILE_APPEND | LOCK_EX);
    }
});

require dirname(__DIR__, 3) . '/public/index.php';
