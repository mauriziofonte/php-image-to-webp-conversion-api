<?php

declare(strict_types=1);

/*
 * Guard used by the Composer scripts before they call a development tool.
 *
 * Tools that cannot be installed on PHP 7.2 live in tools/<name>/ with their
 * own composer.json. This script exits with status 1 and prints the install
 * command when tools/<name>/vendor/ is missing, so `composer analyse` and the
 * other scripts fail with a hint instead of "Could not open input file".
 *
 * Usage: php tools/require-tool.php <name>
 */

$name = isset($argv[1]) ? (string) $argv[1] : '';

if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) !== 1 || !is_file(__DIR__ . '/' . $name . '/composer.json')) {
    fwrite(STDERR, sprintf('Unknown development tool "%s": expected a directory tools/<name>/ with a composer.json.%s', $name, PHP_EOL));
    exit(2);
}

if (!is_file(__DIR__ . '/' . $name . '/vendor/autoload.php')) {
    fwrite(STDERR, sprintf('%1$s is not installed. Run: composer install -d tools/%1$s%2$s', $name, PHP_EOL));
    exit(1);
}
