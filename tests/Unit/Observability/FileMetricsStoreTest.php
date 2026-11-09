<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Observability;

use Mfonte\ImageConversionApi\Observability\FileMetricsStore;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Observability\FileMetricsStore
 */
#[CoversClass(FileMetricsStore::class)]
final class FileMetricsStoreTest extends TestCase
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

    public function testAddsTheIncrementsAcrossInstances(): void
    {
        $path = $this->directory->path('locks');

        self::assertSame([], (new FileMetricsStore($path))->read());
        self::assertTrue((new FileMetricsStore($path))->add(['a{x="1"}' => 2, 'b' => 1]));
        self::assertTrue((new FileMetricsStore($path))->add(['a{x="1"}' => 3]));

        self::assertSame(['a{x="1"}' => 5, 'b' => 1], (new FileMetricsStore($path))->read());
        self::assertSame(0700, fileperms($path) & 0777);
    }

    public function testADamagedFileStartsTheCountersAgain(): void
    {
        $path = $this->directory->path('locks');
        $store = new FileMetricsStore($path);
        self::assertTrue($store->add(['a' => 1]));
        file_put_contents($path . '/' . FileMetricsStore::FILE_NAME, '{"a":"text","b":2.5,"c":4');

        self::assertSame([], $store->read());
        self::assertTrue($store->add(['a' => 1]));
        self::assertSame(['a' => 1], $store->read());
    }

    public function testIgnoresValuesThatAreNotCounters(): void
    {
        $path = $this->directory->path('locks');
        $store = new FileMetricsStore($path);
        self::assertTrue($store->add(['a' => 1]));
        file_put_contents($path . '/' . FileMetricsStore::FILE_NAME, '{"a":"text","b":2.5,"c":4,"0":1}');

        self::assertSame(['c' => 4], $store->read());
    }

    public function testFailsWithoutAUsableDirectory(): void
    {
        $file = $this->directory->path('a-file');
        file_put_contents($file, '');
        $store = new FileMetricsStore($file . '/locks');

        self::assertFalse($store->add(['a' => 1]));
        self::assertNull($store->read());
    }
}
