<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Functional\Http;

use Mfonte\ImageConversionApi\Http\Kernel;
use Mfonte\ImageConversionApi\Http\RequestDirectories;
use Mfonte\ImageConversionApi\Http\RequestDirectory;
use Mfonte\ImageConversionApi\Http\ResponseFactory;
use Mfonte\ImageConversionApi\Http\Route;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\FakeConverter;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Http\ConvertingHandler;
use Mfonte\ImageConversionApi\Tests\Fixtures\Http\TestKernel;
use Mfonte\ImageConversionApi\Tests\Fixtures\Log\MemoryLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * No file is left in the temporary directory after a request, whether the
 * conversion succeeds, the converter fails, the handler answers an error,
 * throws, or the process ends in a fatal error (finding C-8). The handler
 * stores its input and converts it into its request directory, as the
 * conversion endpoints will, with a fake ConverterInterface.
 *
 * @covers \Mfonte\ImageConversionApi\Http\Kernel
 * @covers \Mfonte\ImageConversionApi\Http\RequestDirectories
 * @covers \Mfonte\ImageConversionApi\Http\RequestDirectory
 */
#[CoversClass(Kernel::class)]
#[CoversClass(RequestDirectories::class)]
#[CoversClass(RequestDirectory::class)]
final class TemporaryFilesTest extends TestCase
{
    private const FILES = ['input.png', 'nested/chunk', 'output.webp'];

    /**
     * @var TemporaryDirectory
     */
    private $directory;

    /**
     * @var MemoryLogger
     */
    private $logger;

    protected function set_up(): void
    {
        parent::set_up();
        $this->directory = new TemporaryDirectory();
        $this->logger = new MemoryLogger();
    }

    protected function tear_down(): void
    {
        $this->directory->remove();
        parent::tear_down();
    }

    public function testASuccessfulConversionLeavesNoFile(): void
    {
        $handler = new ConvertingHandler(new FakeConverter());

        $response = $this->handle($handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(FakeConverter::OUTPUT_PREFIX . 'PNG-BYTES', $response->getContent());
        self::assertSame(self::FILES, $handler->getFiles(), 'the files existed while the request ran');
        self::assertSame([], $this->directory->entries());
    }

    public function testAFailedConversionLeavesNoFile(): void
    {
        $handler = new ConvertingHandler(new FakeConverter(true));

        $response = $this->handle($handler);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(self::FILES, $handler->getFiles(), 'the partial output existed when the converter failed');
        self::assertSame([], $this->directory->entries());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function provideFailures(): array
    {
        return [
            'error response' => [ConvertingHandler::ANSWER_ERROR, 422],
            'exception' => [ConvertingHandler::THROW, 500],
        ];
    }

    /**
     * @dataProvider provideFailures
     */
    #[DataProvider('provideFailures')]
    public function testAFailingHandlerLeavesNoFile(string $outcome, int $status): void
    {
        $handler = new ConvertingHandler(new FakeConverter(), $outcome);

        $response = $this->handle($handler);

        self::assertSame($status, $response->getStatusCode());
        self::assertSame(self::FILES, $handler->getFiles());
        self::assertSame([], $this->directory->entries());
    }

    /**
     * The exception is logged with the route; the client only gets the
     * generic internal error.
     */
    public function testAnExceptionIsLoggedAndAnsweredWithAGeneric500(): void
    {
        $response = $this->handle(new ConvertingHandler(new FakeConverter(), ConvertingHandler::THROW));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            '{"error":{"code":"' . ResponseFactory::ERROR_INTERNAL . '","message":"The request could not be processed."}}',
            $response->getContent()
        );
        $records = $this->logger->getRecords();
        self::assertCount(1, $records);
        self::assertSame('error', $records[0]['level']);
        self::assertSame('probe', $records[0]['context']['route']);
        $exception = $records[0]['context']['exception'];
        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame('The handler failed.', $exception->getMessage());
    }

    /**
     * A fatal error skips the kernel's finally block: the shutdown function
     * of RequestDirectories removes the directory. The probe's own shutdown
     * function, which runs first, proves that the files existed at that
     * point.
     */
    public function testAFatalErrorLeavesNoFile(): void
    {
        $temporaryDirectory = $this->directory->path('temp');
        $report = $this->directory->path('report');
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY,
            '-d',
            'display_errors=stderr',
            dirname(__DIR__, 2) . '/Fixtures/Http/fatal-probe.php',
            $temporaryDirectory,
            $report,
        ]));
        $output = [];
        exec($command . ' 2>&1', $output, $status);

        $process = sprintf("exit status %d, output:\n%s", $status, implode("\n", $output));
        self::assertSame(255, $status, $process);
        self::assertFileExists($report, $process);
        $lines = explode("\n", (string) file_get_contents($report));
        self::assertStringContainsString('ConvertingHandlerFatalProbe', $lines[0], $process);
        $files = array_slice($lines, 1);
        self::assertCount(3, $files, 'the request directory held the files at the fatal error; ' . $process);
        foreach ($files as $index => $file) {
            self::assertMatchesRegularExpression(
                '#\A' . preg_quote(RequestDirectories::PREFIX, '#') . '[0-9a-f]{16}/' . preg_quote(self::FILES[$index], '#') . '\z#',
                $file,
                $process
            );
        }
        self::assertSame([], $this->directory->entries('temp'), 'the request directory is removed; ' . $process);
    }

    private function handle(ConvertingHandler $handler): Response
    {
        $kernel = TestKernel::create([new Route('probe', '/probe', ['POST'], $handler, true)], $this->directory->path(), $this->logger);

        return $kernel->handle(Request::create('https://api.example.test/probe', 'POST', [], [], [], [], 'PNG-BYTES'));
    }
}
