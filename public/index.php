<?php

declare(strict_types=1);

/*
 * Front controller: the web server routes every request here. It reads the
 * configuration (environment first, then the optional .env file of the
 * installation), builds the object graph with KernelFactory (no container)
 * and sends the response of the kernel. Temporary files live under
 * TEMP_DIR, outside this directory.
 */

use Mfonte\ImageConversionApi\Config\ConfigLoader;
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
$requestId = RequestId::of($request);
$documentRoot = $request->server->get('DOCUMENT_ROOT');

try {
    $config = (new ConfigLoader(dirname(__DIR__) . '/.env'))->load([__DIR__, is_string($documentRoot) ? $documentRoot : '']);
} catch (InvalidConfigurationException $exception) {
    (new JsonLogger(null, 'debug', $requestId))->critical('Invalid configuration', ['errors' => $exception->getErrors()]);
    $response = (new ResponseFactory())->invalidConfiguration();
    $response->headers->set(RequestId::HEADER, $requestId);
    $response->send();

    return;
}

$logger = new JsonLogger(JsonLogger::writerFor($config->getLogFile()), $config->getLogLevel(), $requestId);
KernelFactory::create($config, $logger)->handle($request)->send();
