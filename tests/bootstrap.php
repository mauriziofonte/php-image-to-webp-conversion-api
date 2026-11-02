<?php

declare(strict_types=1);

use Mfonte\ImageConversionApi\Tests\StrictErrorHandler;

/*
 * PHPUnit bootstrap. The strict error handler is installed first, before the
 * Composer autoloader, so that it also covers the code that runs while
 * Composer loads the "files" autoload entries: every notice, warning and
 * deprecation becomes an exception, except deprecations raised inside
 * vendor/, which are counted and reported on STDERR at shutdown.
 */
error_reporting(E_ALL);

$projectRoot = dirname(__DIR__);

if (!is_file($projectRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, 'Dependencies are missing. Run: composer install' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/StrictErrorHandler.php';

StrictErrorHandler::install($projectRoot . DIRECTORY_SEPARATOR . 'vendor');

require_once $projectRoot . '/vendor/autoload.php';
