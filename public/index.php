<?php

declare(strict_types=1);

/*
 * Front controller: the web server routes every request here. FrontController
 * reads the configuration (environment first, then the optional .env file of
 * the installation), builds the object graph with KernelFactory (no
 * container) and sends the response of the kernel, and nothing else: PHP
 * errors are logged, never printed. Temporary files live under TEMP_DIR,
 * outside this directory.
 */

use Mfonte\ImageConversionApi\Config\ConfigLoader;
use Mfonte\ImageConversionApi\Http\FrontController;
use Mfonte\ImageConversionApi\Http\InstallationBase;
use Symfony\Component\HttpFoundation\Request;

// Before the autoloader runs: PHP errors must never reach a response body.
ini_set('display_errors', '0');

require dirname(__DIR__) . '/vendor/autoload.php';

$request = Request::createFromGlobals();
// Installed in a subdirectory served by Apache: see the root .htaccess.
InstallationBase::apply($request);
$documentRoot = $request->server->get('DOCUMENT_ROOT');

(new FrontController(
    new ConfigLoader(dirname(__DIR__) . '/.env'),
    [__DIR__, is_string($documentRoot) ? $documentRoot : '']
))->run($request);
