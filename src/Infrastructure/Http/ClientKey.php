<?php

declare(strict_types=1);

namespace Phil\SpamProtectionBundle\Infrastructure\Http;

use SensitiveParameter;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The key a client's submissions are counted by: an HMAC-SHA256 of its network under the application's secret.
 * The address itself is never kept nor logged, and the key cannot be turned back into it without the secret.
 *
 * **One key per client network, not per address.** An IPv4 address is its own network. An IPv6 host is given a
 * whole `/64` and can pick a new address in it for each request, so an IPv6 address counts as its `/64`: rotating
 * inside it does not escape the rate limit. An IPv4 address written as IPv6 (`::ffff:203.0.113.7`) counts as the
 * IPv4 address it carries.
 */
final readonly class ClientKey
{
    /** The twelve leading bytes of an IPv4 address mapped into IPv6. */
    private const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    public function __construct(
        private RequestStack $requests,
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    /**
     * The key of the current request's client, or `null` outside a request or without an address.
     */
    public function current(): ?string
    {
        $address = $this->requests->getCurrentRequest()?->getClientIp();

        return null === $address ? null : hash_hmac('sha256', self::network($address), $this->secret);
    }

    /**
     * The network an address counts as: the IPv4 address itself, the IPv4 address an IPv6 one maps, or the `/64` of
     * any other IPv6 address. What is not an IP address is kept as it is.
     */
    public static function network(string $address): string
    {
        $packed = false === filter_var($address, \FILTER_VALIDATE_IP) ? false : inet_pton($address);

        if (false === $packed) {
            return $address;
        }

        if (16 === \strlen($packed) && str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
            $packed = substr($packed, 12);
        }

        $network = 4 === \strlen($packed) ? inet_ntop($packed) : inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        if (false === $network) {
            return $address;
        }

        return 4 === \strlen($packed) ? $network : $network.'/64';
    }
}
