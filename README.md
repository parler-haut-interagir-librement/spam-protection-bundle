# phil/spam-protection-bundle

**Keep spam out of your Symfony forms without a captcha, a third party or an identifier.**

phil/spam-protection-bundle protects a Symfony form with layers a person never sees. A honeypot field catches naive
robots. A signed single-use token sets a minimum and maximum filling time. The browser solves a proof of work
without the person noticing. Rules on the text catch links, forbidden terms and gibberish, and a rate limit applies
per form and client.

It is for any Symfony application with public forms, such as contact, sign-up or comment forms, that wants to stay
friendly to the people filling them. It shows no captcha, calls no third party, downloads no address reputation
list and keeps no identifier. The rate limit counts a keyed hash of the client's address, and the log names the
form and the reasons, never the client.

The signals come from `phil/spam-protection`, the plain PHP library to use outside Symfony. This bundle adds what
needs the framework: a form type extension, used tokens kept in a PSR-6 cache, the Symfony rate limiter, and the
Stimulus controller of the proof. `Verdicts` gives an inspection as a `Verdict` of `phil/bot-detection-bundle`.
One raised signal makes it a robot, so an application counts spam like any other robot verdict.

## Status

| Part | State |
|---|---|
| Application: `SubmissionInspector`, `Verdicts`, `UsedTokens` and `SubmissionRateLimiter` ports | Implemented and tested |
| Infrastructure: form type extension, PSR-6 used tokens, Symfony rate limiter, client key | Implemented and tested |
| The browser's proof of work: a Stimulus controller (`assets/src/proof_of_work_controller.js`) | Implemented, tested with Node |
| A kernel test of a protected form | Implemented |

## Documentation

| Document | What it covers |
|---|---|
| [Installation](doc/installation.md) | Requirements, installing, registering the bundle and enabling its Stimulus controller. |
| [Configuration](doc/configuration.md) | Every key, its default, and the services the bundle registers. |
| [Protecting a form](doc/forms.md) | The `spam_protection` option, the three fields it adds, and what a held submission gets. |
| [Signals](doc/signals.md) | Each signal, what raises it, and how to tune it. |
| [Proof of work](doc/proof-of-work.md) | The computation the browser makes, its cost, and the script. |
| [Privacy](doc/privacy.md) | What is kept, for how long, and what is never collected. |
| [Extension points](doc/extension-points.md) | The `UsedTokens` and `SubmissionRateLimiter` ports, and using the inspector outside forms. |
| [Testing](doc/testing.md) | The bundle's tests, and how to test a protected form. |
| [Reference](doc/reference.md) | Every type of the bundle, with its role. |

## Quick start

```sh
composer require phil/spam-protection-bundle
```

```php
$builder->setDefault('spam_protection', ['scope' => 'contact', 'content_fields' => ['message']]);
```

With StimulusBundle, the proof-of-work controller is found from the bundle's `assets/package.json`.

## Requirements

PHP 8.5 or later with `ext-sodium`, `phil/spam-protection`, `phil/bot-detection-bundle`, and Symfony 8.2's form,
HTTP foundation and translation contracts; a PSR-6 cache pool and a PSR-20 clock. Optional: `symfony/rate-limiter`. In the browser: Stimulus 3, through StimulusBundle.

## Contributing

Contributions are welcome on the PHIL monorepo: read the [contribution guide](CONTRIBUTING.md) and the
[code of conduct](CODE_OF_CONDUCT.md). Report a vulnerability privately, as the [security policy](SECURITY.md)
explains, never in a public issue.

## License

MIT, © Philippe Gamache, doing business as Le Gnome Archiviste; a Parler Haut Interagir Librement project. See [LICENSE](LICENSE).
