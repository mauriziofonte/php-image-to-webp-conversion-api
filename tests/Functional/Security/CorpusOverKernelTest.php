<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\Security;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Http\Handler\V2ConvertHandler;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Security\CorpusTestCase;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * The security corpus through the kernel as public/index.php builds it,
 * with the real library and the backends of this host (see
 * CorpusTestCase). tests/Integration/SecurityCorpusTest runs the same
 * corpus over HTTP against the image, with every backend.
 *
 * @covers \Mfonte\ImageConversionApi\Http\Handler\V2ConvertHandler
 *
 * @group security
 */
#[CoversClass(V2ConvertHandler::class)]
#[Group('security')]
final class CorpusOverKernelTest extends CorpusTestCase
{
    private const API_KEY = 'corpus-test-key-0123456789';

    /**
     * @var TemporaryDirectory|null
     */
    private $directory;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        if (!$this->capabilities()->supportsOutput(ImageFormat::WEBP)) {
            self::markTestSkipped('No backend of this host writes WEBP.');
        }
    }

    protected function tear_down(): void
    {
        if ($this->directory !== null) {
            self::assertSame([], $this->directory->entries('temp'), 'nothing is left in TEMP_DIR');
            $this->directory->remove();
        }
        parent::tear_down();
    }

    protected function convert(string $bytes, string $format): array
    {
        $response = KernelFactory::create($this->config(), new NullLogger())->handle(Request::create(
            'https://api.example.test/v2/convert?format=' . $format,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_AUTHORIZATION' => 'Bearer ' . self::API_KEY],
            $bytes
        ));
        $data = strpos((string) $response->headers->get('Content-Type'), 'application/json') === 0
            ? json_decode((string) $response->getContent(), true)
            : null;
        $code = is_array($data) && is_array($data['error'] ?? null) && is_string($data['error']['code'] ?? null) ? $data['error']['code'] : null;

        return [$response->getStatusCode(), $code];
    }

    protected function capabilities(): Capabilities
    {
        return KernelFactory::converterBuilder($this->config(), new NullLogger())->build()->getCapabilities();
    }

    private function config(): Config
    {
        self::assertNotNull($this->directory);
        if (!is_dir($this->directory->path('temp'))) {
            mkdir($this->directory->path('temp'));
        }

        return Config::fromArray([
            'TEMP_DIR' => $this->directory->path('temp'),
            'LOCK_DIR' => $this->directory->path('locks'),
            'API_KEY' => self::API_KEY,
            'RATE_LIMIT' => '0',
            'METRICS_STORE' => 'off',
        ]);
    }
}
