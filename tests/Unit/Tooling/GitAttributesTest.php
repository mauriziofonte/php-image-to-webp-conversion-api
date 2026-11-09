<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Tooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Guards .gitattributes: LF line endings, and development-only paths excluded from the distributed archive.
 *
 * @coversNothing
 */
#[CoversNothing]
final class GitAttributesTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function lines(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/.gitattributes');
        self::assertIsString($contents);

        return array_values(array_filter(array_map('trim', explode("\n", $contents)), static function (string $line): bool {
            return $line !== '' && strpos($line, '#') !== 0;
        }));
    }

    /**
     * @return list<string>
     */
    private static function exportIgnoredPaths(): array
    {
        $paths = [];
        foreach (self::lines() as $line) {
            $parts = preg_split('/\s+/', $line);
            if (is_array($parts) && count($parts) === 2 && $parts[1] === 'export-ignore') {
                $paths[] = $parts[0];
            }
        }

        return $paths;
    }

    public function testLineEndingsAreNormalisedToLf(): void
    {
        self::assertContains('* text=auto eol=lf', self::lines());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function developmentOnlyPaths(): array
    {
        $paths = [
            '/.dockerignore',
            '/.github',
            '/docker',
            '/tests',
            '/tools',
            '/.editorconfig',
            '/.gitattributes',
            '/.gitignore',
            '/.php-cs-fixer.dist.php',
            '/phpstan.neon.dist',
            '/phpstan-php85.neon.dist',
            '/phpunit.xml.dist',
            '/psalm.xml.dist',
        ];

        $cases = [];
        foreach ($paths as $path) {
            $cases[$path] = [$path];
        }

        return $cases;
    }

    /**
     * @dataProvider developmentOnlyPaths
     */
    #[DataProvider('developmentOnlyPaths')]
    public function testDevelopmentOnlyPathIsExportIgnored(string $path): void
    {
        self::assertContains($path, self::exportIgnoredPaths());
    }

    public function testEveryExportIgnoredPathExists(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (self::exportIgnoredPaths() as $path) {
            self::assertTrue(file_exists($root . $path), sprintf('.gitattributes lists "%s", which does not exist', $path));
        }
    }
}
