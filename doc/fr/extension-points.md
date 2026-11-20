<!-- phil-dev source-sha256=65fe169a6f80faab845790cb8ea8ac14eedc1664cdb89c14d739a3182d87a637 -->
# Points d'extension

## UsedTokens

```php
interface UsedTokens
{
    public function claim(string $nonce, int $ttl): bool;
}
```

Enregistre un nonce comme utilisé pendant `ttl` secondes et indique s'il était nouveau. **Une revendication devrait
être atomique** : deux envois d'un même jeton au même moment ne doivent pas se faire dire tous les deux qu'il était
nouveau.

Le bundle en fournit trois, choisis par `used_tokens` ([Configuration](configuration.md#jetons-à-usage-unique-en-production)) :

| Stockage | Atomique | Comment |
|---|---|---|
| `DbalUsedTokens`, avec `used_tokens.connection` | Oui | Une insertion sur la clé primaire du nonce ; un doublon est refusé. |
| `CacheUsedTokens` sous `used_tokens.lock_factory` | Oui, avec un stockage de verrous partagé | Le verrou du nonce, puis une lecture et une écriture sous ce verrou. |
| `CacheUsedTokens` seul, par défaut | Non | Une lecture, un marqueur écrit, puis une relecture ; refusé dans l'environnement `prod`. |

PSR-6 n'a pas d'« ajout si absent » atomique : le pool seul lit, écrit un marqueur à lui, puis relit. De deux envois
qui écrivent l'un après l'autre, celui dont le marqueur a été écrasé est refusé. Deux envois qui relisent chacun avant
que l'autre n'écrive peuvent quand même passer tous les deux : un robot qui rejoue un jeton en une rafale parallèle
serrée peut donc faire passer quelques envois ; les autres signaux et la limite de débit s'appliquent quand même à
chacun. Un stockage à vous implémente `claim()` sur une primitive atomique — une insertion sur une clé unique qui
échoue sur un doublon, Redis `SET key value NX EX ttl` — et prend la place de celui du bundle par un alias de
`Application\UsedTokens`.

## SubmissionRateLimiter

```php
interface SubmissionRateLimiter
{
    public function consume(string $scope, string $client): bool;
}
```

Compte un envoi et indique s'il reste dans la limite. Le bundle adapte une fabrique de limiteurs de débit de Symfony
(`SymfonySubmissionRateLimiter`), avec la portée et le client comme clé.

## L'inspecteur hors des formulaires

Un point de terminaison d'API ou un récepteur de webhooks utilise l'inspecteur directement :

```php
$protection = $inspector->protect('newsletter');       // token, challenge, difficulty, for the client to echo

$inspection = $inspector->inspect(new Submission(
    scope: 'newsletter',
    honeypot: $payload['website_url'] ?? null,
    token: $payload['token'] ?? null,
    solution: $payload['proof'] ?? null,
    texts: [$payload['comment'] ?? ''],
    client: $clientKey->current(),
));

if ($refusal->refuses($inspection)) {     // $refusal: RefusalPolicy, from the container
    // refuse, or add friction — Verdicts::of($inspection, $refusal) is a bot-detection Verdict
}
```

`$inspection->isSpam()` dit si un signal a été levé ; `RefusalPolicy` les pèse comme la configuration le prévoit
([Refus pondérés](signals.md#refus-pondérés)).
