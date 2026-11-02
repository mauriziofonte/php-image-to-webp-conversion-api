<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

/**
 * The deprecation schedule of the 1.x endpoint (/v1), in one place: the
 * Deprecation, Sunset and Link headers of every /v1 response
 * (DeprecatedHandler, wired by KernelFactory) and the README, which a test
 * keeps in step with these constants.
 *
 * The dates are a published commitment to the 1.x clients, not a setting:
 * they are constants rather than configuration keys, so that every
 * installation announces the same schedule. Moving them is a code change
 * reviewed with the README and the release notes.
 */
final class V1Deprecation
{
    /**
     * Unix time of the deprecation of /v1: 2026-11-01T00:00:00Z, the 2.0.0
     * release window.
     */
    public const DEPRECATED_AT = 1793491200;

    /**
     * Unix time after which /v1 may be removed: 2027-11-01T00:00:00Z.
     */
    public const SUNSET_AT = 1825027200;

    /**
     * The deprecation notice of /v1 in the README.
     */
    public const NOTICE_URL = 'https://github.com/mauriziofonte/php-image-to-webp-conversion-api#deprecated-the-1x-endpoint';

    private function __construct()
    {
    }
}
