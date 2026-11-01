<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Conversion;

use Mfonte\SecureImageConverter\Contract\ConverterInterface;
use Mfonte\SecureImageConverter\Contract\Exception\InvalidArgumentException;
use Mfonte\SecureImageConverter\Pipeline\ConverterOptions;
use Mfonte\SecureImageConverter\Pipeline\SecureConverterBuilder;
use Mfonte\SecureImageConverter\Validation\Limits;

/**
 * Creates the library's SecureConverter for one request.
 *
 * - Working directory: the request directory (resolved with realpath()).
 *   Every private directory of the library (sic-*) is then created inside
 *   it, so the kernel's removal of the request directory, in a finally
 *   block or in the shutdown function after a fatal error, removes them
 *   too, and a cleaner scoped to RequestDirectories::PREFIX covers what a
 *   killed worker leaves.
 * - Processing budget: the ceiling of each job is the time left to the
 *   request (at least MIN_CEILING_SECONDS), instead of the library's
 *   default of one hour, and the floor is lowered to it when it is
 *   smaller. A conversion that would need more is refused before it runs
 *   (conversion_timeout, 422), and one that runs longer than its estimate
 *   is stopped by the library's wall-clock timeout, both before the web
 *   server or PHP-FPM gives up on the request. Its backend processes also
 *   run with a CPU time limit of twice the ceiling (rlimit and sandbox
 *   isolation), and a sandboxed one dies with its parent (bubblewrap
 *   --die-with-parent), so a worker killed in spite of this does not leave
 *   them running for long.
 *
 * The builder is built again for each request; IsolationDetector and the
 * backends' capability cache keep what they probe in files, so that costs
 * little after the first request.
 */
final class LibraryConverterFactory implements ConverterFactoryInterface
{
    /**
     * Smallest processing ceiling given to a conversion, in seconds.
     */
    public const MIN_CEILING_SECONDS = 1.0;

    /**
     * @var SecureConverterBuilder
     */
    private $builder;

    /**
     * @var Limits
     */
    private $limits;

    /**
     * @var ConverterOptions
     */
    private $options;

    /**
     * @param SecureConverterBuilder $builder the settings of every converter (backends, isolation policy, backend
     *                                        options); its limits and converter options are replaced
     * @param Limits                 $limits  the input limits of every converter
     * @param ConverterOptions|null  $options the converter options before the per-request working directory and
     *                                        budget are applied; the library defaults when null
     */
    public function __construct(SecureConverterBuilder $builder, Limits $limits, ?ConverterOptions $options = null)
    {
        $this->builder = $builder->withLimits($limits);
        $this->limits = $limits;
        $this->options = $options ?? new ConverterOptions();
    }

    /**
     * @throws InvalidArgumentException when the working directory does not exist
     */
    public function create(string $workingDirectory, float $timeLimitSeconds): ConverterInterface
    {
        $directory = realpath($workingDirectory);
        if ($directory === false || !is_dir($directory)) {
            throw new InvalidArgumentException('The working directory of the request does not exist.');
        }

        return $this->builder
            ->withConverterOptions($this->options($directory, $timeLimitSeconds))
            ->build();
    }

    public function getMaxInputBytes(): int
    {
        return $this->limits->getMaxBytes();
    }

    /**
     * The converter options of one request.
     */
    private function options(string $directory, float $timeLimitSeconds): ConverterOptions
    {
        $budget = $this->options->getBudget();
        $ceiling = max(self::MIN_CEILING_SECONDS, min($timeLimitSeconds, $budget->getMaxSeconds()));
        $budget = $budget->withMinSeconds(min($budget->getMinSeconds(), $ceiling))->withMaxSeconds($ceiling);

        return $this->options
            ->withWorkingDirectoryParent($directory)
            ->withBudget($budget);
    }
}
