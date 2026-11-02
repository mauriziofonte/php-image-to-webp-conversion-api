<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Limits;

use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\SecureImageConverter\Concurrency\LeaseInterface;
use Mfonte\SecureImageConverter\Concurrency\SemaphoreInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;

/**
 * The semaphore of ConversionSlots, as the library's SecureConverter takes
 * it: a slot file that cannot be opened or locked (the library's
 * InvalidArgumentException, internal_error; it also reports a wait out of
 * range, which Config prevents) becomes the 503 of
 * ConversionSlots::unavailable(). The library lets an exception of its
 * semaphore through convert() unchanged, after it released what it held,
 * so the handlers answer it as any RequestException. A busy host is not a
 * failure: ConcurrencyLimitException passes through.
 *
 * @internal created by ConversionSlots
 */
final class GuardedSemaphore implements SemaphoreInterface
{
    /**
     * @var SemaphoreInterface
     */
    private $inner;

    public function __construct(SemaphoreInterface $inner)
    {
        $this->inner = $inner;
    }

    /**
     * @throws RequestException 503 concurrency_unavailable when a slot file cannot be opened or locked
     */
    public function acquire(float $timeout): LeaseInterface
    {
        try {
            return $this->inner->acquire($timeout);
        } catch (\Throwable $exception) {
            throw self::translate($exception);
        }
    }

    /**
     * @throws RequestException 503 concurrency_unavailable when a slot file cannot be opened or locked
     */
    public function tryAcquire(): ?LeaseInterface
    {
        try {
            return $this->inner->tryAcquire();
        } catch (\Throwable $exception) {
            throw self::translate($exception);
        }
    }

    public function getSlots(): int
    {
        return $this->inner->getSlots();
    }

    /**
     * The exception to throw instead of $exception: the 503 of
     * ConversionSlots::unavailable() for the library's
     * InvalidArgumentException, $exception itself otherwise. The interface
     * of the library's semaphore declares only ConcurrencyLimitException,
     * while its Semaphore also throws InvalidArgumentException, hence the
     * catch of every Throwable.
     */
    private static function translate(\Throwable $exception): \Throwable
    {
        return $exception instanceof InvalidArgumentException ? ConversionSlots::unavailable($exception) : $exception;
    }
}
