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
        file_put_contents($request->getPath() . '/output.webp', 'output');

        $request->remove();
        $request->remove();

        self::assertFalse($request->isCreated());
        self::assertSame([], $this->directory->entries());
        $this->expectException(RuntimeException::class);
        $request->getPath();
    }

    public function testRemovingADirectoryNeverCreatedTouchesNothing(): void
    {
        $request = (new RequestDirectories($this->directory->path('never-created')))->forRequest();

        $request->remove();

        self::assertSame([], $this->directory->entries());
    }
}
