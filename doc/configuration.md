# Configuration

The alias is `phil_spam_protection`. Every key is optional.

```yaml
phil_spam_protection:
    secret: '%kernel.secret%'
    timing: { minimum: 3, maximum: 3600 }
    proof_of_work: 12
    content: { maximum_length: 20000, maximum_links: 3, forbidden_terms: [], detect_gibberish: true }
    cache_pool: cache.app
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
| `rate_limiter` | `null` | The service id of a Symfony rate limiter factory — `limiter.contact` for `framework.rate_limiter.contact` —; none by default. |

```yaml
# a rate limit of 5 submissions per hour, per form and client
framework:
    rate_limiter:
        forms: { policy: fixed_window, limit: 5, interval: '1 hour' }

phil_spam_protection:
    rate_limiter: limiter.forms
```

## Services

| Service | Role |
|---|---|
| `Infrastructure\Cache\CacheUsedTokens` | The used tokens in `cache_pool`. |
| `Infrastructure\RateLimit\SymfonySubmissionRateLimiter` | The rate limit, only when `rate_limiter` is set. |
| `Application\SubmissionInspector` | Issues and inspects, built by `SubmissionInspector::configured()` from the configuration's values; usable outside forms ([Extension points](extension-points.md)). |
| `Infrastructure\Http\ClientKey` | The keyed hash of the current request's client address. |
| `Infrastructure\Form\SpamProtectionTypeExtension` | The `spam_protection` option, tagged `form.type_extension`; logs on the `spam_protection` channel. |
