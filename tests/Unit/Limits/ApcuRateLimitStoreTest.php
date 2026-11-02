<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Limits;

use Mfonte\ImageConversionApi\Limits\ApcuRateLimitStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Runs where APCu is loaded and enabled for the CLI (apc.enable_cli=1);
 * skipped elsewhere.
 *
 * @covers \Mfonte\ImageConversionApi\Limits\ApcuRateLimitStore
 */
#[CoversClass(ApcuRateLimitStore::class)]
final class ApcuRateLimitStoreTest extends TestCase
{
    /**
     * @var string
     */
    private $key;

    protected function set_up(): void
    {
        parent::set_up();
        if (!ApcuRateLimitStore::isAvailable()) {
            self::markTestSkipped('APCu is not enabled for the CLI.');
        }
        $this->key = 'test-' . bin2hex(random_bytes(8));
    }

    protected function tear_down(): void
    {
        if (ApcuRateLimitStore::isAvailable()) {
            apcu_delete(ApcuRateLimitStore::PREFIX . $this->key);
        }
        parent::tear_down();
    }

    public function testStoresAndUpdatesAValue(): void
    {
        $store = new ApcuRateLimitStore();
        $seen = [];
        $increment = static function (?int $value) use (&$seen): int {
            $seen[] = $value;

            return ($value ?? 0) + 5;
        };

        self::assertTrue($store->update($this->key, $increment));
        self::assertTrue($store->update($this->key, $increment));

        self::assertSame([null, 5], $seen);
        self::assertSame(10, apcu_fetch(ApcuRateLimitStore::PREFIX . $this->key));
    }

    public function testANullDecisionKeepsTheValue(): void
    {
        $store = new ApcuRateLimitStore();
        $store->update($this->key, static function (): int {
            return 3;
        });

        self::assertTrue($store->update($this->key, static function (): ?int {
            return null;
        }));
        self::assertSame(3, apcu_fetch(ApcuRateLimitStore::PREFIX . $this->key));
    }

    public function testReplacesAValueThatIsNoInteger(): void
    {
        apcu_store(ApcuRateLimitStore::PREFIX . $this->key, 'garbage');

        self::assertTrue((new ApcuRateLimitStore())->update($this->key, static function (?int $value): int {
            self::assertNull($value);

            return 1;
        }));
        self::assertSame(1, apcu_fetch(ApcuRateLimitStore::PREFIX . $this->key));
    }
}
