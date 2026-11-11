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

The bundle implements it over a PSR-6 pool (`CacheUsedTokens`). PSR-6 has no atomic "add if absent", so its claim
reads, writes a marker of its own, and reads back: of two submissions that write one after the other, the one
whose marker was overwritten is refused. Two that each read back before the other writes can still both pass, so a
robot replaying one token in a tight parallel burst may get a few submissions through; the other signals and the
rate limit still apply to each. A store that must close that window implements `claim()` over an atomic primitive:
an insert into a table with a unique key on the nonce that fails on a duplicate, or Redis `SET key value NX EX ttl`.

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

if ($inspection->isSpam()) {
    // refuse, or add friction — Verdicts::of($inspection) is a bot-detection Verdict
}
```
