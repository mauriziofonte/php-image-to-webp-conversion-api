<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap.
 *
 * Every error level is reported, and every notice, warning and deprecation
 * becomes an \ErrorException, so that a PHP 8.4 or 8.5 deprecation raised by
 * src/ or tests/ fails the build. PHPUnit 8.5 to 11.5 do not register their
 * own error handler when one is already installed, so this one stays active
 * while the tests run; BootstrapErrorHandlerTest proves it on every version.
 *
 * Two exceptions, both left to PHP's standard handler, which prints them on
 * STDERR without failing the run:
 * - deprecations raised inside vendor/, which are not ours to fix;
 * - errors silenced with the @ operator.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');

$projectRoot = realpath(dirname(__DIR__));
if ($projectRoot === false) {
    throw new RuntimeException('Cannot resolve the project root directory.');
}
$vendorDir = $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;

set_error_handler(
    static function (int $level, string $message, string $file = '', int $line = 0) use ($vendorDir): bool {
        if ((error_reporting() & $level) === 0) {
            return false;
        }

        $isDeprecation = $level === E_DEPRECATED || $level === E_USER_DEPRECATED;
        if ($isDeprecation && strpos($file, $vendorDir) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $level, $file, $line);
    }
);

require $projectRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
