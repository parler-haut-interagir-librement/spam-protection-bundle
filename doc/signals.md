# Signals

Each signal is an observation the server makes itself, never a claim the client makes. The inspection raises all of
those it finds, in this order.

| Signal | Raised when | Tune with |
|---|---|---|
| `honeypot_filled` | The hidden `website_url` field holds anything. | — |
| `token_missing` | The submission carries no token: the form was not rendered here. | — |
| `token_forged` | The token's signature does not match, or it belongs to another scope. | `secret` |
| `proof_invalid` | The proof of work is missing or wrong. Checked only on a genuine token. | `proof_of_work` |
| `too_fast` | Fewer than `timing.minimum` seconds since the form was rendered. | `timing.minimum` |
| `token_expired` | More than `timing.maximum` seconds since the form was rendered. | `timing.maximum` |
| `token_replayed` | The token was already submitted. Checked only on a genuine, on-time token. | `cache_pool`, `used_tokens` |
| `malformed` | A text is not valid UTF-8, which no browser sends; it is still read for the next four signals, each bad sequence replaced. | — |
| `too_long` | A text holds more characters than allowed; only that many are read for the next three signals. | `content.maximum_length` |
| `too_many_links` | A text holds more `http://`, `https://` or `www.` addresses than allowed, each counted once. | `content.maximum_links` |
| `forbidden_term` | A text holds a forbidden term as a whole word or a whole sequence of words, without regard to case, accents or punctuation. | `content.forbidden_terms` |
| `gibberish` | A text has a word of 12 letters or more without a vowel, or 20 letters or more of which fewer than one in eight are vowels (`y` counts). | `content.detect_gibberish` |
| `rate_limited` | The client submitted the form more often than the limiter allows. | `rate_limiter` |

**A token is claimed only when it is genuine and on time**, so that forged or stale tokens never fill the cache of
used ones. **The rate limit counts every submission** of a request with a client address, held or not, so that a
robot cannot reset it by passing the other checks.

## Weighted refusals

By default, one signal refuses a submission. An application that finds a signal too strict on its own — a fast typist
who raises `too_fast`, a support form where people paste several links — gives it a lighter weight: each signal raised
adds its weight, and a score that reaches the threshold refuses.

```yaml
phil_spam_protection:
    refusal:
        threshold: 100
        weights:
            too_fast: 60          # alone, accepted; with another light signal, refused
            too_many_links: 40
            gibberish: 50
```

| Signals raised | Score | Outcome |
|---|---|---|
| `too_fast` | 60 | Accepted, logged at the `info` level |
| `too_fast`, `too_many_links` | 100 | Refused |
| `honeypot_filled` | 100 | Refused: a signal not listed weighs the threshold |
| `token_replayed` | 100 | Refused, whatever the weights |

- **A signal not listed weighs the threshold**, so that the configuration only ever lightens the signals it names.
- **`token_missing`, `token_forged` and `token_replayed` always weigh the threshold**, and the configuration refuses a
  weight for them: they are certain, and a lighter replay would let one token pass twice, which the atomic store of
  used tokens exists to prevent ([Single-use tokens in production](configuration.md#single-use-tokens-in-production)).
- **Each signal counts once**, however many texts raise it.
- A weight of 0 keeps a signal in the log without letting it refuse; the threshold and the weights are bounded from 1,
  and from 0, to 1000.
- A submission refused is logged at the `warning` level with its signals, its score and the threshold; one accepted
  with signals below the threshold at the `info` level, so that a weight too light shows in the log.

`RefusalPolicy` is a service: an endpoint that calls the inspector directly asks it the same question
([Extension points](extension-points.md#the-inspector-outside-forms)), and `Verdicts::of($inspection, $policy)` gives
the same answer as a bot-detection verdict.
