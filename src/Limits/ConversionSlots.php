<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Limits;

use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\SecureImageConverter\Concurrency\LockDirectory;
use Mfonte\SecureImageConverter\Concurrency\Semaphore;
use Mfonte\SecureImageConverter\Concurrency\SemaphoreInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * The conversion slots of the host: CONCURRENCY_SLOTS lock files in
 * LOCK_DIR, as the library's Semaphore keeps them, shared by every PHP
 * process that uses the same directory. The semaphore is created on first
 * use, so that requests that convert nothing never touch LOCK_DIR.
 *
 * A LOCK_DIR that cannot be used (writable by other users, not creatable,
 * a slot file that cannot be opened or locked) fails closed: the request
 * is refused with 503 concurrency_unavailable and Retry-After, as a
 * failing rate limit store is refused with 503 rate_limit_unavailable,
 * never converted beyond the limit nor answered 500. The library reports
 * these failures with its InvalidArgumentException (internal_error), at
 * construction or when a slot is taken; both become the RequestException
 * of unavailable(), with the library's exception as the previous one for
 * the log. A failed construction is not kept: the next request tries
 * again, so that fixing LOCK_DIR needs no restart.
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
     * The semaphore of the slots. Its acquire() and tryAcquire() throw the
     * RequestException of unavailable() when a slot file cannot be opened
     * or locked; a busy host still fails with the library's
     * ConcurrencyLimitException.
     *
     * @throws RequestException 503 concurrency_unavailable when LOCK_DIR is not private to the PHP user or cannot be
     *                          created, or the number of slots is out of range
     */
    public function semaphore(): SemaphoreInterface
    {
        if ($this->semaphore === null) {
            try {
                $semaphore = new Semaphore(new LockDirectory($this->path), self::NAME, $this->slots);
            } catch (InvalidArgumentException $exception) {
                throw self::unavailable($exception);
            }
            $this->semaphore = new GuardedSemaphore($semaphore);
        }

        return $this->semaphore;
    }

    public function getSlots(): int
    {
        return $this->slots;
    }

    /**
     * The error of slots that cannot be taken: 503
     * concurrency_unavailable, with Retry-After and a fixed sentence.
     *
     * @param \Throwable $previous the library's failure, kept for the log
     */
    public static function unavailable(\Throwable $previous): RequestException
    {
        return new RequestException(
            503,
            ResponseFactory::ERROR_CONCURRENCY_UNAVAILABLE,
            'The concurrency limit cannot be checked: retry later.',
            ['Retry-After' => '1'],
            $previous
        );
    }
}
