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
| `token_replayed` | The token was already submitted. Checked only on a genuine, on-time token. | `cache_pool` |
| `malformed` | A text is not valid UTF-8, which no browser sends; it is still read for the next four signals, each bad sequence replaced. | — |
| `too_long` | A text holds more characters than allowed; only that many are read for the next three signals. | `content.maximum_length` |
| `too_many_links` | A text holds more `http://`, `https://` or `www.` addresses than allowed, each counted once. | `content.maximum_links` |
| `forbidden_term` | A text holds a forbidden term as a whole word or a whole sequence of words, without regard to case, accents or punctuation. | `content.forbidden_terms` |
| `gibberish` | A text has a word of 12 letters or more without a vowel, or 20 letters or more of which fewer than one in eight are vowels (`y` counts). | `content.detect_gibberish` |
| `rate_limited` | The client submitted the form more often than the limiter allows. | `rate_limiter` |

**A token is claimed only when it is genuine and on time**, so that forged or stale tokens never fill the cache of
used ones. **The rate limit counts every submission** of a request with a client address, held or not, so that a
robot cannot reset it by passing the other checks.
