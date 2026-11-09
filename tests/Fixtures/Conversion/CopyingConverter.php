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
 * "Converts" by copying the input file to the output on disk, without
 * reading it into memory, and reports a WEBP of the same size: outputs as
 * large as the inputs, for the memory tests of the endpoints.
 */
final class CopyingConverter implements ConverterInterface
{
    public function convert(string $inputPath, string $outputPath, ConversionRequest $request): ConversionResult
    {
        $size = @filesize($inputPath);
        if ($size === false || !@copy($inputPath, $outputPath)) {
            throw new ConversionFailedException('The input cannot be copied.');
        }

        return new ConversionResult($outputPath, ImageFormat::WEBP, 1, 1, $size, 1, 'copy', 0, IsolationLevel::NONE, false);
    }

    public function getCapabilities(): Capabilities
    {
        return new Capabilities(
            [ImageFormat::PNG],
            [ImageFormat::WEBP],
            [],
            ['copy'],
            IsolationLevel::NONE,
            array_fill_keys(Capabilities::limitNames(), 1000)
        );
    }
}
