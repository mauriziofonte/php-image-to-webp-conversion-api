<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Tests\Fixtures\Conversion;

use Mfonte\SecureImageConverter\Contract\IsolationLevel;
use Mfonte\SecureImageConverter\Isolation\DetectionResult;
use Mfonte\SecureImageConverter\Isolation\IsolationDetectorInterface;

/**
 * Reports the isolation level "sandbox" without probing the host, so that a
 * test can build a SecureConverter without starting processes.
 */
final class FixedIsolationDetector implements IsolationDetectorInterface
{
    public function detect(): DetectionResult
    {
        return new DetectionResult(IsolationLevel::SANDBOX, [], time());
    }

    public function refresh(): DetectionResult
    {
        return $this->detect();
    }
}
