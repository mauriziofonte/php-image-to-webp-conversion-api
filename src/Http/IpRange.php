<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Mfonte\ImageConversionApi\Exception\InvalidArgumentException;

/**
 * One IPv4 or IPv6 address, or a CIDR block of them ("192.0.2.10",
 * "10.0.0.0/8", "2001:db8::/32"), as written in TRUSTED_PROXIES and
 * IP_ALLOWLIST.
 *
 * Addresses are compared in their binary form, so every textual form of an
 * IPv6 address matches. An IPv4-mapped IPv6 address ("::ffff:192.0.2.10",
 * what a dual-stack socket reports for an IPv4 client) is the IPv4 address
 * it maps, both in a range and in a tested address; the other IPv6 ranges,
 * "::/0" included, never match an IPv4 address. Zone indexes ("%eth0"),
 * leading zeros in IPv4 octets and host names are not addresses.
 *
 * Immutable value object.
 */
final class IpRange
{
    /**
     * @var string the network address, packed (4 or 16 bytes), host bits cleared
     */
    private $network;

    /**
     * @var int
     */
    private $prefixLength;

    private function __construct(string $network, int $prefixLength)
    {
        $this->network = $network;
        $this->prefixLength = $prefixLength;
    }

    /**
     * Parses an address or a CIDR block. Host bits set below the prefix
     * ("192.0.2.10/24") are cleared: the block is 192.0.2.0/24.
     *
     * @throws InvalidArgumentException when $range is not an address or a CIDR block
     */
    public static function fromString(string $range): self
    {
        $range = trim($range);
        $parts = explode('/', $range, 2);
        $address = self::pack($parts[0]);
        if ($address === null) {
            throw new InvalidArgumentException(sprintf('"%s" is not an IP address or a CIDR block', $range));
        }
        // pack() reduces IPv4-mapped IPv6 to IPv4; its prefix length still counts 128 bits.
        $mapped = strlen($address) === 4 && strpos($parts[0], ':') !== false;
        $bits = $mapped ? 128 : strlen($address) * 8;
        $prefixLength = $bits;
        if (isset($parts[1])) {
            if (preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/', $parts[1]) !== 1 || (int) $parts[1] > $bits) {
                throw new InvalidArgumentException(sprintf('"%s" has an invalid prefix length (0 to %d)', $range, $bits));
            }
            $prefixLength = (int) $parts[1];
        }
        if ($mapped) {
            if ($prefixLength < 96) {
                throw new InvalidArgumentException(sprintf('"%s" is an IPv4-mapped block shorter than /96: write it as IPv4 and IPv6 blocks', $range));
            }
            $prefixLength -= 96;
        }

        return new self(self::mask($address, $prefixLength), $prefixLength);
    }

    /**
     * Parses a comma-separated list, as found in TRUSTED_PROXIES and
     * IP_ALLOWLIST. Blank entries are skipped.
     *
     * @throws InvalidArgumentException naming the first invalid entry
     *
     * @return list<self>
     */
    public static function listFromString(string $list): array
    {
        $ranges = [];
        foreach (explode(',', $list) as $entry) {
            if (trim($entry) !== '') {
                $ranges[] = self::fromString($entry);
            }
        }

        return $ranges;
    }

    /**
     * Whether $address lies in this range; false when it is not an IP
     * address.
     */
    public function contains(string $address): bool
    {
        $packed = self::pack($address);
        if ($packed === null || strlen($packed) !== strlen($this->network)) {
            return false;
        }

        return self::mask($packed, $this->prefixLength) === $this->network;
    }

    /**
     * Whether $address lies in at least one of $ranges.
     *
     * @param list<self> $ranges
     */
    public static function anyContains(array $ranges, string $address): bool
    {
        foreach ($ranges as $range) {
            if ($range->contains($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The canonical form: "192.0.2.0/24", "2001:db8::/32".
     */
    public function __toString(): string
    {
        return (string) inet_ntop($this->network) . '/' . $this->prefixLength;
    }

    /**
     * The packed form of an IP address, IPv4-mapped IPv6 reduced to IPv4;
     * null when $address is not an IP address.
     */
    private static function pack(string $address): ?string
    {
        $address = trim($address);
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($address);
        if (!is_string($packed)) {
            return null;
        }
        if (strlen($packed) === 16 && strpos($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff") === 0) {
            return substr($packed, 12);
        }

        return $packed;
    }

    /**
     * $packed with every bit after the first $prefixLength cleared.
     */
    private static function mask(string $packed, int $prefixLength): string
    {
        $masked = '';
        $length = strlen($packed);
        for ($index = 0; $index < $length; ++$index) {
            $bits = max(0, min(8, $prefixLength - $index * 8));
            $masked .= chr(ord($packed[$index]) & ((0xff << (8 - $bits)) & 0xff));
        }

        return $masked;
    }
}
