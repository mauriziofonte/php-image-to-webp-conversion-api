<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Mfonte\ImageConversionApi\Exception\RuntimeException;

/**
 * Creates the per-request temporary directories under one parent directory
 * (TEMP_DIR, outside the web root) and removes them (finding C-8).
 *
 * The kernel removes the directory of a request in a finally block when
 * handle() ends, normally or with an exception. A fatal error skips finally
 * blocks and destructors, but not shutdown functions: the first directory
 * created registers removeAll(), bound to this object, as a shutdown
 * function, and removeAll() removes every directory still tracked. One
 * shutdown function per instance, not per request, so that a long-running
 * process that reuses the kernel does not pile them up; the object owns its
 * list of directories, so no static state is involved.
 *
 * What a fatal error cannot bypass, a dead process can: a process killed by a
 * signal (SIGKILL, or SIGTERM without a handler) runs no shutdown function,
 * and neither do some PHP builds after memory exhaustion (the builds of PHP
 * 7.2 to 8.1 that setup-php installs on GitHub's Ubuntu runners exit with
 * status 1 there). Their directories stay until something else removes them,
 * such as systemd-tmpfiles or tmpreaper for the system temporary directory.
 * Every directory is named with PREFIX, so that such a cleaner can be scoped
 * to them.
 *
 * Removal never follows symbolic links, never raises a PHP warning (it may
 * run at shutdown, after the owner of a directory already removed it, under
 * a strict error handler) and is idempotent.
 */
final class RequestDirectories
{
    /**
     * Prefix of the name of every request directory.
     */
    public const PREFIX = 'image-conversion-api-';

    /**
     * Attempts at finding an unused random name before giving up.
     */
    private const ATTEMPTS = 8;

    /**
     * @var string
     */
    private $parent;

    /**
     * @var array<string, true> directories created and not removed yet, keyed by path
     */
    private $directories = [];

    /**
     * @var bool
     */
    private $shutdownRegistered = false;

    /**
     * @param string $parent directory that receives the request directories; created (mode 0700) on first use
     *                       when it does not exist
     */
    public function __construct(string $parent)
    {
        $this->parent = rtrim($parent, '/\\');
        if ($this->parent === '') {
            $this->parent = DIRECTORY_SEPARATOR;
        }
    }

    public function getParent(): string
    {
        return $this->parent;
    }

    /**
     * A directory object for one request, created on its first getPath().
     */
    public function forRequest(): RequestDirectory
    {
        return new RequestDirectory($this);
    }

    /**
     * Creates a new, empty directory (mode 0700) with a random name under the
     * parent, and tracks it until remove() or the end of the process.
     *
     * @throws RuntimeException when the parent cannot be created or no directory can be created in it
     *
     * @return string the absolute path of the new directory
     */
    public function create(): string
    {
        if (!is_dir($this->parent) && !@mkdir($this->parent, 0700, true) && !is_dir($this->parent)) {
            throw new RuntimeException(sprintf('Cannot create the temporary directory %s', $this->parent));
        }

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            $path = $this->parent . DIRECTORY_SEPARATOR . self::PREFIX . bin2hex(random_bytes(8));
            if (@mkdir($path, 0700)) {
                $this->track($path);

                return $path;
            }
        }

        throw new RuntimeException(sprintf('Cannot create a request directory in %s', $this->parent));
    }

    /**
     * Removes a directory created by create(), with everything inside, and
     * stops tracking it. Does nothing for a path this object did not create
     * or already removed.
     */
    public function remove(string $path): void
    {
        if (!isset($this->directories[$path])) {
            return;
        }
        unset($this->directories[$path]);
        self::removeTree($path);
    }

    /**
     * Removes every directory still tracked.
     *
     * @internal the shutdown function registered by create()
     */
    public function removeAll(): void
    {
        foreach (array_keys($this->directories) as $path) {
            $this->remove((string) $path);
        }
    }

    /**
     * @return list<string> the directories created and not removed yet
     */
    public function getTracked(): array
    {
        return array_map('strval', array_keys($this->directories));
    }

    private function track(string $path): void
    {
        $this->directories[$path] = true;
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function([$this, 'removeAll']);
        }
    }

    /**
     * Removes $path and, for a directory, everything inside it. A symbolic
     * link is removed, never followed.
     */
    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = @scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }
}
