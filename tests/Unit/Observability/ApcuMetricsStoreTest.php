<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Observability;

use Mfonte\ImageConversionApi\Observability\ApcuMetricsStore;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Runs where APCu is loaded and enabled for the CLI (apc.enable_cli=1);
 * skipped elsewhere.
 *
 * @covers \Mfonte\ImageConversionApi\Observability\ApcuMetricsStore
 */
#[CoversClass(ApcuMetricsStore::class)]
final class ApcuMetricsStoreTest extends TestCase
{
    /**
     * @var string
     */
    private $name;

    protected function set_up(): void
    {
        parent::set_up();
        if (!ApcuMetricsStore::isAvailable()) {
            self::markTestSkipped('APCu is not enabled for the CLI.');
        }
        $this->name = 'test_' . bin2hex(random_bytes(8));
    }

    protected function tear_down(): void
    {
        if (ApcuMetricsStore::isAvailable()) {
            apcu_delete(ApcuMetricsStore::PREFIX . $this->name);
            apcu_delete(ApcuMetricsStore::PREFIX . $this->name . '{x="1"}');
        }
        parent::tear_down();
    }

    public function testAddsTheIncrements(): void
    {
        $store = new ApcuMetricsStore();
        $labelled = $this->name . '{x="1"}';

        self::assertTrue($store->add([$this->name => 2, $labelled => 1]));
        self::assertTrue((new ApcuMetricsStore())->add([$this->name => 3]));

        $counters = (new ApcuMetricsStore())->read();
        self::assertIsArray($counters);
        self::assertSame(5, $counters[$this->name] ?? null);
        self::assertSame(1, $counters[$labelled] ?? null);
    }

    public function testIgnoresEntriesThatAreNotCounters(): void
    {
        apcu_store(ApcuMetricsStore::PREFIX . $this->name, 'text');

        $counters = (new ApcuMetricsStore())->read();
        self::assertIsArray($counters);
        self::assertArrayNotHasKey($this->name, $counters);
    }
}
