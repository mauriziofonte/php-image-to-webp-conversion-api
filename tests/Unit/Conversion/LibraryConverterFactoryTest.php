<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Unit\Conversion;

use Mfonte\ImageConversionApi\Conversion\LibraryConverterFactory;
use Mfonte\ImageConversionApi\Tests\Fixtures\Conversion\FixedIsolationDetector;
use Mfonte\ImageConversionApi\Tests\Fixtures\Filesystem\TemporaryDirectory;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\ProcessingBudget;
use Mfonte\SecureImageConverter\Pipeline\SecureConverter;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use Mfonte\SecureImageConverter\Validation\Limits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The converter of a request works inside the request directory and gives
 * up before the request's time limit (carried item: the library's default
 * ceiling of one hour would outlive any web server timeout).
 *
 * @covers \Mfonte\ImageConversionApi\Conversion\LibraryConverterFactory
 */
#[CoversClass(LibraryConverterFactory::class)]
final class LibraryConverterFactoryTest extends TestCase
{
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

    public function testWorksInsideTheRequestDirectory(): void
    {
        mkdir($this->directory->path('request'));
        $link = $this->directory->path('link');
        symlink($this->directory->path('request'), $link);

        $options = $this->create($link, 40.0)->getOptions();

        self::assertSame(realpath($this->directory->path('request')), $options->getWorkingDirectoryParent());
    }

    /**
     * @return array<string, array{float, float, float}>
     */
    public static function provideTimeLimits(): array
    {
        return [
            'above the default floor' => [45.0, ProcessingBudget::DEFAULT_MIN_SECONDS, 45.0],
            'below the default floor' => [12.5, 12.5, 12.5],
            'almost nothing left' => [0.01, LibraryConverterFactory::MIN_CEILING_SECONDS, LibraryConverterFactory::MIN_CEILING_SECONDS],
            'above the library ceiling' => [99999.0, ProcessingBudget::DEFAULT_MIN_SECONDS, ProcessingBudget::DEFAULT_MAX_SECONDS],
        ];
    }

    /**
     * @dataProvider provideTimeLimits
     */
    #[DataProvider('provideTimeLimits')]
    public function testTheBudgetEndsWithinTheTimeLimit(float $timeLimit, float $floor, float $ceiling): void
    {
        $budget = $this->create($this->directory->path(), $timeLimit)->getOptions()->getBudget();

        self::assertSame($floor, $budget->getMinSeconds());
        self::assertSame($ceiling, $budget->getMaxSeconds());
    }

    public function testKeepsTheOtherOptions(): void
    {
        $options = (new ConverterOptions())->withSlotWaitSeconds(3.0)->withMaxOutputBytes(1000)
            ->withBudget((new ProcessingBudget())->withScale(2.0)->withMinSeconds(10.0)->withMaxSeconds(20.0));

        $converter = $this->create($this->directory->path(), 40.0, $options);

        self::assertSame(3.0, $converter->getOptions()->getSlotWaitSeconds());
        self::assertSame(1000, $converter->getOptions()->getMaxOutputBytes());
        self::assertSame(2.0, $converter->getOptions()->getBudget()->getScale());
        self::assertSame(20.0, $converter->getOptions()->getBudget()->getMaxSeconds(), 'a lower configured ceiling stays');
    }

    public function testTheLimitsSetTheLargestInput(): void
    {
        $factory = new LibraryConverterFactory(new SecureConverterBuilder(), (new Limits())->withMaxBytes(1234));

        self::assertSame(1234, $factory->getMaxInputBytes());
    }

    public function testRefusesAMissingDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->create($this->directory->path('missing'), 10.0);
    }

    private function create(string $directory, float $timeLimit, ?ConverterOptions $options = null): SecureConverter
    {
        $builder = (new SecureConverterBuilder())
            ->withIsolationDetector(new FixedIsolationDetector())
            ->withBackends([]);
        $converter = (new LibraryConverterFactory($builder, new Limits(), $options))->create($directory, $timeLimit);
        self::assertInstanceOf(SecureConverter::class, $converter);

        return $converter;
    }
}
