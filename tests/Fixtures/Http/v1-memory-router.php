<?php

declare(strict_types=1);

/*
 * Router of the built-in web server for the /v1 case of LoadTest: the
 * kernel of KernelFactory, configured from the environment as
 * public/index.php configures it, with CopyingConverter in place of the
 * library, so that every output is as large as its input whatever the
 * host's backends. At the end of each request it appends the request's
 * peak memory (memory_get_peak_usage(), in bytes) as one line to the file
 * named by the MEMORY_LOG environment variable.
 */

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Observability\JsonLogger;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\CopyingConverter;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\ScriptedConverterFactory;
use Symfony\Component\HttpFoundation\Request;

register_shutdown_function(static function (): void {
    $log = getenv('MEMORY_LOG');
    if (is_string($log) && $log !== '') {
        file_put_contents($log, memory_get_peak_usage() . "\n", FILE_APPEND | LOCK_EX);
    }
});

ini_set('display_errors', '0');

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$config = Config::fromEnvironment([dirname(__DIR__, 3) . '/public']);
$converters = new ScriptedConverterFactory(new CopyingConverter(), $config->getMaxImageBytes());
KernelFactory::create($config, new JsonLogger(), $converters)->handle(Request::createFromGlobals())->send();
