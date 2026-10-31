<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Auth;

use Mfonte\ImageConversionApi\Http\IpRange;

/**
 * The client addresses allowed on the protected endpoints (IP_ALLOWLIST:
 * IPs and CIDR blocks, IPv4 and IPv6), on top of the API key. Empty, the
 * default, allows every address.
 *
 * The client address must come from Http\TrustedProxies::getClientAddress(),
 * which believes X-Forwarded-For only from TRUSTED_PROXIES: a client cannot
 * name itself an allowed address. An unknown address (null) is refused
 * whenever the list is not empty.
 *
 * Immutable.
 */
final class IpAllowlist
{
    /**
     * @var list<IpRange>
     */
    private $ranges;

    /**
     * @param list<IpRange> $ranges the allowed addresses; none allows every address
     */
    public function __construct(array $ranges = [])
    {
        $this->ranges = $ranges;
    }

    /**
     * Whether the list restricts anything.
     */
    public function isEnabled(): bool
    {
        return $this->ranges !== [];
    }

    /**
     * @param string|null $clientAddress the client address as TrustedProxies tells it; null when unknown
     */
    public function allows(?string $clientAddress): bool
    {
        if ($this->ranges === []) {
            return true;
        }

        return $clientAddress !== null && IpRange::anyContains($this->ranges, $clientAddress);
    }
}
