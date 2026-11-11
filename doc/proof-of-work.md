# Proof of work

Before it submits, the browser finds the smallest number `n` such that the SHA-256 of `challenge:n` starts with
`difficulty` zero bits, and writes it into `_phil_spam_proof`. The challenge is the token's nonce, so a proof is
good for one rendering only. The server checks it with one hash.

| Difficulty | Hashes on average | On a laptop (about 500,000 hashes a second) |
|---|---|---|
| 8 | 256 | Instant |
| 12 (default) | 4,096 | About 10 ms |
| 16 | 65,536 | About 0.15 s |
| 20 | about a million | About 2 s |
| 24 (the most) | about 16 million | About 30 s |

The timings are averages measured with the controller's own SHA-256 under Node on a recent laptop; a single
challenge can take several times its average, and a mid-range phone is several times slower. A form submitted
before the proof is found is held, so keep the difficulty at 16 or below on forms people fill: 20 and 24 suit
endpoints where a delay of seconds is acceptable.

A person pays the cost once, while reading the form; a robot posting thousands of submissions pays it on every one.
It stops nothing alone, and adds cost to everything else.

## The controller

`assets/src/proof_of_work_controller.js` is a Stimulus controller over `assets/src/proof_of_work.js`, a module with
no dependency that computes SHA-256 itself (FIPS 180-4), synchronously: one awaited Web Crypto call per candidate
would cost a promise round trip per hash, several times the hash. The search yields to the browser after about a
frame of work (16 ms), so the page stays responsive while it runs. When the proof field connects — the page loads, or a
form arrives later in a modal or a Turbo frame —, the controller solves the challenge of its
`challenge` value at its `difficulty` value and writes the solution into the field. It reads nothing else and sends
nothing anywhere.

```html
<input type="hidden" name="contact[_phil_spam_proof]"
       data-controller="phil--spam-protection-bundle--proof-of-work"
       data-phil--spam-protection-bundle--proof-of-work-challenge-value="0f3a…"
       data-phil--spam-protection-bundle--proof-of-work-difficulty-value="12">
```

A solution is decimal digits only, at most 12; anything else is refused before the hash. Without the controller, a
form with a difficulty above 0 is always held: turn the proof off — `proof_of_work: 0` — on an application that
cannot load it.
