<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Config\ConfigLoader;
use Mfonte\ImageConversionApi\Exception\InvalidConfigurationException;
use Mfonte\ImageConversionApi\Observability\JsonLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves one request in a web server SAPI (public/index.php) so that
 * nothing but the response reaches the client (finding C-5): no PHP
 * notice, warning, deprecation or fatal error message, and no stray
 * output, whatever php.ini says.
 *
 * 1. display_errors, display_startup_errors and html_errors are turned
 *    off and log_errors on, before anything else runs;
 * 2. the configuration is loaded (ConfigLoader); the JSON logger is built
 *    from LOG_FILE and LOG_LEVEL, with the request ID (RequestId). An
 *    invalid configuration is logged at critical level, to PHP's error
 *    log, and answered 503 invalid_configuration;
 * 3. while the kernel runs, an error handler logs every PHP error that
 *    error_reporting() reports (a warning entry, or error for
 *    E_USER_ERROR and E_RECOVERABLE_ERROR) with its type, file and line,
 *    and keeps PHP from printing it; an error silenced with "@" is
 *    neither logged nor printed;
 * 4. everything printed meanwhile is caught by an output buffer and
 *    discarded before the response is sent; its size is logged;
 * 5. a fatal error (which skips the code after it) is logged at critical
 *    level by a shutdown function, which discards what was printed,
 *    PHP's own error message included, and answers 500 internal_error
 *    when no header has been sent yet;
 * 6. an exception that escapes the kernel is logged and answered 500
 *    internal_error.
 *
 * Every response carries X-Request-Id. The error handler stays installed
 * while the response is sent, so a warning raised while a body streams is
 * logged rather than printed into it.
 */
final class FrontController
{
    /**
     * PHP error types that end the script.
     */
    private const FATAL_ERRORS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

    private const ERROR_NAMES = [
        E_WARNING => 'E_WARNING',
        E_NOTICE => 'E_NOTICE',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_ERROR => 'E_ERROR',
        E_PARSE => 'E_PARSE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
    ];

    /**
     * @var ConfigLoader
     */
    private $loader;

    /**
     * @var list<string>
     */
    private $webRoots;

    /**
     * @var callable(Config, LoggerInterface): Kernel
     */
    private $kernelFactory;

    /**
     * @param list<string>                                     $webRoots      directories the web server serves files
     *                                                                        from (see Config::fromArray())
     * @param (callable(Config, LoggerInterface): Kernel)|null $kernelFactory builds the kernel; KernelFactory::create()
     *                                                                        when null
     */
    public function __construct(ConfigLoader $loader, array $webRoots, ?callable $kernelFactory = null)
    {
        $this->loader = $loader;
        $this->webRoots = $webRoots;
        $this->kernelFactory = $kernelFactory ?? static function (Config $config, LoggerInterface $logger): Kernel {
            return KernelFactory::create($config, $logger);
        };
    }

    /**
     * Serves $request and sends the response.
     */
    public function run(Request $request): void
    {
        self::silenceErrorDisplay();
        $requestId = RequestId::of($request);
        $bootLogger = new JsonLogger(null, LogLevel::DEBUG, $requestId);

        try {
            $config = $this->loader->load($this->webRoots);
        } catch (InvalidConfigurationException $exception) {
            $bootLogger->critical('Invalid configuration', ['errors' => $exception->getErrors()]);
            self::send((new ResponseFactory())->invalidConfiguration(), $requestId);

            return;
        }
        $logger = new JsonLogger(JsonLogger::writerFor($config->getLogFile()), $config->getLogLevel(), $requestId);

        $bufferLevel = ob_get_level();
        ob_start();
        set_error_handler(static function (int $type, string $message, string $file = '', int $line = 0) use ($logger): bool {
            if ((error_reporting() & $type) === 0) {
                // Silenced with "@", or below error_reporting: neither logged nor printed.
                return true;
            }
            $level = ($type & (E_USER_ERROR | E_RECOVERABLE_ERROR)) !== 0 ? LogLevel::ERROR : LogLevel::WARNING;
            $logger->log($level, 'PHP {php_error}: {reason}', [
                'php_error' => self::ERROR_NAMES[$type] ?? (string) $type,
                'reason' => $message,
                'file' => $file . ':' . $line,
            ]);

            return true;
        });
        register_shutdown_function(static function () use ($logger, $bufferLevel, $requestId): void {
            $error = error_get_last();
            if ($error === null || ($error['type'] & self::FATAL_ERRORS) === 0) {
                return;
            }
            $logger->critical('PHP {php_error}: {reason}', [
                'php_error' => self::ERROR_NAMES[$error['type']] ?? (string) $error['type'],
                'reason' => $error['message'],
                'file' => $error['file'] . ':' . $error['line'],
            ]);
            self::discardOutput($bufferLevel);
            if (!headers_sent()) {
                self::send((new ResponseFactory())->internalError(), $requestId);
            }
        });

        try {
            $response = ($this->kernelFactory)($config, $logger)->handle($request);
        } catch (\Throwable $exception) {
            $logger->error('Unhandled exception outside the routes', ['exception' => $exception]);
            $response = (new ResponseFactory())->internalError();
        }

        $discarded = self::discardOutput($bufferLevel);
        if ($discarded > 0) {
            $logger->warning('Discarded {bytes} bytes of unexpected output', ['bytes' => $discarded]);
        }
        self::send($response, $requestId);
    }

    /**
     * Keeps PHP from printing errors, whatever php.ini says; they are
     * logged instead.
     */
    private static function silenceErrorDisplay(): void
    {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('html_errors', '0');
        ini_set('log_errors', '1');
    }

    /**
     * Closes and empties every output buffer above $level.
     *
     * @return int the bytes discarded
     */
    private static function discardOutput(int $level): int
    {
        $bytes = 0;
        while (ob_get_level() > $level) {
            $output = ob_get_clean();
            $bytes += is_string($output) ? strlen($output) : 0;
        }

        return $bytes;
    }

    private static function send(Response $response, string $requestId): void
    {
        $response->headers->set(RequestId::HEADER, $requestId);
        $response->send();
    }
}
