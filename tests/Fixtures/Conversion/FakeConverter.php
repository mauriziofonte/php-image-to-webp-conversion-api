<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Conversion;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\ConverterInterface;
use Mfonte\SecureImageConverter\Contract\Exception\ConversionFailedException;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;

/**
 * Stands in for the library's converter, which the API's routing and
 * authentication do not need: it "converts" by copying the input to the
 * output with a "WEBP" prefix, or, when built to fail, writes a partial
 * output and throws ConversionFailedException, as a crashing backend would
 * before the converter cleans up.
 */
final class FakeConverter implements ConverterInterface
{
    public const OUTPUT_PREFIX = 'WEBP:';

    /**
     * @var bool
     */
    private $fails;

    /**
     * @var list<string> every output path passed to convert()
     */
    private $outputs = [];

    public function __construct(bool $fails = false)
    {
        $this->fails = $fails;
    }

    public function convert(string $inputPath, string $outputPath, ConversionRequest $request): ConversionResult
    {
        $this->outputs[] = $outputPath;
        $input = file_get_contents($inputPath);
        if ($input === false) {
            throw new ConversionFailedException('The input cannot be read.');
        }
        if ($this->fails) {
            file_put_contents($outputPath, substr(self::OUTPUT_PREFIX, 0, 2));

            throw new ConversionFailedException('The fake backend failed.');
        }
        $output = self::OUTPUT_PREFIX . $input;
        file_put_contents($outputPath, $output);

        return new ConversionResult($outputPath, ImageFormat::WEBP, 1, 1, strlen($output), 1, 'fake', 0, IsolationLevel::NONE, false);
    }

    public function getCapabilities(): Capabilities
    {
        return new Capabilities(
            [ImageFormat::PNG],
            [ImageFormat::WEBP],
            [],
            ['fake'],
            IsolationLevel::NONE,
            array_fill_keys(Capabilities::limitNames(), 1000)
        );
    }

    /**
     * @return list<string>
     */
    public function getOutputs(): array
    {
        return $this->outputs;
    }
}
