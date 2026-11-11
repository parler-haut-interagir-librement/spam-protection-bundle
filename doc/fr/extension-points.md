<!-- phil-dev source-sha256=a23efe745c46e7a71aaef31aa167dad2d365029aca955b886c92f19ee1af8bef -->
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

Le bundle l'implémente sur un pool PSR-6 (`CacheUsedTokens`). PSR-6 n'a pas d'« ajout si absent » atomique : sa
revendication lit, écrit un marqueur à elle, puis relit. De deux envois qui écrivent l'un après l'autre, celui dont le
marqueur a été écrasé est refusé. Deux envois qui relisent chacun avant que l'autre n'écrive peuvent quand même
passer tous les deux : un robot qui rejoue un jeton en une rafale parallèle serrée peut donc faire passer quelques
envois ; les autres signaux et la limite de débit s'appliquent quand même à chacun. Un stockage qui doit fermer cette
fenêtre implémente `claim()` sur une primitive atomique : une insertion dans une table avec une clé unique sur le
nonce, qui échoue sur un doublon, ou Redis `SET key value NX EX ttl`.

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

if ($inspection->isSpam()) {
    // refuse, or add friction — Verdicts::of($inspection) is a bot-detection Verdict
}
```
