# Installation

## Requirements

| Requirement | Why |
|---|---|
| PHP 8.5 or later, `ext-sodium` | The nonce's hexadecimal, timing-safe. |
| `phil/spam-protection` | The signals, tokens, timing, proof of work and content rules. |
| `phil/bot-detection-bundle` | The `Verdict` an inspection gives. |
| `symfony/form`, `symfony/options-resolver` | The `spam_protection` option of every form. |
| `symfony/http-foundation` | The client's address, from the current request, for the rate limit's key. |
| `symfony/translation-contracts` | The message of a held submission. |
| `psr/cache`, `psr/clock`, `psr/log` | The used tokens, the time, and the log of held submissions. |
| `symfony/rate-limiter` (optional) | The rate limit per form and client. |

The application registers FrameworkBundle, with forms, a cache pool and the `clock` service.

## Installing

```sh
composer require phil/spam-protection-bundle
```

```php
// config/bundles.php
return [
    // …
    Phil\SpamProtectionBundle\PhilSpamProtectionBundle::class => ['all' => true],
];
```

## The Stimulus controller

The proof of work is solved in the browser by a Stimulus controller the bundle ships, declared in
`assets/package.json` as Symfony UX bundles declare theirs. With StimulusBundle and AssetMapper or Webpack Encore,
it is found like any UX controller:

```sh
composer require symfony/stimulus-bundle
```

```json
// assets/controllers.json — added by Flex, or by hand
{
    "controllers": {
        "@phil/spam-protection-bundle": {
            "proof-of-work": { "enabled": true, "fetch": "eager" }
        }
    }
}
```

The controller's identifier is `phil--spam-protection-bundle--proof-of-work`; the form extension puts it on the
proof field with its two values. A page without the controller submits no proof: with a difficulty above 0, its
forms are held — set `proof_of_work: 0` until the controller is loaded everywhere ([Proof of work](proof-of-work.md)).
