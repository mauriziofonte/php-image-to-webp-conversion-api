<?php

declare(strict_types=1);

namespace Mfonte\ImageConversionApi\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The reverse proxies and load balancers allowed to tell the service how a
 * client reached them (TRUSTED_PROXIES: IPs and CIDR blocks, none by
 * default). X-Forwarded-Proto and X-Forwarded-For are read only when the
 * direct peer (REMOTE_ADDR) is one of them; from anyone else they are
 * ignored, since any client can send them.
 *
 * Symfony's Request::setTrustedProxies() is never called: it is static,
 * process-wide state, and this object must not change what other code in
 * the process trusts. For the same reason Request::isSecure() and
 * Request::getClientIp(), which depend on it, are not used here.
 *
 * Immutable.
 */
final class TrustedProxies
{
    /**
     * X-Forwarded-Proto values that mean HTTPS, as Symfony reads them.
     */
    private const SECURE_PROTOCOLS = ['https', 'on', 'ssl', '1'];

    /**
     * @var list<IpRange>
     */
    private $ranges;

    /**
     * @param list<IpRange> $ranges the trusted proxies; none trusts nobody
     */
    public function __construct(array $ranges = [])
    {
        $this->ranges = $ranges;
    }

    /**
     * Whether $address is a trusted proxy; false for null or an invalid
     * address.
     */
    public function isTrusted(?string $address): bool
    {
        return $address !== null && IpRange::anyContains($this->ranges, $address);
    }

    /**
     * Whether the client reached the service, or the trusted proxy in front
     * of it, over HTTPS.
     *
     * From a trusted peer that sends X-Forwarded-Proto, every value of the
     * header must mean HTTPS: a chain of proxies that appends values ("https,
     * http") is secure only when every hop was, and a client cannot prepend
     * "https" in front of a proxy that appends "http". Otherwise the HTTPS
     * server variable decides, as the web server set it for the connection
     * it received.
     */
    public function isSecure(Request $request): bool
    {
        if ($this->isTrusted(self::remoteAddress($request))) {
            $protocols = self::headerValues($request, 'X-Forwarded-Proto');
            if ($protocols !== []) {
                foreach ($protocols as $protocol) {
                    if (!in_array(strtolower($protocol), self::SECURE_PROTOCOLS, true)) {
                        return false;
                    }
                }

                return true;
            }
        }

        $https = $request->server->get('HTTPS');

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
    }

    /**
     * The address of the client: REMOTE_ADDR, or, when REMOTE_ADDR is a
     * trusted proxy, the nearest address of X-Forwarded-For that is not a
     * trusted proxy, read from right to left (each proxy appends the address
     * it received the request from, so only the entries right of the first
     * untrusted one were written by trusted proxies). When every entry is
     * trusted, the leftmost one.
     *
     * @return string|null null when the address is unknown: no REMOTE_ADDR, or an entry that is not an IP address
     *                     where a trusted proxy should have written one
     */
    public function getClientAddress(Request $request): ?string
    {
        $address = self::remoteAddress($request);
        if (!$this->isTrusted($address)) {
            return $address;
        }

        $forwarded = self::headerValues($request, 'X-Forwarded-For');
        for ($index = count($forwarded) - 1; $index >= 0; --$index) {
            $address = self::stripPort($forwarded[$index]);
            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                return null;
            }
            if (!$this->isTrusted($address)) {
                return $address;
            }
        }

        return $address;
    }

    private static function remoteAddress(Request $request): ?string
    {
        $address = $request->server->get('REMOTE_ADDR');

        return is_string($address) && $address !== '' ? $address : null;
    }

    /**
     * The comma-separated values of every occurrence of a header, trimmed,
     * without empty values.
     *
     * @return list<string>
     */
    private static function headerValues(Request $request, string $name): array
    {
        $values = [];
        foreach ($request->headers->all(strtolower($name)) as $header) {
            foreach (explode(',', (string) $header) as $value) {
                $value = trim($value);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }

    /**
     * "192.0.2.1:8080" and "[2001:db8::1]:8080" without their port, as some
     * proxies write them; "[2001:db8::1]" without its brackets.
     */
    private static function stripPort(string $address): string
    {
        if (preg_match('/\A\[([^\]]+)\](?::\d+)?\z/', $address, $match) === 1) {
            return $match[1];
        }
        if (preg_match('/\A(\d{1,3}(?:\.\d{1,3}){3}):\d+\z/', $address, $match) === 1) {
            return $match[1];
        }

        return $address;
    }
}
