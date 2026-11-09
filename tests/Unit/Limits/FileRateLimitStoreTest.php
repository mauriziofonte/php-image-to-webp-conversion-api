<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Limits;

use Mfonte\ImageConversionApi\Limits\FileRateLimitStore;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Limits\FileRateLimitStore
 */
#[CoversClass(FileRateLimitStore::class)]
final class FileRateLimitStoreTest extends TestCase
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

    public function testStoresValuesAcrossInstances(): void
    {
        $path = $this->directory->path('locks');

        self::assertTrue((new FileRateLimitStore($path))->update('client', static function (?int $value): int {
            self::assertNull($value);

            return 42;
        }));

        self::assertSame(42, self::read(new FileRateLimitStore($path), 'client'));
        self::assertNull(self::read(new FileRateLimitStore($path), 'other'));
        self::assertSame(0700, fileperms($path) & 0777);
    }

    public function testANullDecisionKeepsTheValue(): void
    {
        $store = new FileRateLimitStore($this->directory->path('locks'));
        $store->update('client', static function (): int {
            return 7;
        });

        $store->update('client', static function (): ?int {
            return null;
        });

        self::assertSame(7, self::read($store, 'client'));
    }

    public function testIgnoresACorruptFile(): void
    {
        $store = new FileRateLimitStore($this->directory->path('locks'));
        $store->update('client', static function (): int {
            return 7;
        });
        file_put_contents($this->directory->path('locks/ratelimit.client.state'), 'garbage');

        self::assertNull(self::read($store, 'client'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideInvalidKeys(): array
    {
        return ['empty' => [''], 'path' => ['../x'], 'too long' => [str_repeat('a', 65)]];
    }

    /**
     * @dataProvider provideInvalidKeys
     */
    #[DataProvider('provideInvalidKeys')]
    public function testRefusesInvalidKeys(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FileRateLimitStore($this->directory->path('locks')))->update($key, static function (): int {
            return 1;
        });
    }

    public function testRefusesADirectoryOthersCanWrite(): void
    {
        mkdir($this->directory->path('shared'));
        chmod($this->directory->path('shared'), 0777);

        $this->expectException(InvalidArgumentException::class);

        (new FileRateLimitStore($this->directory->path('shared')))->update('client', static function (): int {
            return 1;
        });
    }

    /**
     * Four processes increment one value 250 times each: every increment
     * counts, none reads a value another one is about to replace.
     */
    public function testUpdatesAreAtomicAcrossProcesses(): void
    {
        $path = $this->directory->path('locks');
        $processes = [];
        $outputs = [];
        for ($worker = 0; $worker < 4; ++$worker) {
            $command = [PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/Limits/increment-worker.php', $path, 'shared', '250'];
            $process = proc_open(implode(' ', array_map('escapeshellarg', $command)), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as list($process, $pipes)) {
            $outputs[] = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        self::assertSame(['0', '0', '0', '0'], $outputs);
        self::assertSame(1000, self::read(new FileRateLimitStore($path), 'shared'));
    }

    private static function read(FileRateLimitStore $store, string $key): ?int
    {
        $read = null;
        $store->update($key, static function (?int $value) use (&$read): ?int {
            $read = $value;

            return null;
        });

        return $read;
    }
}
