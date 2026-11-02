<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Security;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Mapping\ErrorMapper;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface as LibraryException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Tests\Fixtures\ImageCorpus;
use Mfonte\SecureImageConverter\Tests\Integration\Security\SecurityTestCase;
use Mfonte\SecureImageConverter\Validation\ImageInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The library's security corpus (ImageCorpus: truncated files, bombs,
 * spoofed headers, polyglots, unsupported variants and valid files)
 * posted to POST /v2/convert, under the library's own rules
 * (SecurityTestCase), read through the HTTP answers:
 *
 * - a file the library's inspector refuses, with the API's limits and
 *   formats (the oracle, in this process), is refused with the same code
 *   and its status; an empty file is the API's own 400 missing_image;
 * - a file it accepts is converted (200, an image), or refused with a 4xx
 *   code of SecurityTestCase::REFUSALS; when every backend is there, a
 *   valid file is converted, or refused as SecurityTestCase::KNOWN_REFUSALS
 *   says;
 * - no file gets a 5xx.
 *
 * The files come from the library's tests/ (installed from source, see
 * composer.json autoload-dev); nothing hostile is copied into this
 * repository. Without them, the tests are skipped.
 */
abstract class CorpusTestCase extends TestCase
{
    /**
     * The backends of the reference image: with all of them, the valid
     * files must convert.
     */
    private const EVERY_BACKEND = ['vips', 'cwebp', 'gif2webp', 'avifenc', 'imagick', 'gd'];

    /**
     * The paths only: the bytes of every case (about 14 MB) would stay in
     * memory for the whole run, beyond the 128 MB of a default php.ini on
     * PHPUnit 8.5. Each test builds them again (some 35 ms).
     *
     * @return array<string, array{string}>
     */
    public static function corpus(): array
    {
        if (!class_exists(ImageCorpus::class)) {
            return ['library tests missing' => ['']];
        }
        $cases = [];
        foreach (array_keys(ImageCorpus::cases()) as $path) {
            $cases[$path] = [$path];
        }

        return $cases;
    }

    /**
     * @dataProvider corpus
     */
    #[DataProvider('corpus')]
    public function testEveryFileIsRefusedOrConvertedWithoutA5xx(string $path): void
    {
        if ($path === '') {
            self::markTestSkipped('The library is not installed from source: composer reinstall mfonte/secure-image-converter --prefer-install=source');
        }
        $bytes = ImageCorpus::cases()[$path]['bytes'];
        $capabilities = $this->capabilities();
        // An empty body is no image at all: the API refuses it before the library (400 missing_image).
        $expected = $bytes === '' ? ResponseFactory::ERROR_MISSING_IMAGE : self::oracle($bytes);

        $formats = [ImageFormat::WEBP];
        if ($expected === null && $capabilities->supportsOutput(ImageFormat::AVIF)) {
            $formats[] = ImageFormat::AVIF;
        }
        $failures = [];
        foreach ($formats as $format) {
            list($status, $code) = $this->convert($bytes, $format);
            $problem = self::problem($path, $expected, $status, $code, self::hasEveryBackend($capabilities));
            if ($problem !== null) {
                $failures[] = $format . ': ' . $problem;
            }
        }

        self::assertSame([], $failures, $path);
    }

    /**
     * Posts $bytes as the raw body of POST /v2/convert?format=$format.
     *
     * @return array{int, string|null} the status, and the error code of a JSON error body (null for an image)
     */
    abstract protected function convert(string $bytes, string $format): array;

    /**
     * The capabilities of the converter behind the API.
     */
    abstract protected function capabilities(): Capabilities;

    /**
     * Why the answer is wrong, or null.
     */
    private static function problem(string $path, ?string $expected, int $status, ?string $code, bool $complete): ?string
    {
        $answer = $status . ' ' . ($code ?? 'image');
        if ($status >= 500) {
            return 'a server error: ' . $answer;
        }
        if ($expected !== null) {
            $expectedStatus = $expected === ResponseFactory::ERROR_MISSING_IMAGE ? 400 : (new ErrorMapper())->statusOf($expected);

            return $status === $expectedStatus && $code === $expected
                ? null
                : sprintf('refused by the inspector as %s, answered %s instead of %d %s', $expected, $answer, $expectedStatus, $expected);
        }
        $outcome = $status === 200 && $code === null ? SecurityTestCase::CONVERTED : (string) $code;
        if ($outcome !== SecurityTestCase::CONVERTED && !in_array($outcome, SecurityTestCase::REFUSALS, true)) {
            return 'accepted by the inspector, answered ' . $answer;
        }
        if ($complete && strpos(basename($path), 'valid-') === 0) {
            $known = SecurityTestCase::KNOWN_REFUSALS[$path] ?? null;
            $wanted = $known !== null ? $known[0] : SecurityTestCase::CONVERTED;
            if ($outcome !== $wanted) {
                return sprintf('a valid file ended as %s instead of %s', $outcome, $wanted);
            }
        }

        return null;
    }

    /**
     * The code the library's inspector refuses $bytes with, with the
     * limits and formats of the API; null when it accepts them.
     */
    private static function oracle(string $bytes): ?string
    {
        $inspector = new ImageInspector(KernelFactory::inputLimits(Config::fromArray([])));
        $file = tempnam(sys_get_temp_dir(), 'ica-corpus-');
        self::assertIsString($file);

        try {
            file_put_contents($file, $bytes);
            $inspector->inspect($file);

            return null;
        } catch (LibraryException $exception) {
            return $exception->getErrorCode();
        } finally {
            unlink($file);
        }
    }

    private static function hasEveryBackend(Capabilities $capabilities): bool
    {
        return array_diff(self::EVERY_BACKEND, $capabilities->getBackends()) === [];
    }
}
