<?php

declare(strict_types=1);

/*
 * Drives the kernel with one request whose handler, while the request
 * directory holds its input, its output and a nested file, ends in a fatal
 * error (a class declared twice), after which finally blocks and
 * destructors do not run. TemporaryFilesTest::testAFatalErrorLeavesNoFile
 * runs it in a separate PHP process and checks that the directory is
 * removed anyway.
 *
 * The fatal error is a class declared twice, not the likelier memory
 * exhaustion: the builds of PHP 7.2 to 8.1 that setup-php installs on
 * GitHub's Ubuntu runners end the process on memory exhaustion without
 * running shutdown functions (see RequestDirectories). Both errors skip
 * finally blocks and destructors in the same way.
 *
 * Its own shutdown function, registered before any other and therefore run
 * before the one of RequestDirectories, writes to the report file the
 * message of the last error, then the files that exist below the temporary
 * directory at that point, one per line.
 *
 * Usage: php fatal-probe.php <temporary directory> <report file>
 */

use Mfonte\ImageConversionApi\Http\Route;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\FakeConverter;
use Mfonte\ImageConversionApi\Tests\Fixtures\Http\ConvertingHandler;
use Mfonte\ImageConversionApi\Tests\Fixtures\Http\TestKernel;
use Symfony\Component\HttpFoundation\Request;

error_reporting(E_ALL);

$arguments = array_values(array_filter(isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [], 'is_string'));
if (count($arguments) < 3) {
    fwrite(STDERR, "Usage: php fatal-probe.php <temporary directory> <report>\n");
    exit(2);
}
$temporaryDirectory = $arguments[1];
$report = $arguments[2];

register_shutdown_function(static function () use ($temporaryDirectory, $report): void {
    $error = error_get_last();
    file_put_contents($report, implode("\n", array_merge(
        [$error === null ? '' : $error['message']],
        ConvertingHandler::listFiles($temporaryDirectory)
    )));
});

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$kernel = TestKernel::create(
    [new Route('probe', '/probe', ['POST'], new ConvertingHandler(new FakeConverter(), ConvertingHandler::FATAL), true)],
    $temporaryDirectory
);
$kernel->handle(Request::create('https://api.example.test/probe', 'POST', [], [], [], [], 'PNG'));

echo "no fatal error\n";
