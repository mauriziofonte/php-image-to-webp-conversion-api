<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Limits;

use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * Keeps each value in a file of LOCK_DIR ("ratelimit.<key>.state"), read
 * and written under an exclusive flock() of that file, so that every PHP
 * process of the host (PHP-FPM pools, the CLI) shares the buckets.
 *
 * The directory is checked by the library's LockDirectory on first use:
 * private to the PHP user, so that no other user can plant or change a
 * state file. The limitations of LockDirectory apply (local filesystem,
 * one host).
 */
final class FileRateLimitStore implements RateLimitStoreInterface
{
    /**
     * @var string
     */
    private $path;

    /**
     * @var string|null the resolved directory, once checked
     */
    private $directory;

    /**
     * @param string $path absolute path of the directory; created with mode 0700 when missing
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @throws InvalidArgumentException when the directory is not private to the PHP user or cannot be created
     */
    public function update(string $key, callable $decide): bool
    {
        $name = self::fileName($key);
        if ($this->directory === null) {
            $this->directory = (new LockDirectory($this->path))->getPath();
        }
        $handle = @fopen($this->directory . '/' . $name, 'c+b');
        if ($handle === false) {
            return false;
        }

        try {
            if (!@flock($handle, LOCK_EX)) {
                return false;
            }
            $raw = @stream_get_contents($handle, 32, 0);
            $current = is_string($raw) && preg_match('/\A-?[0-9]{1,19}\z/', $raw) === 1 ? (int) $raw : null;
            $next = $decide($current);
            if ($next !== null) {
                $written = @ftruncate($handle, 0) && @rewind($handle) && @fwrite($handle, (string) $next) !== false && @fflush($handle);
                if (!$written) {
                    return false;
                }
            }
            @flock($handle, LOCK_UN);

            return true;
        } finally {
            @fclose($handle);
        }
    }

    /**
     * The name of the state file of $key: the key is checked against
     * [A-Za-z0-9_-]{1,64}, so the name holds no separator, no dot segment
     * and nothing else a path could be bent with.
     *
     * @psalm-taint-escape file
     *
     * @throws InvalidArgumentException when the key is not valid
     */
    private static function fileName(string $key): string
    {
        if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $key) !== 1) {
            throw new InvalidArgumentException('A rate limit key must be 1 to 64 characters among [A-Za-z0-9_-].');
        }

        return 'ratelimit.' . $key . '.state';
    }
}
