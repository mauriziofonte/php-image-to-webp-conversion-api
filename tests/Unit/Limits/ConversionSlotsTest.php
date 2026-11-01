<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Limits;

use Mfonte\ImageConversionApi\Limits\ConversionSlots;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\ConcurrencyLimitException;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Limits\ConversionSlots
 */
#[CoversClass(ConversionSlots::class)]
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
}
