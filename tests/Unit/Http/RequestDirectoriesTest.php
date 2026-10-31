<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Http;

use Mfonte\ImageConversionApi\Exception\RuntimeException;
use Mfonte\ImageConversionApi\Http\RequestDirectories;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Http\RequestDirectories
 */
#[CoversClass(RequestDirectories::class)]
final class RequestDirectoriesTest extends TestCase
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

    public function testCreatesPrivateDirectoriesWithRandomNamesUnderTheParent(): void
    {
        $directories = new RequestDirectories($this->directory->path());

        $first = $directories->create();
        $second = $directories->create();

        self::assertNotSame($first, $second);
        self::assertSame($this->directory->path(), dirname($first));
        self::assertMatchesRegularExpression('/\A' . preg_quote(RequestDirectories::PREFIX, '/') . '[0-9a-f]{16}\z/', basename($first));
        self::assertDirectoryExists($first);
        self::assertSame([], $this->directory->entries(basename($first)));
        self::assertSame(0700, fileperms($first) & 0777);
        self::assertSame([$first, $second], $directories->getTracked());
    }

    public function testCreatesAMissingParent(): void
    {
        $parent = $this->directory->path('missing/parent');
        $directories = new RequestDirectories($parent . '/');

        self::assertSame($parent, $directories->getParent());
        self::assertSame($parent, dirname($directories->create()));
        self::assertSame(0700, fileperms($parent) & 0777);
    }

    public function testRefusesAParentThatIsAFile(): void
    {
        $file = $this->directory->path('file');
        touch($file);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create the temporary directory ' . $file);

        (new RequestDirectories($file))->create();
    }

    public function testRemovesADirectoryWithEverythingInside(): void
    {
        $directories = new RequestDirectories($this->directory->path());
        $path = $directories->create();
        mkdir($path . '/nested/deeper', 0700, true);
        file_put_contents($path . '/input.png', 'input');
        file_put_contents($path . '/nested/deeper/output.webp', 'output');

        self::assertTrue($directories->remove($path));

        self::assertDirectoryDoesNotExist($path);
        self::assertSame([], $this->directory->entries());
        self::assertSame([], $directories->getTracked());
    }

    /**
     * A directory that cannot be removed entirely is reported, quietly,
     * and stays tracked, so that removeAll() tries again at shutdown.
     */
    public function testKeepsTrackingADirectoryItCannotRemove(): void
    {
        $directories = new RequestDirectories($this->directory->path());
        $path = $directories->create();
        mkdir($path . '/locked');
        file_put_contents($path . '/locked/output.webp', 'output');
        file_put_contents($path . '/input.png', 'input');
        if (!TemporaryDirectory::makeReadOnly($path . '/locked')) {
            self::markTestSkipped('Directory permissions do not stop root: run the suite as another user');
        }

        // Under the strict error handler of the suite, a warning that is not silenced would throw.
        $removed = $directories->remove($path);
        $leftAtShutdown = $directories->removeAll();

        self::assertFalse($removed);
        self::assertSame(['output.webp'], $this->directory->entries(basename($path) . '/locked'));
        self::assertSame(['locked'], $this->directory->entries(basename($path)), 'what could be removed is removed');
        self::assertSame([$path], $directories->getTracked());
        self::assertSame([$path], $leftAtShutdown);

        chmod($path . '/locked', 0700);

        self::assertTrue($directories->remove($path));
        self::assertSame([], $this->directory->entries());
        self::assertSame([], $directories->getTracked());
    }

    public function testRemovesSymbolicLinksWithoutFollowingThem(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink() is not available');
        }
        $outside = $this->directory->path('outside');
        mkdir($outside);
        file_put_contents($outside . '/keep', 'keep');
        $directories = new RequestDirectories($this->directory->path('parent'));
        $path = $directories->create();
        symlink($outside, $path . '/link-to-directory');
        symlink($outside . '/keep', $path . '/link-to-file');

        $directories->remove($path);

        self::assertDirectoryDoesNotExist($path);
        self::assertSame(['keep'], $this->directory->entries('outside'));
    }

    public function testRemovingIsIdempotentAndQuiet(): void
    {
        $directories = new RequestDirectories($this->directory->path());
        $path = $directories->create();
        $raised = [];
        set_error_handler(static function (int $level, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            self::assertTrue($directories->remove($path));
            self::assertTrue($directories->remove($path));
            self::assertSame([], $directories->removeAll());
        } finally {
            restore_error_handler();
        }

        self::assertDirectoryDoesNotExist($path);
        self::assertSame([], $raised);
    }

    public function testNeverRemovesAPathItDidNotCreate(): void
    {
        $foreign = $this->directory->path(RequestDirectories::PREFIX . 'foreign');
        mkdir($foreign);
        $directories = new RequestDirectories($this->directory->path());

        self::assertTrue($directories->remove($foreign), 'nothing of this object is left there');
        self::assertSame([], $directories->removeAll());

        self::assertDirectoryExists($foreign);
    }

    /**
     * The shutdown function: what a fatal error left behind is removed, and
     * what the kernel already removed is not touched again.
     */
    public function testRemoveAllRemovesWhatIsStillTracked(): void
    {
        $directories = new RequestDirectories($this->directory->path());
        $removed = $directories->create();
        $leftOver = $directories->create();
        file_put_contents($leftOver . '/output.webp', 'output');
        $directories->remove($removed);

        self::assertSame([], $directories->removeAll());

        self::assertSame([], $this->directory->entries());
        self::assertSame([], $directories->getTracked());
    }
}
