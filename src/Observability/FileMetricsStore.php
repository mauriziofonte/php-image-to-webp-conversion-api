<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Observability;

use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;

/**
 * Keeps every counter in one JSON file of LOCK_DIR ("metrics.json"), read
 * and written under an exclusive flock() of that file, so that every PHP
 * process of the host shares the counters. One update costs one locked
 * read and write of a file of a few kilobytes, next to a conversion of
 * tens of milliseconds or more.
 *
 * The directory is checked by the library's LockDirectory on first use:
 * private to the PHP user, so that no other user can change the counters.
 */
final class FileMetricsStore implements MetricsStoreInterface
{
    public const FILE_NAME = 'metrics.json';

    /**
     * @var string
     */
    private $path;

    /**
     * @var string|null the file, once its directory is checked
     */
    private $file;

    /**
     * @param string $path absolute path of the directory (LOCK_DIR); created with mode 0700 when missing
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function add(array $increments): bool
    {
        $handle = $this->open();
        if ($handle === null) {
            return false;
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                return false;
            }
            $counters = self::decode(@stream_get_contents($handle, -1, 0));
            foreach ($increments as $name => $amount) {
                $counters[$name] = ($counters[$name] ?? 0) + $amount;
            }
            $json = json_encode($counters, JSON_UNESCAPED_SLASHES);
            $written = is_string($json) && @ftruncate($handle, 0) && @rewind($handle) && @fwrite($handle, $json) !== false && @fflush($handle);
            @flock($handle, LOCK_UN);

            return $written;
        } finally {
            @fclose($handle);
        }
    }

    public function read(): ?array
    {
        $handle = $this->open();
        if ($handle === null) {
            return null;
        }

        try {
            if (!@flock($handle, LOCK_SH)) {
                return null;
            }
            $counters = self::decode(@stream_get_contents($handle, -1, 0));
            @flock($handle, LOCK_UN);

            return $counters;
        } finally {
            @fclose($handle);
        }
    }

    /**
     * @return resource|null
     */
    private function open()
    {
        if ($this->file === null) {
            try {
                $this->file = (new LockDirectory($this->path))->getPath() . '/' . self::FILE_NAME;
            } catch (LibraryException $exception) {
                return null;
            }
        }
        $handle = @fopen($this->file, 'c+b');

        return $handle === false ? null : $handle;
    }

    /**
     * The counters of the file; empty when it is empty or damaged.
     *
     * @param string|false $json
     *
     * @return array<string, int>
     */
    private static function decode($json): array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return [];
        }
        $counters = [];
        foreach ($data as $name => $value) {
            if (is_string($name) && is_int($value)) {
                $counters[$name] = $value;
            }
        }

        return $counters;
    }
}
