<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\Contract;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Conversion\LibraryConverterFactory;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * POST /v2/convert with the real library, as KernelFactory wires it for
 * production: a PNG drawn with GD becomes a WEBP whose headers describe
 * it, a file that is no image is refused with 415, and nothing is left in
 * TEMP_DIR (the library's working directories live in the request
 * directory). Skipped where no backend of the host writes WEBP (the PHP
 * images of the local Docker runs have no GD).
 *
 * @covers \Mfonte\ImageConversionApi\Conversion\LibraryConverterFactory
 * @covers \Mfonte\ImageConversionApi\Http\KernelFactory
 *
 * @group contract
 */
#[CoversClass(LibraryConverterFactory::class)]
#[CoversClass(KernelFactory::class)]
#[Group('contract')]
final class V2LibraryConversionTest extends TestCase
{
    private const API_KEY = 'library-test-key-0123456789';

    /**
     * @var TemporaryDirectory|null
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        if (!extension_loaded('gd') || !function_exists('imagepng')) {
            self::markTestSkipped('The gd extension is needed to draw the input image.');
        }
        if (!(new SecureConverterBuilder())->build()->getCapabilities()->supportsOutput(ImageFormat::WEBP)) {
            self::markTestSkipped('No backend of this host writes WEBP.');
        }
        $this->directory = new TemporaryDirectory();
    }

    protected function tear_down(): void
    {
        if ($this->directory !== null) {
            $this->directory->remove();
        }
        parent::tear_down();
    }

    public function testConvertsAPngToWebp(): void
    {
        $response = $this->send(self::png(64, 48), '?width=32');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = self::body($response);
        self::assertSame('RIFF', substr($body, 0, 4));
        self::assertSame('WEBP', substr($body, 8, 4));
        self::assertSame('image/webp', $response->headers->get('Content-Type'));
        self::assertSame((string) strlen($body), $response->headers->get('Content-Length'));
        self::assertSame('webp', $response->headers->get('X-Conversion-Format'));
        self::assertSame('32', $response->headers->get('X-Conversion-Width'));
        self::assertSame('24', $response->headers->get('X-Conversion-Height'));
        self::assertSame('0', $response->headers->get('X-Conversion-Original-Kept'));
        self::assertNotSame('', (string) $response->headers->get('X-Conversion-Backend'));
        self::assertNotNull($this->directory);
        self::assertSame([], $this->directory->entries(), 'neither the request directory nor a working directory is left');
    }

    public function testRefusesAFileThatIsNoImage(): void
    {
        $response = $this->send('%PDF-1.7 not an image', '');

        self::assertSame(415, $response->getStatusCode());
        self::assertStringContainsString('"code":"unsupported_format"', (string) $response->getContent());
        self::assertNotNull($this->directory);
        self::assertSame([], $this->directory->entries());
    }

    private function send(string $body, string $query): Response
    {
        self::assertNotNull($this->directory);
        $kernel = KernelFactory::create(
            Config::fromArray(['TEMP_DIR' => $this->directory->path(), 'API_KEY' => self::API_KEY]),
            new MemoryLogger()
        );

        return $kernel->handle(Request::create(
            'https://api.example.test/v2/convert' . $query,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_AUTHORIZATION' => 'Bearer ' . self::API_KEY],
            $body
        ));
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        for ($x = 0; $x < $width; ++$x) {
            imageline($image, $x, 0, $x, $height - 1, (int) imagecolorallocate($image, $x * 4 % 256, 80, 160));
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
