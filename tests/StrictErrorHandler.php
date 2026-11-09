<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests;

/**
 * Error handler installed by tests/bootstrap.php, before the Composer
 * autoloader.
 *
 * Every notice, warning and deprecation becomes an \ErrorException, so that a
 * PHP 8.4 or 8.5 deprecation in the project's own code fails the build.
 * Deprecations raised by files inside vendor/ are tolerated: the lowest
 * supported releases of the dependencies trigger them on the newest PHP, and
 * they are not the project's to fix. They are counted instead, and reported
 * once on STDERR at shutdown so that they stay visible. Errors silenced with
 * the @ operator, or excluded by error_reporting(), are left to PHP.
 *
 * PHPUnit 8.5 to 11.5 keep an error handler that is already installed when
 * they start, so this one stays active for the whole run.
 */
final class StrictErrorHandler
{
    /**
     * @var string Absolute vendor directory with forward slashes and a trailing slash
     */
    private $vendorDirectory;

    /**
     * @var array<string, int> "message in file:line" => number of occurrences
     */
    private $vendorDeprecations = [];

    /**
     * @param string $vendorDirectory absolute path of the Composer vendor
     *                                directory, resolved when it exists, as
     *                                PHP reports resolved paths
     */
    public function __construct(string $vendorDirectory)
    {
        $realPath = realpath($vendorDirectory);
        if ($realPath !== false) {
            $vendorDirectory = $realPath;
        }
        $this->vendorDirectory = rtrim(str_replace('\\', '/', $vendorDirectory), '/') . '/';
    }

    /**
     * Installs a new handler as the current PHP error handler, and registers
     * the shutdown report of the vendor deprecations it tolerated.
     *
     * @param string $vendorDirectory absolute path of the Composer vendor directory
     */
    public static function install(string $vendorDirectory): self
    {
        $handler = new self($vendorDirectory);
        set_error_handler($handler);
        register_shutdown_function([$handler, 'reportVendorDeprecations']);

        return $handler;
    }

    /**
     * Handles one PHP error, with the signature expected by set_error_handler().
     *
     * @throws \ErrorException for every error that is reported and not tolerated
     *
     * @return bool true when the error is a tolerated vendor deprecation, false to let PHP handle it
     */
    public function __invoke(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        $isDeprecation = $severity === E_DEPRECATED || $severity === E_USER_DEPRECATED;
        if ($isDeprecation && strpos(str_replace('\\', '/', $file), $this->vendorDirectory) === 0) {
            $key = sprintf('%s in %s:%d', $message, $file, $line);
            $this->vendorDeprecations[$key] = ($this->vendorDeprecations[$key] ?? 0) + 1;

            return true;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * @return array<string, int> tolerated vendor deprecations ("message in file:line") with their count
     */
    public function getVendorDeprecations(): array
    {
        return $this->vendorDeprecations;
    }

    /**
     * Writes the tolerated vendor deprecations, each once with its count.
     * Writes nothing when there are none.
     *
     * @param resource|null $stream where to write; STDERR when null
     */
    public function reportVendorDeprecations($stream = null): void
    {
        if ($this->vendorDeprecations === []) {
            return;
        }
        if ($stream === null) {
            if (!defined('STDERR')) {
                return;
            }
            $stream = STDERR;
        }

        $lines = [sprintf(
            '%d distinct deprecation(s) raised inside vendor/ (not fatal):',
            count($this->vendorDeprecations)
        )];
        foreach ($this->vendorDeprecations as $description => $count) {
            $lines[] = sprintf('  [%dx] %s', $count, $description);
        }

        fwrite($stream, PHP_EOL . implode(PHP_EOL, $lines) . PHP_EOL);
    }
}
