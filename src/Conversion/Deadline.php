<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Conversion;

use Symfony\Component\HttpFoundation\Request;

/**
 * The moment by which a conversion request must be done
 * (CONVERSION_TIME_LIMIT seconds after PHP started it), so that every
 * conversion it runs gets what is left of that time as its processing
 * ceiling instead of the library's default of one hour.
 *
 * Immutable value object.
 */
final class Deadline
{
    /**
     * @var float Unix time in seconds
     */
    private $expiresAt;

    /**
     * @var callable(): float
     */
    private $clock;

    /**
     * @param float                    $expiresAt Unix time in seconds
     * @param (callable(): float)|null $clock     the current Unix time in seconds; microtime(true) when null
     */
    public function __construct(float $expiresAt, ?callable $clock = null)
    {
        $this->expiresAt = $expiresAt;
        $this->clock = $clock ?? static function (): float {
            return microtime(true);
        };
    }

    /**
     * $seconds after the request started: REQUEST_TIME_FLOAT, the moment
     * PHP received the request (after the web server buffered the body),
     * or now when it is missing or in the future.
     *
     * @param (callable(): float)|null $clock the current Unix time in seconds; microtime(true) when null
     */
    public static function forRequest(Request $request, float $seconds, ?callable $clock = null): self
    {
        $now = $clock !== null ? $clock() : microtime(true);
        $started = $request->server->get('REQUEST_TIME_FLOAT');
        $start = is_float($started) || is_int($started) ? min((float) $started, $now) : $now;

        return new self($start + $seconds, $clock);
    }

    /**
     * Seconds left until the deadline; 0 or less once it has passed.
     */
    public function remainingSeconds(): float
    {
        return $this->expiresAt - ($this->clock)();
    }
}
