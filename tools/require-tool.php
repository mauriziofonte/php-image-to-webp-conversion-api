<?php

declare(strict_types=1);

/*
 * Guard used by the Composer scripts before they call a development tool.
 *
 * Tools that cannot be installed on PHP 7.2 live in tools/<name>/ with their own composer.json. This script exits with
 * status 1 and prints the install command when tools/<name>/vendor/ is missing, so that `composer analyse` and the
 * other scripts fail with a hint instead of "Could not open input file".
 *
 * Usage: php tools/require-tool.php <name>
 */

$name = isset($argv[1]) ? (string) $argv[1] : '';

if (preg_match('/\A[a-z0-9][a-z0-9-]*\z/', $name) !== 1 || !is_file(__DIR__ . '/' . $name . '/composer.json')) {
    fwrite(STDERR, 'Usage: php tools/require-tool.php <name>, where tools/<name>/ holds a composer.json.' . PHP_EOL);
    exit(2);
}

if (!is_file(__DIR__ . '/' . $name . '/vendor/autoload.php')) {
    fwrite(STDERR, sprintf('%1$s is not installed. Run: composer install --working-dir=tools/%1$s', $name) . PHP_EOL);
    exit(1);
}
