<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Limits;

use Mfonte\ImageConversionApi\Limits\RateLimitStoreInterface;

/**
 * Keeps the values in an array, or fails every update when built to.
 */
final class MemoryRateLimitStore implements RateLimitStoreInterface
{
    /**
     * @var array<string, int>
     */
    private $values = [];

    /**
     * @var bool
     */
    private $fails;

    public function __construct(bool $fails = false)
    {
        $this->fails = $fails;
    }

    public function update(string $key, callable $decide): bool
    {
        if ($this->fails) {
            return false;
        }
        $next = $decide($this->values[$key] ?? null);
        if ($next !== null) {
            $this->values[$key] = $next;
        }

        return true;
    }

    /**
     * @return array<string, int>
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
