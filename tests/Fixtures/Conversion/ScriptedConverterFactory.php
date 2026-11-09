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

    /**
     * @var \Throwable|null
     */
    private $createFailure;

    public function __construct(ConverterInterface $converter, int $maxInputBytes = 1048576)
    {
        $this->converter = $converter;
        $this->maxInputBytes = $maxInputBytes;
    }

    /**
     * Makes create() throw $failure, as the library's builder does when the
     * converter cannot be built.
     */
    public function failToCreate(\Throwable $failure): self
    {
        $this->createFailure = $failure;

        return $this;
    }

    public function create(string $workingDirectory, float $timeLimitSeconds): ConverterInterface
    {
        $this->created[] = [
            'workingDirectory' => $workingDirectory,
            'timeLimitSeconds' => $timeLimitSeconds,
            'existed' => is_dir($workingDirectory),
        ];
        if ($this->createFailure !== null) {
            throw $this->createFailure;
        }

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
