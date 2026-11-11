# Privacy

| Data | Kept | For how long |
|---|---|---|
| The token's nonce | In the cache pool of used tokens | `timing.maximum` seconds |
| A keyed hash of the client's network, per form: the IPv4 address, or the `/64` of an IPv6 one | In the rate limiter's storage | The limiter's window |
| The form's scope and the signals of a held submission | In the log, `spam_protection` channel | The log's retention |

**Never collected**: the address itself, cookies, browser fingerprints, mouse or keyboard behaviour, third-party
reputation scores. **Never sent anywhere**: the Stimulus controller computes a hash locally and writes a number into the
form.

The client hash is an HMAC-SHA256 under the application's secret: it cannot be turned back into an address without
the secret, and it changes with it. An IPv6 address is hashed as its `/64`, the block one host is given and can
rotate addresses in freely, so that rotating does not escape the rate limit; an IPv4 address written as IPv6
(`::ffff:203.0.113.7`) is hashed as the IPv4 address. The token holds the scope, a date and a random nonce — nothing about the person.
