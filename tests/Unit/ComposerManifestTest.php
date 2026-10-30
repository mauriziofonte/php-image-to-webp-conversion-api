<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Guards the composer.json invariants that the CI matrix and the other two
 * repositories rely on: PHP range, PSR-4 mapping, runtime and development
 * requirements, stable resolution without a platform override, and the
 * shared Composer script names.
 *
 * @coversNothing
 */
#[CoversNothing]
final class ComposerManifestTest extends TestCase
{
    /**
     * Runtime packages approved by the plan (section 4), plus the conversion
     * library this service exposes. Any other package needs the maintainer's
     * approval first; PHP extensions (ext-*) are always allowed.
     */
    private const APPROVED_RUNTIME_PACKAGES = [
        'mfonte/secure-image-converter',
        'psr/log',
        'symfony/console',
        'symfony/http-foundation',
        'vlucas/phpdotenv',
    ];

    public function testPackageIdentity(): void
    {
        $manifest = self::manifest();

        self::assertSame('mfonte/image-conversion-api', $manifest['name'] ?? null);
        self::assertSame('project', $manifest['type'] ?? null);
        self::assertSame('BSD-3-Clause', $manifest['license'] ?? null);
    }

    public function testPhpRangeIsPhp725ToPhp8(): void
    {
        self::assertSame('^7.2.5 || ^8.0', self::section('require')['php'] ?? null);
    }

    public function testRuntimeRequirementsAreExtensionsOrApprovedPackages(): void
    {
        $unapproved = [];
        foreach (array_keys(self::section('require')) as $package) {
            $package = (string) $package;
            if ($package === 'php' || strpos($package, 'ext-') === 0) {
                continue;
            }
            if (!in_array($package, self::APPROVED_RUNTIME_PACKAGES, true)) {
                $unapproved[] = $package;
            }
        }

        self::assertSame([], $unapproved, 'Runtime dependencies outside the approved list.');
    }

    /**
     * The conversion library is not on Packagist: it is required from its
     * GitHub repository, as the integration branch aliased to 1.0.0 until the
     * library is released, and as ^1.0 from then on (plan section 9).
     */
    public function testConversionLibraryIsRequiredFromItsGitRepository(): void
    {
        self::assertContains(
            self::section('require')['mfonte/secure-image-converter'] ?? null,
            ['dev-claude/project-thread-79fchb as 1.0.0', '^1.0']
        );
        self::assertSame(
            [['type' => 'vcs', 'url' => 'https://github.com/mauriziofonte/php-secure-image-converter']],
            self::manifest()['repositories'] ?? null
        );
    }

    public function testDevRequirementsAreLimitedToPhpUnitAndPolyfills(): void
    {
        $packages = array_keys(self::section('require-dev'));
        sort($packages);

        self::assertSame(['phpunit/phpunit', 'yoast/phpunit-polyfills'], $packages);
    }

    public function testPhpUnitRangeStartsAt85AndIsCappedAt11(): void
    {
        $constraint = self::section('require-dev')['phpunit/phpunit'] ?? null;
        self::assertIsString($constraint);

        $majors = [];
        foreach (explode('||', $constraint) as $alternative) {
            if (preg_match('/^\^(\d+)\.\d+(?:\.\d+)?$/', trim($alternative), $match) !== 1) {
                self::fail(sprintf('Unexpected PHPUnit constraint "%s".', trim($alternative)));
            }
            $majors[] = (int) $match[1];
        }

        self::assertContains(8, $majors, 'PHPUnit 8.5 is the only line that installs on PHP 7.2.');
        self::assertLessThanOrEqual(11, max($majors), 'PHPUnit is capped at 11.');
    }

    public function testPsr4Mapping(): void
    {
        self::assertSame(
            ['Mfonte\\ImageConversionApi\\' => 'src/'],
            self::section('autoload')['psr-4'] ?? null
        );
        self::assertSame(
            ['Mfonte\\ImageConversionApi\\Tests\\' => 'tests/'],
            self::section('autoload-dev')['psr-4'] ?? null
        );
    }

    public function testResolutionIsStableAndNotPinnedToOnePlatform(): void
    {
        $manifest = self::manifest();

        self::assertSame('stable', $manifest['minimum-stability'] ?? null);
        self::assertTrue($manifest['prefer-stable'] ?? null);
        self::assertArrayNotHasKey(
            'platform',
            self::section('config'),
            'config.platform would make every CI job resolve dependencies for the same PHP version.'
        );
    }

    public function testComposerScriptsUseTheSharedNames(): void
    {
        $scripts = self::section('scripts');

        foreach (['test', 'analyse', 'cs:check', 'cs:fix', 'taint', 'ci'] as $name) {
            self::assertArrayHasKey($name, $scripts);
        }
        self::assertSame(['@cs:check', '@analyse', '@test'], $scripts['ci']);
    }

    /**
     * @return array<mixed>
     */
    private static function manifest(): array
    {
        $json = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'composer.json');
        self::assertIsString($json);

        $manifest = json_decode($json, true);
        self::assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        self::assertIsArray($manifest);

        return $manifest;
    }

    /**
     * @return array<mixed>
     */
    private static function section(string $key): array
    {
        $section = self::manifest()[$key] ?? null;
        self::assertIsArray($section, sprintf('composer.json has no "%s" object.', $key));

        return $section;
    }
}
