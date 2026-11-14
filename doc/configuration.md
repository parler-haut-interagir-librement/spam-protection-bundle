# Configuration

The alias is `phil_spam_protection`. Every key is optional.

```yaml
phil_spam_protection:
    secret: '%kernel.secret%'
    timing: { minimum: 3, maximum: 3600 }
    proof_of_work: 12
    content: { maximum_length: 20000, maximum_links: 3, forbidden_terms: [], detect_gibberish: true }
    cache_pool: cache.app
    used_tokens: { connection: null, lock_factory: null }
    rate_limiter: null
```

| Key | Default | Meaning |
|---|---|---|
| `secret` | `%kernel.secret%` | Signs the tokens and keys the client hash; 16 bytes or more. Changing it invalidates the forms already rendered. |
| `timing.minimum` | `3` | The seconds a person takes at least to fill a form; a quicker submission raises `too_fast`. 0 turns it off. |
| `timing.maximum` | `3600` | The seconds after which a rendered form is stale; a later submission raises `token_expired`. Used tokens are kept as long. |
| `proof_of_work` | `12` | The bits of difficulty of the browser's proof; 0 to 24, 0 turning it off. Keep it at 16 or below on forms people fill ([Proof of work](proof-of-work.md)). |
| `content.maximum_length` | `20000` | The characters a text may hold; a longer text raises `too_long`, and only its first `maximum_length` characters are read. An integer of 1 or more, or `~` for no bound. |
| `content.maximum_links` | `3` | The links a text may hold, each address counted once; an integer of 0 or more, or `~` for no limit. |
| `content.forbidden_terms` | `[]` | Whole words, or sequences of words, a text may not hold, compared without case, accents or the punctuation between words. |
| `content.detect_gibberish` | `true` | Whether a text of random characters is held. |
| `cache_pool` | `cache.app` | The PSR-6 pool of used tokens. Give one that every web server shares. |
| `used_tokens.connection` | `null` | The Doctrine connection whose table keeps the used tokens, in place of the pool: `default`. Each claim is one atomic insert. |
| `used_tokens.lock_factory` | `null` | The service id of a Symfony lock factory under which the pool claims each token: `lock.factory`. Its store must be shared by every web server. Not with `connection`. |
| `rate_limiter` | `null` | The service id of a Symfony rate limiter factory — `limiter.contact` for `framework.rate_limiter.contact` —; none by default. |

```yaml
# a rate limit of 5 submissions per hour, per form and client
framework:
    rate_limiter:
        forms: { policy: fixed_window, limit: 5, interval: '1 hour' }

phil_spam_protection:
    rate_limiter: limiter.forms
```

## Single-use tokens in production

Without `used_tokens`, the pool claims a token by reading, writing and reading back: two submissions of one token at
the same moment can both pass. In a burst of eight simultaneous submissions of one token on a filesystem pool, one
to four got through. **In production, choose an atomic store**:

```yaml
# a table of the Doctrine connection, created by the next migration
phil_spam_protection:
    used_tokens: { connection: default }
```

```yaml
# or the cache pool under a lock, both on a store every web server shares
framework:
    lock: '%env(LOCK_DSN)%'          # redis://…, a database…

phil_spam_protection:
    cache_pool: cache.shared
    used_tokens: { lock_factory: lock.factory }
```

With `connection`, the table `phil_spam_protection_used_token` joins the schema the ORM generates for that
connection, so `doctrine:migrations:diff` writes its migration; without the ORM, `UsedTokensTable::configure()` adds
it to a schema. `DbalUsedTokens::purge()` forgets the expired rows, from a scheduled task. Under a lock, each claim
holds the lock of its nonce for at most 10 seconds; on Redis, the lock store's `SET NX` gives the atomic step.

## Services

| Service | Role |
|---|---|
| `Application\UsedTokens` | An alias of the store chosen below; alias it to a store of your own to replace it. |
| `Infrastructure\Cache\CacheUsedTokens` | The used tokens in `cache_pool`, under `used_tokens.lock_factory` when it is set; unless `used_tokens.connection` is set. |
| `Infrastructure\Doctrine\DbalUsedTokens` | The used tokens in a table of `used_tokens.connection`, only when it is set. |
| `Infrastructure\Doctrine\UsedTokensSchemaListener` | Adds that table to the schema the ORM generates for the connection, only when `used_tokens.connection` is set. |
| `Infrastructure\RateLimit\SymfonySubmissionRateLimiter` | The rate limit, only when `rate_limiter` is set. |
| `Application\SubmissionInspector` | Issues and inspects, built by `SubmissionInspector::configured()` from the configuration's values; usable outside forms ([Extension points](extension-points.md)). |
| `Infrastructure\Http\ClientKey` | The keyed hash of the current request's client address. |
| `Infrastructure\Form\SpamProtectionTypeExtension` | The `spam_protection` option, tagged `form.type_extension`; logs on the `spam_protection` channel. |
