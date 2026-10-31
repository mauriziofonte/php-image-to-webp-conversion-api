<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Http;

/**
 * PHP's built-in web server ("php -S") started in the background on a free
 * loopback port, running the real front controller with an explicit
 * environment, and a minimal HTTP/1.1 client for it.
 *
 * The server runs with PHP_BINARY, so it runs the PHP version of the test,
 * and with display_errors off and log_errors on, as in production: PHP
 * errors go to the log file, never into a response body.
 */
final class BuiltinServer
{
    /**
     * @var resource
     */
    private $process;

    /**
     * @var int
     */
    private $port;

    /**
     * @var string
     */
    private $logFile;

    /**
     * @var bool
     */
    private $stopped = false;

    /**
     * @param resource $process
     */
    private function __construct($process, int $port, string $logFile)
    {
        $this->process = $process;
        $this->port = $port;
        $this->logFile = $logFile;
    }

    /**
     * Starts a server and waits until it accepts connections.
     *
     * @param string                $documentRoot directory given to -t
     * @param string                $router       script run for every request
     * @param array<string, string> $environment  the whole environment of the server process
     * @param string                $logFile      receives the server's output (access log, PHP errors)
     *
     * @throws \RuntimeException when the server cannot be started within 10 seconds
     */
    public static function start(string $documentRoot, string $router, array $environment, string $logFile): self
    {
        $lastError = '';
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $port = self::freePort();
            $command = sprintf(
                'exec %s -d display_errors=0 -d log_errors=1 -d error_log= -S 127.0.0.1:%d -t %s %s',
                escapeshellarg(PHP_BINARY),
                $port,
                escapeshellarg($documentRoot),
                escapeshellarg($router)
            );
            // "exec" replaces the shell, so that terminating the process stops the server itself.
            $process = proc_open(
                $command,
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
                $pipes,
                $documentRoot,
                $environment
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('Cannot start the PHP built-in web server');
            }

            $server = new self($process, $port, $logFile);
            if ($server->waitUntilReady()) {
                return $server;
            }
            $lastError = $server->getOutput();
            $server->stop();
        }

        throw new \RuntimeException('The PHP built-in web server did not start: ' . $lastError);
    }

    /**
     * Sends one request and reads the response until the server closes the
     * connection.
     *
     * @param string                $target  request target as sent on the request line
     * @param array<string, string> $headers extra headers; Host defaults to 127.0.0.1:<port>
     *
     * @throws \RuntimeException when the server cannot be reached or answers with no status line
     *
     * @return array{status: int, headers: array<string, string>, body: string} header names in lower case
     */
    public function request(string $method, string $target, array $headers = [], string $body = ''): array
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 5.0);
        if (!is_resource($socket)) {
            throw new \RuntimeException(sprintf('Cannot connect to 127.0.0.1:%d: %s', $this->port, $errstr));
        }
        stream_set_timeout($socket, 30);

        $headers += ['Host' => '127.0.0.1:' . $this->port];
        $headers['Content-Length'] = (string) strlen($body);
        $headers['Connection'] = 'close';
        $request = $method . ' ' . $target . " HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            $request .= $name . ': ' . $value . "\r\n";
        }
        fwrite($socket, $request . "\r\n" . $body);
        $raw = (string) stream_get_contents($socket);
        fclose($socket);

        $parts = explode("\r\n\r\n", $raw, 2);
        $lines = explode("\r\n", $parts[0]);
        if (preg_match('#\AHTTP/\d\.\d (\d{3})#', $lines[0], $match) !== 1) {
            throw new \RuntimeException('No status line in the response: ' . $raw . "\n" . $this->getOutput());
        }
        $responseHeaders = [];
        foreach (array_slice($lines, 1) as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $responseHeaders[strtolower(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
            }
        }

        return ['status' => (int) $match[1], 'headers' => $responseHeaders, 'body' => $parts[1] ?? ''];
    }

    /**
     * Everything the server wrote so far (access log and PHP errors).
     */
    public function getOutput(): string
    {
        return (string) @file_get_contents($this->logFile);
    }

    /**
     * Stops the server and waits for the process to exit. Calling it again
     * does nothing.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        proc_terminate($this->process);
        proc_close($this->process);
    }

    private function waitUntilReady(): bool
    {
        $failure = sprintf('Failed to listen on 127.0.0.1:%d', $this->port);
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 0.2);
            if (is_resource($socket)) {
                fclose($socket);

                return true;
            }
            if (strpos($this->getOutput(), $failure) !== false) {
                return false;
            }
            usleep(10000);
        }

        return false;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($socket)) {
            throw new \RuntimeException('Cannot find a free port: ' . $errstr);
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
