<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Conversion;

use Mfonte\SecureImageConverter\Contract\Capabilities;
use Mfonte\SecureImageConverter\Contract\ConversionRequest;
use Mfonte\SecureImageConverter\Contract\ConversionResult;
use Mfonte\SecureImageConverter\Contract\ConverterInterface;
use Mfonte\SecureImageConverter\Contract\ImageFormat;
use Mfonte\SecureImageConverter\Contract\IsolationLevel;

/**
 * A converter whose outcome a test chooses: it writes fixed bytes to the
 * output and returns a result described by the test, or throws the
 * exception the test gave it (for every input, or for the inputs that
 * contain a needle). It records every call, with the input bytes
 * it was given, so that tests can check what the endpoint passed on.
 */
final class ScriptedConverter implements ConverterInterface
{
    /**
     * @var string
     */
    private $output;

    /**
     * @var array<string, mixed>
     */
    private $result;

    /**
     * @var \Throwable|null
     */
    private $failure;

    /**
     * @var string|null
     */
    private $needle;

    /**
     * @var list<array{input: string, inputPath: string, outputPath: string, request: ConversionRequest}>
     */
    private $calls = [];

    /**
     * @param string               $output bytes written to the output
     * @param array<string, mixed> $result overrides of the result: format, width, height, frames, backend,
     *                                     duration, isolation, passthrough, warnings
     */
    private function __construct(string $output, array $result, ?\Throwable $failure, ?string $needle = null)
    {
        $this->output = $output;
        $this->result = $result;
        $this->failure = $failure;
        $this->needle = $needle;
    }

    /**
     * @param array<string, mixed> $result see the constructor
     */
    public static function succeeding(string $output, array $result = []): self
    {
        return new self($output, $result, null);
    }

    public static function failing(\Throwable $failure): self
    {
        return new self('', [], $failure);
    }

    /**
     * Succeeds as succeeding() does, but throws $failure for an input that
     * contains $needle.
     *
     * @param array<string, mixed> $result see the constructor
     */
    public static function failingOn(string $needle, \Throwable $failure, string $output, array $result = []): self
    {
        return new self($output, $result, $failure, $needle);
    }

    public function convert(string $inputPath, string $outputPath, ConversionRequest $request): ConversionResult
    {
        $this->calls[] = [
            'input' => (string) file_get_contents($inputPath),
            'inputPath' => $inputPath,
            'outputPath' => $outputPath,
            'request' => $request,
        ];
        if ($this->failure !== null && ($this->needle === null || strpos($this->calls[count($this->calls) - 1]['input'], $this->needle) !== false)) {
            throw $this->failure;
        }
        file_put_contents($outputPath, $this->output);

        $result = $this->result + [
            'format' => ImageFormat::WEBP,
            'width' => 640,
            'height' => 480,
            'frames' => 1,
            'backend' => 'cwebp',
            'duration' => 12,
            'isolation' => IsolationLevel::SANDBOX,
            'passthrough' => false,
            'warnings' => [],
        ];

        return new ConversionResult(
            $outputPath,
            self::string($result['format']),
            self::int($result['width']),
            self::int($result['height']),
            strlen($this->output),
            self::int($result['frames']),
            self::string($result['backend']),
            self::int($result['duration']),
            self::string($result['isolation']),
            $result['passthrough'] === true,
            array_values(array_map([self::class, 'string'], (array) $result['warnings']))
        );
    }

    public function getCapabilities(): Capabilities
    {
        return new Capabilities(
            [ImageFormat::PNG, ImageFormat::JPEG],
            [ImageFormat::WEBP],
            [],
            ['cwebp'],
            IsolationLevel::SANDBOX,
            array_fill_keys(Capabilities::limitNames(), 100000)
        );
    }

    /**
     * @return list<array{input: string, inputPath: string, outputPath: string, request: ConversionRequest}>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * @param mixed $value
     */
    private static function string($value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param mixed $value
     */
    private static function int($value): int
    {
        return is_int($value) ? $value : 0;
    }
}
