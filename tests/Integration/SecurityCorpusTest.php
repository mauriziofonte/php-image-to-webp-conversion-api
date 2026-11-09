<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Integration;

use Mfonte\ImageConversionApi\Tests\Fixtures\Http\HttpClient;
use Mfonte\ImageConversionApi\Tests\Fixtures\Security\CorpusTestCase;
use Mfonte\SecureImageConverter\Contract\Capabilities;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;

/**
 * The security corpus over HTTP, through nginx, against the image of
 * docker/Dockerfile with every backend (see CorpusTestCase).
 *
 * Skipped unless run.sh passes ICA_INTEGRATION_IMAGE_URL.
 *
 * @coversNothing
 *
 * @group integration
 * @group security
 */
#[CoversNothing]
#[Group('integration')]
#[Group('security')]
final class SecurityCorpusTest extends CorpusTestCase
{
    /**
     * @var string
     */
    private $url = '';

    protected function set_up(): void
    {
        parent::set_up();
        $url = getenv('ICA_INTEGRATION_IMAGE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Run through tests/Integration/docker/run.sh --mode image.');
        }
        $this->url = $url;
    }

    protected function convert(string $bytes, string $format): array
    {
        $response = $this->send('POST', '/v2/convert?format=' . $format, ['Content-Type' => 'application/octet-stream'], $bytes);
        $data = strpos(($response['headers']['content-type'] ?? [''])[0], 'application/json') === 0 ? json_decode($response['body'], true) : null;
        $code = is_array($data) && is_array($data['error'] ?? null) && is_string($data['error']['code'] ?? null) ? $data['error']['code'] : null;

        return [$response['status'], $code];
    }

    protected function capabilities(): Capabilities
    {
        $data = json_decode($this->send('GET', '/healthz')['body'], true);
        self::assertIsArray($data);
        self::assertIsArray($data['capabilities'] ?? null);

        return Capabilities::fromArray($data['capabilities']);
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
