# Reference

| Type | Role |
|---|---|
| `PhilSpamProtectionBundle` | The bundle class: its configuration and services. |
| `Application\Verdicts` | An inspection as the `Verdict` of `phil/bot-detection-bundle`. |
| `Application\SubmissionInspector` | Issues a form's protection and inspects a submission. |
| `Application\Protection`, `Application\Submission` | What a rendered form carries; what a submission brings back. |
| `Application\UsedTokens`, `Application\SubmissionRateLimiter` | The ports. |
| `Infrastructure\Cache\CacheUsedTokens` | Used tokens in a PSR-6 pool. |
| `Infrastructure\RateLimit\SymfonySubmissionRateLimiter` | The rate limit over a Symfony rate limiter. |
| `Infrastructure\Http\ClientKey` | The keyed hash of the client's network (IPv4 address, or IPv6 `/64`); `network()` gives that network. |
| `Infrastructure\Form\SpamProtectionTypeExtension` | The `spam_protection` option of every form. |

The signals, tokens, timing, proof of work and content rules are `phil/spam-protection`'s, under
`Phil\SpamProtection`.
