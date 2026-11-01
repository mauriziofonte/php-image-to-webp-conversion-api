<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\V1;

use Mfonte\ImageConversionApi\Config\Config;
use Mfonte\ImageConversionApi\Http\Handler\V1ConvertHandler;
use Mfonte\ImageConversionApi\Http\KernelFactory;
use Mfonte\ImageConversionApi\Mapping\V1RequestMapper;
use Mfonte\ImageConversionApi\Mapping\V1ResponseFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\ScriptedConverter;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\ScriptedConverterFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Log\MemoryLogger;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * POST /v1 answers the documents of 1.x byte for byte: every request of
 * tests/Fixtures/V1/cases.php is sent to 2.x, with a scripted converter
 * that writes what the fake cwebp of generate-golden.php wrote, and the
 * body must equal the golden file the 1.x process.php produced, but for
 * elapsed_time. The statuses differ where 2.x refuses the whole request:
 * 1.x answered 200, 2.x answers 400.
 *
 * @covers \Mfonte\ImageConversionApi\Http\Handler\V1ConvertHandler
 * @covers \Mfonte\ImageConversionApi\Mapping\V1RequestMapper
 * @covers \Mfonte\ImageConversionApi\Mapping\V1ResponseFactory
 *
 * @group contract
 */
#[CoversClass(V1ConvertHandler::class)]
#[CoversClass(V1RequestMapper::class)]
#[CoversClass(V1ResponseFactory::class)]
#[Group('contract')]
final class V1GoldenTest extends TestCase
{
    private const API_KEY = 'golden-test-key-0123456789';

    private const SCRIPT_NAME = 'convert-now';

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

    /**
     * @return array<string, array{string}>
     */
    public static function provideCases(): array
    {
        $cases = [];
        foreach (array_keys(self::fixture()['cases']) as $name) {
            $cases[$name] = [$name];
        }

        return $cases;
    }

    /**
     * @dataProvider provideCases
     */
    #[DataProvider('provideCases')]
    public function testAnswersTheGoldenDocument(string $name): void
    {
        $fixture = self::fixture();
        $case = $fixture['cases'][$name];
        $index = json_decode((string) file_get_contents(self::golden('index.json')), true);
        self::assertIsArray($index);
        self::assertSame(['status' => 200, 'content_type' => V1ResponseFactory::CONTENT_TYPE], $index[$name] ?? null, '1.x answered 200');

        $converter = ScriptedConverter::failingOn($fixture['needle'], new ConversionFailedException('cwebp failed'), $fixture['output']);
        $response = $this->send(new ScriptedConverterFactory($converter, 4194304), $case);

        self::assertSame($case['status'], $response->getStatusCode());
        self::assertSame(V1ResponseFactory::CONTENT_TYPE, $response->headers->get('Content-Type'));
        $body = self::body($response);
        self::assertSame(
            self::withoutElapsedTime((string) file_get_contents(self::golden($name . '.json'))),
            self::withoutElapsedTime($body)
        );
        if ($response->headers->has('Content-Length')) {
            self::assertSame((string) strlen($body), $response->headers->get('Content-Length'));
        }
        self::assertSame([], $this->directory->entries(), 'the request directory is removed');
    }

    /**
     * The library is asked for what cwebp was asked: the method m as the
     * effort that maps to it, lossless, at cwebp's default quality.
     */
    public function testConvertsWithTheSwitchesOf1x(): void
    {
        $fixture = self::fixture();
        $arguments = json_decode((string) file_get_contents(self::golden('cwebp-arguments.json')), true);
        self::assertIsArray($arguments);
        $expected = [
            'batch' => ['-quiet -pass 3 -m 6 -lossless -hint photo', 9, true],
            'per-file-failures' => ['-quiet', 6, false],
            'single-file-defaults' => ['-quiet -m 4', 6, false],
        ];

        foreach ($expected as $name => [$switches, $effort, $lossless]) {
            $converter = ScriptedConverter::failingOn($fixture['needle'], new ConversionFailedException('cwebp failed'), $fixture['output']);
            $this->send(new ScriptedConverterFactory($converter, 4194304), $fixture['cases'][$name]);

            self::assertIsArray($arguments[$name] ?? null);
            self::assertNotSame([], $converter->getCalls());
            self::assertCount(count($arguments[$name]), $converter->getCalls(), $name . ': one conversion per cwebp run');
            foreach ($converter->getCalls() as $call) {
                self::assertSame($switches, $arguments[$name][0]);
                self::assertSame('webp', $call['request']->getFormat());
                self::assertSame($effort, $call['request']->getEffort(), $name);
                self::assertSame($lossless, $call['request']->isLossless(), $name);
                self::assertSame(V1RequestMapper::QUALITY, $call['request']->getQuality());
            }
        }
    }

    /**
     * @param array{query: string, fields: array<string, string>, files: list<array{0: string, 1: string|int}>, status: int} $case
     */
    private function send(ScriptedConverterFactory $factory, array $case): Response
    {
        $files = [];
        foreach ($case['files'] as $index => $file) {
            $path = $this->directory->path('upload-' . $index);
            file_put_contents($path, is_int($file[1]) ? '' : $file[1]);
            $files[] = new UploadedFile($path, $file[0], 'application/octet-stream', is_int($file[1]) ? $file[1] : UPLOAD_ERR_OK, true);
        }
        $request = Request::create(
            'https://api.example.test/v1/' . self::SCRIPT_NAME . $case['query'],
            'POST',
            $case['fields'],
            [],
            $files === [] ? [] : [V1RequestMapper::FIELD_IMAGES => $files],
            ['CONTENT_TYPE' => 'multipart/form-data; boundary=----golden', 'HTTP_X_API_KEY' => self::API_KEY]
        );
        $config = Config::fromArray([
            'TEMP_DIR' => $this->directory->path('temp'),
            'API_KEY' => self::API_KEY,
            'V1_SCRIPT_NAME' => self::SCRIPT_NAME,
            'RATE_LIMIT' => '0',
        ]);
        mkdir($this->directory->path('temp'));
        $response = KernelFactory::create($config, new MemoryLogger(), $factory)->handle($request);
        foreach ($case['files'] as $index => $file) {
            @unlink($this->directory->path('upload-' . $index));
        }
        rmdir($this->directory->path('temp'));

        return $response;
    }

    private static function body(Response $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private static function withoutElapsedTime(string $body): string
    {
        return (string) preg_replace('/"elapsed_time":"\d+(?:\.\d{1,2})?s"/', '"elapsed_time":"<elapsed>s"', $body, 1);
    }

    private static function golden(string $file): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/V1/golden/' . $file;
    }

    /**
     * @return array{output: string, needle: string, cases: array<string, array{query: string, fields: array<string, string>, files: list<array{0: string, 1: string|int}>, status: int}>}
     */
    private static function fixture(): array
    {
        /** @var array{output: string, needle: string, cases: array<string, array{query: string, fields: array<string, string>, files: list<array{0: string, 1: string|int}>, status: int}>} */
        return require dirname(__DIR__, 2) . '/Fixtures/V1/cases.php';
    }
}
