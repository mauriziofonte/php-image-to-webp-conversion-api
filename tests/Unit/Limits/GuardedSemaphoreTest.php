<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Limits;

use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Limits\GuardedSemaphore;
use Mfonte\SecureImageConverter\Concurrency\LeaseInterface;
use Mfonte\SecureImageConverter\Concurrency\SemaphoreInterface;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException as LibraryInvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Limits\GuardedSemaphore
 * @covers \Mfonte\ImageConversionApi\Limits\ConversionSlots
 */
#[CoversClass(GuardedSemaphore::class)]
final class GuardedSemaphoreTest extends TestCase
{
    public function testPassesLeasesAndTheBusyHostThrough(): void
    {
        $lease = $this->createMock(LeaseInterface::class);
        $inner = $this->createMock(SemaphoreInterface::class);
        $inner->method('acquire')->with(2.5)->willReturn($lease);
        $inner->method('tryAcquire')->willReturn(null);
        $inner->method('getSlots')->willReturn(3);
        $semaphore = new GuardedSemaphore($inner);

        self::assertSame($lease, $semaphore->acquire(2.5));
        self::assertNull($semaphore->tryAcquire());
        self::assertSame(3, $semaphore->getSlots());

        $busy = $this->createMock(SemaphoreInterface::class);
        $busy->method('acquire')->willThrowException(ConcurrencyLimitException::afterWaiting(0.0, 'busy'));
        $this->expectException(ConcurrencyLimitException::class);
        (new GuardedSemaphore($busy))->acquire(0.0);
    }

    public function testASlotFileFailureIsUnavailable(): void
    {
        $failure = new LibraryInvalidArgumentException('An entry in the lock directory is not a regular file.');
        $inner = $this->createMock(SemaphoreInterface::class);
        $inner->method('acquire')->willThrowException($failure);
        $inner->method('tryAcquire')->willThrowException($failure);
        $semaphore = new GuardedSemaphore($inner);

        foreach ([static function () use ($semaphore): void {
            $semaphore->acquire(1.0);
        }, static function () use ($semaphore): void {
            $semaphore->tryAcquire();
        }] as $take) {
            try {
                $take();
                self::fail('A RequestException was expected');
            } catch (RequestException $exception) {
                self::assertSame(503, $exception->getStatusCode());
                self::assertSame(ResponseFactory::ERROR_CONCURRENCY_UNAVAILABLE, $exception->getErrorCode());
                self::assertSame(['Retry-After' => '1'], $exception->getHeaders());
                self::assertSame($failure, $exception->getPrevious());
            }
        }
    }
}
