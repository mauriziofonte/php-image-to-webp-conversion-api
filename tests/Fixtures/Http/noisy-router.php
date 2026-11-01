<?php

declare(strict_types=1);

/*
 * Router of the built-in web server for ErrorOutputTest: serves the real
 * FrontController, configured from the environment as public/index.php is,
 * with a kernel whose routes misbehave after turning display_errors back on:
 *
 * - /noisy prints stray output, raises a notice, a warning and a deprecation
 *   with trigger_error(), lets file_get_contents() warn about a missing file
 *   and silences the same warning with "@", then answers {"ok":true};
 * - /fatal prints, then ends in a fatal error (a class declared twice);
 * - /exception throws from the handler;
 * - /stream answers a body that raises a warning while it is sent.
 *
 * The server runs with display_errors=1, html_errors=1 and
 * error_reporting=E_ALL (ErrorOutputTest passes them with -d), the worst
 * php.ini for this purpose.
 */

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Config\ConfigLoader;
use Mfonte\ImageConversionApi\Http\FrontController;
use Mfonte\ImageConversionApi\Http\Kernel;
use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Http\RequestHandlerInterface;
use Mfonte\ImageConversionApi\Http\Route;
use Mfonte\ImageConversionApi\Tests\Fixtures\Http\TestKernel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$handler = new class () implements RequestHandlerInterface {
    public function handle(Request $request, RequestDirectory $directory): Response
    {
        ini_set('display_errors', '1');
        switch ($request->getPathInfo()) {
            case '/noisy':
                echo 'stray output';
                trigger_error('a probe notice', E_USER_NOTICE);
                trigger_error('a probe warning', E_USER_WARNING);
                trigger_error('a probe deprecation', E_USER_DEPRECATED);
                file_get_contents('/nonexistent/noisy-router/missing');
                @file_get_contents('/nonexistent/noisy-router/silenced');

                return new JsonResponse(['ok' => true]);
            case '/fatal':
                echo 'partial output';
                eval('final class NoisyRouterTwice {} final class NoisyRouterTwice {}');

                return new JsonResponse(['unreachable' => true]);
            case '/stream':
                return new StreamedResponse(static function (): void {
                    echo 'streamed';
                    file_get_contents('/nonexistent/noisy-router/streamed');
                });
            default:
                throw new \RuntimeException('a probe exception');
        }
    }
};

$routes = [];
foreach (['noisy', 'fatal', 'exception', 'stream'] as $name) {
    $routes[] = new Route($name, '/' . $name, ['GET'], $handler, true);
}

(new FrontController(new ConfigLoader(null), [], static function (Config $config, LoggerInterface $logger) use ($routes): Kernel {
    return TestKernel::create($routes, $config->getTemporaryDirectory(), $logger);
}))->run(Request::createFromGlobals());
