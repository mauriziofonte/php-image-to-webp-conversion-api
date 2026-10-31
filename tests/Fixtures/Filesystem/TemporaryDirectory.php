<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem;

/**
 * A directory created below the system temporary directory and removed with
 * everything in it. Symbolic links are removed, never followed.
 */
final class TemporaryDirectory
{
    /**
     * @var string
     */
    private $path;

    public function __construct(string $prefix = 'image-conversion-api-test-')
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
        if (!mkdir($this->path, 0777, true)) {
            throw new \RuntimeException('Cannot create ' . $this->path);
        }
    }

    /**
     * Absolute path of the directory, or of $relativePath inside it.
     */
    public function path(string $relativePath = ''): string
    {
        return $relativePath === '' ? $this->path : $this->path . DIRECTORY_SEPARATOR . ltrim($relativePath, '/');
    }

    /**
     * The entries of the directory, or of $relativePath inside it, sorted,
     * without "." and "..".
     *
     * @return list<string>
     */
    public function entries(string $relativePath = ''): array
    {
        $entries = scandir($this->path($relativePath));
        if ($entries === false) {
            throw new \RuntimeException('Cannot list ' . $this->path($relativePath));
        }

        return array_values(array_diff($entries, ['.', '..']));
    }

    public function remove(): void
    {
        self::removePath($this->path);
    }

    private static function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                self::removePath($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        rmdir($path);
    }
}
