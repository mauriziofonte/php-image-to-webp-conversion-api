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
 * status 1 there). Every directory is named with PREFIX, and the private
 * working directories of the conversion library are created inside the
 * request directory (LibraryConverterFactory), so that one rule covers
 * everything such a process leaves: when built with $staleAfterSeconds, the
 * first create() of each instance removes the directories with PREFIX in
 * the parent that nobody has changed for that long (no request can still
 * own one: a request ends within CONVERSION_TIME_LIMIT), and a cleaner such
 * as systemd-tmpfiles can be scoped to PREFIX as well.
 *
 * Removal never follows symbolic links, never raises a PHP warning (it may
 * run at shutdown, after the owner of a directory already removed it, under
 * a strict error handler) and is idempotent. It reports what it could not
 * remove (a subdirectory the handler made read-only, say): such a directory
 * stays tracked, so that removeAll() tries again at shutdown, and the
 * kernel logs a warning.
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
     * @var int|null
     */
    private $staleAfterSeconds;

    /**
     * @var bool
     */
    private $swept = false;

    /**
     * @param string   $parent            directory that receives the request directories; created (mode 0700) on
     *                                    first use when it does not exist
     * @param int|null $staleAfterSeconds age, in seconds since its last change, after which a directory with PREFIX
     *                                    in the parent is left over by a dead process and removed by the first
     *                                    create(); null never removes one. It must be longer than any request
     */
    public function __construct(string $parent, ?int $staleAfterSeconds = null)
    {
        $this->parent = rtrim($parent, '/\\');
        if ($this->parent === '') {
            $this->parent = DIRECTORY_SEPARATOR;
        }
        $this->staleAfterSeconds = $staleAfterSeconds;
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
        if ($this->staleAfterSeconds !== null && !$this->swept) {
            $this->swept = true;
            $this->removeStale($this->staleAfterSeconds);
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
     * stops tracking it. When part of it cannot be removed, it stays
     * tracked, so that a later remove() or removeAll() tries again. Does
     * nothing for a path this object did not create or already removed.
     *
     * @return bool false when part of the directory is left; true when it is gone, or is not a directory this
     *              object tracks
     */
    public function remove(string $path): bool
    {
        if (!isset($this->directories[$path])) {
            return true;
        }
        if (!self::removeTree($path)) {
            return false;
        }
        unset($this->directories[$path]);

        return true;
    }

    /**
     * Removes every directory still tracked.
     *
     * @internal the shutdown function registered by create()
     *
     * @return list<string> the directories left, still tracked
     */
    public function removeAll(): array
    {
        foreach (array_keys($this->directories) as $path) {
            $this->remove((string) $path);
        }

        return $this->getTracked();
    }

    /**
     * Removes the directories with PREFIX in the parent, other than the ones
     * this object tracks, that nobody has changed for $seconds: what a
     * process killed during a request left. A symbolic link is never
     * followed nor removed; what cannot be removed (another user's) stays.
     *
     * @return list<string> the directories removed
     */
    public function removeStale(int $seconds): array
    {
        $entries = @scandir($this->parent);
        $removed = [];
        $threshold = time() - $seconds;
        foreach ($entries === false ? [] : $entries as $entry) {
            if (strpos($entry, self::PREFIX) !== 0) {
                continue;
            }
            $path = $this->parent . DIRECTORY_SEPARATOR . $entry;
            if (isset($this->directories[$path]) || is_link($path) || !is_dir($path)) {
                continue;
            }
            $changed = @filemtime($path);
            if ($changed !== false && $changed < $threshold && self::removeTree($path)) {
                $removed[] = $path;
            }
        }

        return $removed;
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
     *
     * @return bool whether $path is gone
     */
    private static function removeTree(string $path): bool
    {
        if (is_link($path) || !is_dir($path)) {
            // A file, a link, a FIFO or a socket, or nothing any more.
            if (!self::isGone($path)) {
                @unlink($path);
            }

            return self::isGone($path);
        }
        $entries = @scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);

        return self::isGone($path);
    }

    private static function isGone(string $path): bool
    {
        clearstatcache(true, $path);

        return !is_link($path) && !file_exists($path);
    }
}
