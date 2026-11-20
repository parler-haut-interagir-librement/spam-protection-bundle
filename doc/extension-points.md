# Extension points

## UsedTokens

```php
interface UsedTokens
{
    public function claim(string $nonce, int $ttl): bool;
}
```

Records a nonce as used for `ttl` seconds and answers whether it was new. **A claim should be atomic**: two
submissions of one token at the same moment must not both be told it was new.

The bundle ships three, chosen by `used_tokens` ([Configuration](configuration.md#single-use-tokens-in-production)):

| Store | Atomic | How |
|---|---|---|
| `DbalUsedTokens`, with `used_tokens.connection` | Yes | One insert on the primary key of the nonce; a duplicate is refused. |
| `CacheUsedTokens` under `used_tokens.lock_factory` | Yes, with a shared lock store | The lock of the nonce, then a read and a write under it. |
| `CacheUsedTokens` alone, the default | No | A read, a marker written, and a read back; refused in the `prod` environment. |

PSR-6 has no atomic "add if absent", so the pool alone reads, writes a marker of its own, and reads back: of two
submissions that write one after the other, the one whose marker was overwritten is refused. Two that each read
back before the other writes can still both pass, so a robot replaying one token in a tight parallel burst may get a
few submissions through; the other signals and the rate limit still apply to each. A store of your own implements
`claim()` over an atomic primitive — an insert on a unique key that fails on a duplicate, Redis
`SET key value NX EX ttl` — and takes the place of the bundle's by an alias of `Application\UsedTokens`.

## SubmissionRateLimiter

```php
interface SubmissionRateLimiter
{
    public function consume(string $scope, string $client): bool;
}
```

Counts one submission and answers whether it stays within the limit. The bundle adapts a Symfony rate limiter
factory (`SymfonySubmissionRateLimiter`), keyed by scope and client.

## The inspector outside forms

An API endpoint or a webhook receiver uses the inspector directly:

```php
$protection = $inspector->protect('newsletter');       // token, challenge, difficulty, for the client to echo

$inspection = $inspector->inspect(new Submission(
    scope: 'newsletter',
    honeypot: $payload['website_url'] ?? null,
    token: $payload['token'] ?? null,
    solution: $payload['proof'] ?? null,
    texts: [$payload['comment'] ?? ''],
    client: $clientKey->current(),
));

if ($refusal->refuses($inspection)) {     // $refusal: RefusalPolicy, from the container
    // refuse, or add friction — Verdicts::of($inspection, $refusal) is a bot-detection Verdict
}
```

`$inspection->isSpam()` answers whether any signal was raised; `RefusalPolicy` weighs them as the configuration says
([Weighted refusals](signals.md#weighted-refusals)).
