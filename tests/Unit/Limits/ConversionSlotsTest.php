<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Limits;

use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Limits\ConversionSlots;
use Mfonte\ImageConversionApi\Limits\GuardedSemaphore;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException as LibraryInvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Limits\ConversionSlots
 * @covers \Mfonte\ImageConversionApi\Limits\GuardedSemaphore
 */
#[CoversClass(ConversionSlots::class)]
#[CoversClass(GuardedSemaphore::class)]
final class ConversionSlotsTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testTouchesNothingUntilASlotIsNeeded(): void
    {
        $slots = new ConversionSlots($this->directory->path('locks'), 2);

        self::assertSame(2, $slots->getSlots());
        self::assertSame([], $this->directory->entries());
    }

    public function testInstancesOnOneDirectoryShareTheSlots(): void
    {
        $first = new ConversionSlots($this->directory->path('locks'), 2);
        $second = new ConversionSlots($this->directory->path('locks'), 2);

        $leases = [$first->semaphore()->acquire(0.0), $second->semaphore()->acquire(0.0)];
        self::assertSame($first->semaphore(), $first->semaphore());
        self::assertNull($second->semaphore()->tryAcquire());

        try {
            $first->semaphore()->acquire(0.0);
            self::fail('A ConcurrencyLimitException was expected');
        } catch (ConcurrencyLimitException $exception) {
            self::assertSame(1, $exception->getRetryAfterSeconds());
        }
        $leases[0]->release();
        self::assertNotNull($second->semaphore()->tryAcquire());
    }

    /**
     * Finding F2 of the W4 review: an unusable LOCK_DIR is a 503, never the
     * library's internal_error; the slots work once LOCK_DIR is fixed, with
     * no new instance.
     */
    public function testALockDirectoryOthersMayWriteToIsUnavailable(): void
    {
        $locks = $this->directory->path('locks');
        mkdir($locks);
        chmod($locks, 0777);
        $slots = new ConversionSlots($locks, 1);

        try {
            $slots->semaphore();
            self::fail('A RequestException was expected');
        } catch (RequestException $exception) {
            self::assertSame(503, $exception->getStatusCode());
            self::assertSame(ResponseFactory::ERROR_CONCURRENCY_UNAVAILABLE, $exception->getErrorCode());
            self::assertSame('The concurrency limit cannot be checked: retry later.', $exception->getMessage());
            self::assertSame(['Retry-After' => '1'], $exception->getHeaders());
            self::assertInstanceOf(LibraryInvalidArgumentException::class, $exception->getPrevious());
        }

        chmod($locks, 0700);
        $slots->semaphore()->acquire(0.0)->release();
    }

    public function testASlotFileThatCannotBeOpenedIsUnavailable(): void
    {
        $locks = $this->directory->path('locks');
        mkdir($locks . '/semaphore.' . ConversionSlots::NAME . '.0.lock', 0700, true);
        $semaphore = (new ConversionSlots($locks, 1))->semaphore();

        foreach ([static function () use ($semaphore): void {
            $semaphore->acquire(0.0);
        }, static function () use ($semaphore): void {
            $semaphore->tryAcquire();
        }] as $take) {
            try {
                $take();
                self::fail('A RequestException was expected');
            } catch (RequestException $exception) {
                self::assertSame(ResponseFactory::ERROR_CONCURRENCY_UNAVAILABLE, $exception->getErrorCode());
                self::assertInstanceOf(LibraryInvalidArgumentException::class, $exception->getPrevious());
            }
        }
    }
}
