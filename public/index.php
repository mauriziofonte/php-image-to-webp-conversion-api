<?php

declare(strict_types=1);

/*
 * Front controller: the web server routes every request here. It builds the
 * object graph with KernelFactory (no container) and sends the response of
 * the kernel. Temporary files live under TEMP_DIR, outside this directory.
 */

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Exception\InvalidConfigurationException;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Http\RequestId;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Observability\JsonLogger;
use Symfony\Component\HttpFoundation\Request;

// PHP errors must never reach a response body.
ini_set('display_errors', '0');

require dirname(__DIR__) . '/vendor/autoload.php';

$request = Request::createFromGlobals();
$logger = new JsonLogger(null, 'debug', RequestId::of($request));
$documentRoot = $request->server->get('DOCUMENT_ROOT');

try {
    $config = Config::fromEnvironment([__DIR__, is_string($documentRoot) ? $documentRoot : '']);
} catch (InvalidConfigurationException $exception) {
    $logger->critical('Invalid configuration', ['errors' => $exception->getErrors()]);
    (new ResponseFactory())->invalidConfiguration()->send();

    return;
}

KernelFactory::create($config, $logger)->handle($request)->send();
