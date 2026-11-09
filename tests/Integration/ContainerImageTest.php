<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Integration;

use Mfonte\ImageConversionApi\Tests\Fixtures\Http\HttpClient;
use Mfonte\ImageConversionApi\Tests\Fixtures\Image\Png;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Real conversions through docker/compose.yaml: the image of
 * docker/Dockerfile (non-root, read-only root filesystem, no capability,
 * no privilege escalation) with its backends, behind nginx with
 * deploy/nginx. run.sh checks the hardening of the running container.
 *
 * Skipped unless run.sh passes ICA_INTEGRATION_IMAGE_URL.
 *
 * @coversNothing
 *
 * @group integration
 */
#[CoversNothing]
#[Group('integration')]
final class ContainerImageTest extends TestCase
{
    /**
     * @var string
     */
    private $url;

    protected function set_up(): void
    {
        parent::set_up();
        $url = getenv('ICA_INTEGRATION_IMAGE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Run through tests/Integration/docker/run.sh --mode image.');
        }
        $this->url = $url;
    }

    public function testPublishesTheBackendsOfTheImage(): void
    {
        $data = json_decode($this->send('GET', '/healthz')['body'], true);

        self::assertIsArray($data);
        self::assertIsArray($data['capabilities'] ?? null);
        $capabilities = Capabilities::fromArray($data['capabilities']);
        foreach (['vips', 'cwebp', 'gif2webp', 'avifenc', 'imagick', 'gd'] as $backend) {
            self::assertContains($backend, $capabilities->getBackends(), $backend);
        }
        self::assertContains($capabilities->getIsolationLevel(), [IsolationLevel::RLIMIT, IsolationLevel::SANDBOX]);
        self::assertTrue($capabilities->supportsOutput(ImageFormat::AVIF));
    }

    public function testConvertsAPngToWebp(): void
    {
        $response = $this->send('POST', '/v2/convert?width=32', ['Content-Type' => 'image/png'], Png::gradient(64, 48));

        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame(['image/webp'], $response['headers']['content-type'] ?? null);
        self::assertSame('RIFF', substr($response['body'], 0, 4));
        self::assertSame('WEBP', substr($response['body'], 8, 4));
        self::assertSame(['32'], $response['headers']['x-conversion-width'] ?? null);
        self::assertSame(['24'], $response['headers']['x-conversion-height'] ?? null);
        self::assertContains(($response['headers']['x-conversion-isolation'] ?? [''])[0], ['rlimit', 'sandbox']);
    }

    public function testConvertsAPngToAvif(): void
    {
        list($body, $type) = HttpClient::multipart(['format' => 'avif'], [['field' => 'image', 'name' => 'a.png', 'type' => 'image/png', 'content' => Png::gradient(96, 64)]]);

        $response = $this->send('POST', '/v2/convert', ['Content-Type' => $type], $body);

        self::assertSame(200, $response['status'], $response['body']);
        self::assertSame(['avif'], $response['headers']['x-conversion-format'] ?? null, (string) json_encode($response['headers']));
        self::assertSame('ftyp', substr($response['body'], 4, 4));
    }

    public function testRefusesWhatIsNoImageWithoutA5xx(): void
    {
        $cases = [
            'text' => 'just some text',
            'truncated png' => substr(Png::gradient(64, 64), 0, 60),
            'pdf' => "%PDF-1.7\n1 0 obj << >> endobj\n",
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        ];
        foreach ($cases as $name => $content) {
            $response = $this->send('POST', '/v2/convert', ['Content-Type' => 'application/octet-stream'], $content);

            self::assertContains($response['status'], [415, 422], $name . ': ' . $response['body']);
        }
    }

    public function testCountsTheConversions(): void
    {
        $this->send('POST', '/v2/convert', ['Content-Type' => 'image/png'], Png::gradient(16, 16));

        $metrics = $this->send('GET', '/metrics')['body'];

        self::assertMatchesRegularExpression('/^image_conversion_api_conversions_total\{route="v2_convert",outcome="converted"\} [1-9]/m', $metrics);
        self::assertStringContainsString("image_conversion_api_metrics_available 1\n", $metrics);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    private function send(string $method, string $path, array $headers = [], string $body = ''): array
    {
        $caFile = getenv('ICA_INTEGRATION_CA_FILE');
        $serverName = getenv('ICA_INTEGRATION_SERVER_NAME');
        $key = getenv('ICA_INTEGRATION_API_KEY');
        $client = new HttpClient(is_string($caFile) && $caFile !== '' ? $caFile : null, is_string($serverName) && $serverName !== '' ? $serverName : null);

        return $client->request($method, $this->url . $path, $headers + ['Authorization' => 'Bearer ' . (is_string($key) ? $key : '')], $body);
    }
}
