<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Conversion;

use Mfonte\ImageConversionApi\Conversion\ConverterFactoryInterface;
use Mfonte\SecureImageConverter\Contract\ConverterInterface;

/**
 * Hands out one converter chosen by the test, and records what the
 * endpoint asked for: the working directory and the time limit of every
 * converter it created.
 */
final class ScriptedConverterFactory implements ConverterFactoryInterface
{
    /**
     * @var ConverterInterface
     */
    private $converter;

    /**
     * @var int
     */
    private $maxInputBytes;

    /**
     * @var list<array{workingDirectory: string, timeLimitSeconds: float, existed: bool}>
     */
    private $created = [];

    public function __construct(ConverterInterface $converter, int $maxInputBytes = 1048576)
    {
        $this->converter = $converter;
        $this->maxInputBytes = $maxInputBytes;
    }

    public function create(string $workingDirectory, float $timeLimitSeconds): ConverterInterface
    {
        $this->created[] = [
            'workingDirectory' => $workingDirectory,
            'timeLimitSeconds' => $timeLimitSeconds,
            'existed' => is_dir($workingDirectory),
        ];

        return $this->converter;
    }

    public function getMaxInputBytes(): int
    {
        return $this->maxInputBytes;
    }

    /**
     * @return list<array{workingDirectory: string, timeLimitSeconds: float, existed: bool}>
     */
    public function getCreated(): array
    {
        return $this->created;
    }
}
