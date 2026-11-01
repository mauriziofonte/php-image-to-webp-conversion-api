<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Limits;

use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Concurrency\Semaphore;
use Mfonte\SecureImageConverter\Concurrency\SemaphoreInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * The conversion slots of the host: CONCURRENCY_SLOTS lock files in
 * LOCK_DIR, as the library's Semaphore keeps them, shared by every PHP
 * process that uses the same directory. The semaphore is created on first
 * use, so that requests that convert nothing never touch LOCK_DIR.
 */
final class ConversionSlots
{
    /**
     * Name of the semaphore in LOCK_DIR.
     */
    public const NAME = 'conversions';

    /**
     * @var string
     */
    private $path;

    /**
     * @var int
     */
    private $slots;

    /**
     * @var SemaphoreInterface|null
     */
    private $semaphore;

    /**
     * @param string $path  LOCK_DIR
     * @param int    $slots CONCURRENCY_SLOTS, 1 to Semaphore::MAX_SLOTS
     */
    public function __construct(string $path, int $slots)
    {
        $this->path = $path;
        $this->slots = $slots;
    }

    /**
     * @throws InvalidArgumentException when LOCK_DIR is not private to the PHP user or cannot be created, or the
     *                                  number of slots is out of range
     */
    public function semaphore(): SemaphoreInterface
    {
        if ($this->semaphore === null) {
            $this->semaphore = new Semaphore(new LockDirectory($this->path), self::NAME, $this->slots);
        }

        return $this->semaphore;
    }

    public function getSlots(): int
    {
        return $this->slots;
    }
}
