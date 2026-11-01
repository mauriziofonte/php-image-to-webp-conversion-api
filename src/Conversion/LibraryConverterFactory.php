<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Conversion;

use Mfonte\ImageConversionApi\Limits\ConversionSlots;
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
 * - Concurrency: with ConversionSlots, each conversion takes one of the
 *   host's slots (the library's Semaphore) once the input passed its
 *   checks, waiting at most CONCURRENCY_WAIT, then fails with
 *   concurrency_limit (503 with Retry-After). The wait is part of the time
 *   limit: the processing ceiling is what is left after it.
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
     * @var ConversionSlots|null
     */
    private $slots;

    /**
     * @param SecureConverterBuilder $builder the settings of every converter (backends, isolation policy, backend
     *                                        options); its limits, semaphore and converter options are replaced
     * @param Limits                 $limits  the input limits of every converter
     * @param ConverterOptions|null  $options the converter options before the per-request working directory and
     *                                        budget are applied, the slot wait included; the library defaults when
     *                                        null
     * @param ConversionSlots|null   $slots   the conversion slots of the host; none (no limit) when null
     */
    public function __construct(SecureConverterBuilder $builder, Limits $limits, ?ConverterOptions $options = null, ?ConversionSlots $slots = null)
    {
        $this->builder = $builder->withLimits($limits);
        $this->limits = $limits;
        $this->options = $options ?? new ConverterOptions();
        $this->slots = $slots;
    }

    /**
     * @throws InvalidArgumentException when the working directory does not exist, or LOCK_DIR is not usable
     */
    public function create(string $workingDirectory, float $timeLimitSeconds): ConverterInterface
    {
        $directory = realpath($workingDirectory);
        if ($directory === false || !is_dir($directory)) {
            throw new InvalidArgumentException('The working directory of the request does not exist.');
        }

        return $this->builder
            ->withSemaphore($this->slots !== null ? $this->slots->semaphore() : null)
            ->withConverterOptions($this->options($directory, $timeLimitSeconds, $this->slots !== null))
            ->build();
    }

    public function getMaxInputBytes(): int
    {
        return $this->limits->getMaxBytes();
    }

    /**
     * The converter options of one request: the slot wait, when the
     * converter takes a slot, then the processing ceiling share the time
     * limit.
     */
    private function options(string $directory, float $timeLimitSeconds, bool $takesSlot): ConverterOptions
    {
        $options = $this->options;
        if ($takesSlot) {
            $wait = min($options->getSlotWaitSeconds(), max(0.0, $timeLimitSeconds - self::MIN_CEILING_SECONDS));
            $options = $options->withSlotWaitSeconds($wait);
            $timeLimitSeconds -= $wait;
        }
        $budget = $options->getBudget();
        $ceiling = max(self::MIN_CEILING_SECONDS, min($timeLimitSeconds, $budget->getMaxSeconds()));
        $budget = $budget->withMinSeconds(min($budget->getMinSeconds(), $ceiling))->withMaxSeconds($ceiling);

        return $options
            ->withWorkingDirectoryParent($directory)
            ->withBudget($budget);
    }
}
