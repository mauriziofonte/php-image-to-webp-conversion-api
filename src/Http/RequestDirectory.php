<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Mfonte\ImageConversionApi\Exception\RuntimeException;

/**
 * The temporary directory of one request, created on the first getPath(),
 * so that requests that write no file (health checks, errors) touch no disk.
 *
 * The kernel calls remove() when the request ends, and logs a warning when
 * part of the directory is left; RequestDirectories removes it at shutdown
 * when a fatal error skipped that, or tries again what was left.
 */
final class RequestDirectory
{
    /**
     * @var RequestDirectories
     */
    private $directories;

    /**
     * @var string|null
     */
    private $path;

    /**
     * @var bool
     */
    private $removed = false;

    public function __construct(RequestDirectories $directories)
    {
        $this->directories = $directories;
    }

    /**
     * The absolute path of the directory, created (mode 0700, empty) on the
     * first call.
     *
     * @throws RuntimeException when the directory cannot be created, or was already removed
     */
    public function getPath(): string
    {
        if ($this->removed) {
            throw new RuntimeException('The request directory was already removed');
        }
        if ($this->path === null) {
            $this->path = $this->directories->create();
        }

        return $this->path;
    }

    /**
     * Whether getPath() created the directory and remove() was not called yet.
     */
    public function isCreated(): bool
    {
        return $this->path !== null && !$this->removed;
    }

    /**
     * The path getPath() created, also after remove(), for messages; null
     * when the directory was never created.
     */
    public function getCreatedPath(): ?string
    {
        return $this->path;
    }

    /**
     * Removes the directory with everything inside, when it was created.
     * getPath() throws afterwards. Calling it again tries again what is
     * left.
     *
     * @return bool false when part of the directory is left (it stays tracked by RequestDirectories); true when it
     *              is gone or was never created
     */
    public function remove(): bool
    {
        $this->removed = true;

        return $this->path === null || $this->directories->remove($this->path);
    }
}
