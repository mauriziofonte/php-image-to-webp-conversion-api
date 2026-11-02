<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\V1;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Http\Handler\V1ConvertHandler;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * POST /v1 with the real library, as KernelFactory wires it: a PNG drawn
 * with GD comes back as the base64 of a WEBP, a file that is no image
 * fails alone, and nothing is left in TEMP_DIR. Skipped where no backend
 * of the host writes WEBP (the PHP images of the local Docker runs have
 * no GD).
 *
 * @covers \Mfonte\ImageConversionApi\Http\Handler\V1ConvertHandler
 *
 * @group contract
 */
#[CoversClass(V1ConvertHandler::class)]
#[Group('contract')]
final class V1LibraryConversionTest extends TestCase
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
        mkdir($this->directory->path('temp'));
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
        self::assertNotNull($this->directory);
        $png = $this->directory->path('photo.png');
        file_put_contents($png, self::png(256, 192));
        $text = $this->directory->path('fake.png');
        file_put_contents($text, '%PDF-1.7 not an image');
        $kernel = KernelFactory::create(Config::fromArray([
            'TEMP_DIR' => $this->directory->path('temp'),
            'LOCK_DIR' => $this->directory->path('locks'),
            'API_KEY' => self::API_KEY,
            'V1_SCRIPT_NAME' => 'convert-now',
        ]), new MemoryLogger());

        $response = $kernel->handle(Request::create('https://api.example.test/v1/convert-now?m=6', 'POST', [], [], ['images' => [
            new UploadedFile($png, 'photo.png', 'image/png', UPLOAD_ERR_OK, true),
            new UploadedFile($text, 'fake.png', 'image/png', UPLOAD_ERR_OK, true),
        ]], ['CONTENT_TYPE' => 'multipart/form-data; boundary=----v1', 'HTTP_X_API_KEY' => self::API_KEY]));
        ob_start();
        $response->sendContent();
        $document = json_decode((string) ob_get_clean(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($document);
        self::assertIsArray($document['response'] ?? null);
        [$photo, $fake] = $document['response'];
        self::assertIsArray($photo);
        self::assertIsString($photo['webp_image_base64'] ?? null, (string) json_encode($photo));
        self::assertTrue($photo['status']);
        $webp = (string) base64_decode($photo['webp_image_base64'], true);
        self::assertSame('RIFF', substr($webp, 0, 4));
        self::assertSame('WEBP', substr($webp, 8, 4));
        self::assertGreaterThan(0, $photo['compression_ratio']);
        self::assertSame(['filename' => 'fake.png', 'status' => false, 'error' => 'The image format is not supported.'], $fake);
        self::assertSame([], $this->directory->entries('temp'), 'neither the request directory nor a working directory is left');
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        // A photo-like gradient with noise, which lossy WEBP stores in fewer bytes than PNG.
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $noise = crc32($x . ',' . $y) % 23;
                imagesetpixel($image, $x, $y, ($x + $noise) % 256 << 16 | ($y + $noise) % 256 << 8 | (intdiv($x + $y, 2) + $noise) % 256);
            }
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
