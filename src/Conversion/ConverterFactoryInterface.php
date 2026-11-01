<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Conversion;

use Mfonte\ImageConversionApi\Exception\RequestException;
use Mfonte\SecureImageConverter\Contract\ConverterInterface;
use Mfonte\SecureImageConverter\Contract\Exception\ExceptionInterface;

/**
 * Creates the converter of one conversion request, bound to that request:
 * its working directories live in the request directory, and its
 * processing ceiling is what is left of the request's time limit. The
 * conversion handlers depend on this interface, so that tests can stand a
 * scripted converter in for the library.
 */
interface ConverterFactoryInterface
{
    /**
     * @param string $workingDirectory existing directory of the request, which receives the private working
     *                                 directory of every conversion, so that removing the request directory
     *                                 removes them too
     * @param float  $timeLimitSeconds what is left of the request's time limit, above 0
     *
     * @throws ExceptionInterface when the converter cannot be created (an isolation level that a strict policy
     *                            requires and the host lacks, an invalid setting)
     * @throws RequestException   when a resource of the host the conversions need cannot be used (the conversion
     *                            slots of an unusable LOCK_DIR: 503 concurrency_unavailable); the converter's
     *                            convert() may throw it too, when it takes a slot
     */
    public function create(string $workingDirectory, float $timeLimitSeconds): ConverterInterface;

    /**
     * The largest input the converters accept, in bytes (the max_bytes limit): a body above it is refused
     * while it is read, before any conversion.
     */
    public function getMaxInputBytes(): int;
}
