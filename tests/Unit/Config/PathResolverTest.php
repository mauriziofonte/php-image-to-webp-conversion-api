<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Config;

use Mfonte\ImageConversionApi\Config\PathResolver;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \Mfonte\ImageConversionApi\Config\PathResolver
 */
#[CoversClass(PathResolver::class)]
final class PathResolverTest extends TestCase
{
    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var string the directory with its symbolic links resolved
     */
    private $real;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        mkdir($this->directory->path('public/assets'), 0777, true);
        mkdir($this->directory->path('var'));
        $this->real = (string) realpath($this->directory->path());
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testResolvesExistingAndMissingParts(): void
    {
        self::assertSame($this->real . '/public', PathResolver::resolve($this->directory->path('public')));
        self::assertSame($this->real . '/var/tmp/requests', PathResolver::resolve($this->directory->path('var//tmp/./requests/')));
        self::assertSame($this->real . '/var', PathResolver::resolve($this->directory->path('public/../var')));
        self::assertSame($this->real . '/var', PathResolver::resolve($this->directory->path('missing/../var')));
        self::assertSame('/', PathResolver::resolve('/'));
        self::assertSame('/', PathResolver::resolve('/../..'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideCandidates(): array
    {
        return [
            'the web root itself' => ['public', true],
            'an existing directory inside' => ['public/assets', true],
            'a missing directory inside' => ['public/uploads/tmp', true],
            'inside through ".."' => ['var/../public/tmp', true],
            'a sibling' => ['var', false],
            'a sibling sharing the prefix' => ['public-tmp', false],
            'outside through ".."' => ['public/../var/tmp', false],
            'the parent' => ['', false],
        ];
    }

    /**
     * @dataProvider provideCandidates
     */
    #[DataProvider('provideCandidates')]
    public function testIsInside(string $candidate, bool $inside): void
    {
        self::assertSame($inside, PathResolver::isInside($this->directory->path($candidate), $this->directory->path('public')));
    }

    public function testFollowsSymbolicLinks(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('symlink() is not available');
        }
        symlink($this->directory->path('public'), $this->directory->path('var/link'));

        self::assertTrue(PathResolver::isInside($this->directory->path('var/link/tmp'), $this->directory->path('public')));
        self::assertTrue(PathResolver::isInside($this->directory->path('public/assets'), $this->directory->path('var/link')));
    }

    public function testEverythingIsInsideTheRoot(): void
    {
        self::assertTrue(PathResolver::isInside($this->directory->path('var'), '/'));
    }
}
