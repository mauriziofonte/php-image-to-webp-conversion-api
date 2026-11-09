<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Limits;

use Mfonte\ImageConversionApi\Exception\RuntimeException;
use Mfonte\ImageConversionApi\Limits\RateLimiter;
use Mfonte\ImageConversionApi\Tests\Fixtures\Limits\MemoryRateLimitStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Limits\RateLimiter
 */
#[CoversClass(RateLimiter::class)]
final class RateLimiterTest extends TestCase
{
    /**
     * @var int microseconds
     */
    private $now = 1000000000000000;

    public function testAllowsTheBurstThenRefusesUntilTheNextToken(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 5);

        for ($request = 0; $request < 5; ++$request) {
            self::assertSame(0, $limiter->consume('client'), 'request ' . $request . ' of the burst');
        }
        self::assertSame(1, $limiter->consume('client'));

        $this->now += 999999;
        self::assertSame(1, $limiter->consume('client'), 'one token per second, not yet');
        $this->now += 1;
        self::assertSame(0, $limiter->consume('client'));
        self::assertSame(1, $limiter->consume('client'));
    }

    /**
     * A request that costs several tokens takes them all, or none.
     */
    public function testARequestMayCostSeveralTokens(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 5);

        self::assertSame(0, $limiter->consume('client', 3));
        self::assertSame(1, $limiter->consume('client', 3), 'two tokens left: refused, nothing taken');
        self::assertSame(0, $limiter->consume('client', 2));
        self::assertSame(1, $limiter->consume('client'));

        $this->now += 2000000;
        self::assertSame(1, $limiter->consume('client', 3), 'two tokens refilled, three needed: one more second');
        self::assertSame(0, $limiter->consume('client', 2));
    }

    /**
     * The cost is capped at the burst, so that a large request is delayed,
     * never refused for ever; a cost below 1 counts as 1.
     */
    public function testTheCostIsCappedAtTheBurst(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 4);

        self::assertSame(0, $limiter->consume('client', 10));
        self::assertSame(1, $limiter->consume('client', 0));
        $this->now += 4000000;
        self::assertSame(0, $limiter->consume('client', 100));
        self::assertSame(4, $limiter->consume('client', 4));
    }

    public function testTellsHowLongToWait(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 6, 1);

        self::assertSame(0, $limiter->consume('client'));
        self::assertSame(10, $limiter->consume('client'), 'six per minute: one every ten seconds');
        $this->now += 2500000;
        self::assertSame(8, $limiter->consume('client'), '7.5 seconds rounded up');
    }

    public function testARefusedRequestTakesNoToken(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 1);

        self::assertSame(0, $limiter->consume('client'));
        for ($request = 0; $request < 100; ++$request) {
            $limiter->consume('client');
        }
        $this->now += 1000000;

        self::assertSame(0, $limiter->consume('client'), 'hammering does not push the next token further');
    }

    public function testTheBucketRefillsUpToTheBurstOnly(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 3);
        $limiter->consume('client');
        $this->now += 3600 * 1000000;

        $allowed = 0;
        for ($request = 0; $request < 10; ++$request) {
            $allowed += $limiter->consume('client') === 0 ? 1 : 0;
        }

        self::assertSame(3, $allowed);
    }

    public function testEveryClientHasItsOwnBucket(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 1);

        self::assertSame(0, $limiter->consume('first'));
        self::assertSame(1, $limiter->consume('first'));
        self::assertSame(0, $limiter->consume('second'));
    }

    /**
     * The wall clock steps back by an hour after the burst was spent: the
     * key is refused until the clock has caught up (the safe side), then
     * counts again.
     */
    public function testAClockThatStepsBackDelaysTheKeyByTheStep(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 3);
        for ($request = 0; $request < 3; ++$request) {
            self::assertSame(0, $limiter->consume('client'));
        }

        $this->now -= 3600000000;

        self::assertSame(3601, $limiter->consume('client'), 'the size of the step, plus the next token');
        $this->now += 3601000000;
        self::assertSame(0, $limiter->consume('client'));
        self::assertSame(1, $limiter->consume('client'));
    }

    /**
     * Requests that alternate between two clocks apart (two hosts on one
     * store, or a clock stepping back and forth) get no more than the
     * limit: 60 per minute with a burst of 3, one request every 100 ms
     * for 60 s.
     *
     * @dataProvider provideClockSkews
     */
    #[DataProvider('provideClockSkews')]
    public function testClocksThatDisagreeDoNotLetMoreThroughThanTheLimit(int $skew, int $period): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 60, 3);
        $real = $this->now;

        $allowed = 0;
        for ($request = 0; $request < 600; ++$request) {
            $real += 100000;
            $this->now = intdiv($request, $period) % 2 === 1 ? $real - $skew : $real;
            $allowed += $limiter->consume('client') === 0 ? 1 : 0;
        }

        self::assertLessThanOrEqual(63, $allowed, 'the burst plus one per second');
        self::assertGreaterThanOrEqual(1, $allowed);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function provideClockSkews(): array
    {
        return [
            'two clocks 2 s apart, alternating' => [2000000, 1],
            'two clocks 10 s apart, alternating' => [10000000, 1],
            'one hour back and forward every second' => [3600000000, 10],
        ];
    }

    public function testThrowsWhenTheStoreFails(): void
    {
        $this->expectException(RuntimeException::class);

        $this->limiter(new MemoryRateLimitStore(true), 60, 10)->consume('client');
    }

    public function testAverageRateOverAMinute(): void
    {
        $limiter = $this->limiter(new MemoryRateLimitStore(), 120, 10);

        $allowed = 0;
        for ($tick = 0; $tick < 600; ++$tick) {
            $allowed += $limiter->consume('client') === 0 ? 1 : 0;
            $this->now += 100000;
        }

        self::assertGreaterThanOrEqual(129, $allowed, 'the burst plus two per second over 60 seconds');
        self::assertLessThanOrEqual(130, $allowed);
    }

    private function limiter(MemoryRateLimitStore $store, int $perMinute, int $burst): RateLimiter
    {
        return new RateLimiter($store, $perMinute, $burst, function (): int {
            return $this->now;
        });
    }
}
