# Testing

## The bundle's tests

`tests/Unit` covers the inspector with in-memory ports and a mock clock; the adapters over an array
cache, an in-memory rate limiter and a request stack; the form extension through a form factory; and the bundle's
configuration on a `ContainerBuilder`, and `Verdicts`. The signals themselves are tested in `phil/spam-protection`. `tests/Functional` renders and submits a contact form in a kernel of its own,
as a person — the proof solved — and as robots. `tests/js` runs the Stimulus controller and its computation under
Node's test runner, Stimulus resolved to a stub by `tests/js/register.mjs`.

```sh
vendor/bin/phpunit packages/spam-protection-bundle/tests
node --import ./packages/spam-protection-bundle/tests/js/register.mjs --test packages/spam-protection-bundle/tests/js/proof-of-work.test.mjs
```

Every mutant of `src/` is killed by the PHP tests. Installed on its own, the functional suite is skipped: with
`symfony/event-dispatcher` required for development only, Symfony's container leaves the event dispatcher out and
no kernel boots.

## Testing a protected form

In a functional test, render the form, read the token and the challenge from it, solve the proof with
`ProofOfWork::verify()` in a loop, and submit after `timing.minimum` seconds — or configure `timing.minimum: 0` and
`proof_of_work: 0` in the test environment.
