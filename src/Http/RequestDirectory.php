<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Mfonte\ImageConversionApi\Exception\RuntimeException;

/**
 * The temporary directory of one request, created on the first getPath(),
 * so that requests that write no file (health checks, errors) touch no disk.
 *
 * The kernel calls remove() when the request ends; RequestDirectories
 * removes it at shutdown when a fatal error skipped that.
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
     * Whether getPath() created the directory and it was not removed yet.
     */
    public function isCreated(): bool
    {
        return $this->path !== null && !$this->removed;
    }

    /**
     * Removes the directory with everything inside, when it was created.
     * Idempotent; getPath() throws afterwards.
     */
    public function remove(): void
    {
        $this->removed = true;
        if ($this->path !== null) {
            $this->directories->remove($this->path);
        }
    }
}
