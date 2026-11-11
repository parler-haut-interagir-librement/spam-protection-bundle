# Changelog

All notable changes to `phil/spam-protection-bundle` will be documented in this file.

Updates should follow the [Keep a CHANGELOG](https://keepachangelog.com/) principles.

## NEXT - YYYY-MM-DD

### Added

- `SubmissionInspector`, with the `UsedTokens` and `SubmissionRateLimiter` ports.
- `Verdicts`: an inspection as a `Verdict` of `phil/bot-detection-bundle`.
- `CacheUsedTokens`, used tokens in a PSR-6 pool, which reads its claim back and refuses a claim another submission
  overwrote.
- A Symfony rate limiter adapter and a keyed client key, which hashes an IPv6 address as its `/64` and an
  IPv4-mapped one as its IPv4 address, so rotating addresses inside a `/64` does not escape the rate limit.
- The configuration: `content.maximum_length` (20,000 characters by default, `~` for no bound), with the `too_long`
  signal, and `content.maximum_links`, where `~` sets no limit. A content field that is not valid UTF-8 is held as
  `malformed`, never a server error.
- The `spam_protection` option of every form; English and French messages. A protected form shown again after a
  submission renders the token issued for that request, beside its challenge, and an empty proof.
- The browser's proof of work, a Stimulus controller declared in `assets/package.json`, tested with Node: it computes
  SHA-256 synchronously and yields to the browser after each frame of work.
- The documentation in `doc/`, one file per topic, indexed by the README: the remaining window and atomic stores of
  used tokens, measured timings in the difficulty table, and an extension-points example that converts an
  inspection with `Verdicts::of()`.
- `CONTRIBUTING.md`, `SECURITY.md` and `CODE_OF_CONDUCT.md`.
