<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Http;

use Mfonte\ImageConversionApi\Exception\RuntimeException;
use Mfonte\ImageConversionApi\Http\RequestDirectories;
use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Http\RequestDirectory
 */
#[CoversClass(RequestDirectory::class)]
final class RequestDirectoryTest extends TestCase
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

    public function testIsCreatedOnTheFirstGetPathOnly(): void
    {
        $request = (new RequestDirectories($this->directory->path()))->forRequest();

        self::assertFalse($request->isCreated());
        self::assertSame([], $this->directory->entries());

        $path = $request->getPath();

        self::assertTrue($request->isCreated());
        self::assertSame($path, $request->getPath());
        self::assertSame([basename($path)], $this->directory->entries());
    }

    public function testRemoveDeletesTheDirectoryAndEndsIt(): void
    {
        $request = (new RequestDirectories($this->directory->path()))->forRequest();
        $path = $request->getPath();
        file_put_contents($path . '/output.webp', 'output');

        self::assertTrue($request->remove());
        self::assertTrue($request->remove());

        self::assertFalse($request->isCreated());
        self::assertSame($path, $request->getCreatedPath());
        self::assertSame([], $this->directory->entries());
        $this->expectException(RuntimeException::class);
        $request->getPath();
    }

    public function testRemovingADirectoryNeverCreatedTouchesNothing(): void
    {
        $request = (new RequestDirectories($this->directory->path('never-created')))->forRequest();

        self::assertTrue($request->remove());

        self::assertNull($request->getCreatedPath());
        self::assertSame([], $this->directory->entries());
    }

    public function testRemoveReportsWhatItCouldNotRemoveAndTriesAgain(): void
    {
        $directories = new RequestDirectories($this->directory->path());
        $request = $directories->forRequest();
        $path = $request->getPath();
        mkdir($path . '/locked');
        touch($path . '/locked/output.webp');
        if (!TemporaryDirectory::makeReadOnly($path . '/locked')) {
            self::markTestSkipped('Directory permissions do not stop root: run the suite as another user');
        }

        self::assertFalse($request->remove());
        self::assertFalse($request->isCreated());
        self::assertSame([$path], $directories->getTracked());

        chmod($path . '/locked', 0700);

        self::assertTrue($request->remove());
        self::assertSame([], $directories->getTracked());
        self::assertSame([], $this->directory->entries());
    }
}
