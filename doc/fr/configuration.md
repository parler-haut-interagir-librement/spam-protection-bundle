<!-- phil-dev source-sha256=ba2faa0faaf61045870d5bc1055332122e000d6ecdfcc476d14ea00c081db167 -->
# Configuration

L'alias est `phil_spam_protection`. Chaque clé est facultative.
| `refusal.threshold` | `100` | Le score qui refuse un envoi, de 1 à 1000 ([Refus pondérés](signals.md#refus-pondérés)). |
| `refusal.weights` | `{}` | Signal → poids, de 0 à 1000 ; un signal non listé pèse le seuil, et refuse seul. `token_missing`, `token_forged` et `token_replayed` n'acceptent aucun poids : ils refusent toujours. |

```yaml
phil_spam_protection:
    secret: '%kernel.secret%'
    timing: { minimum: 3, maximum: 3600 }
    proof_of_work: 12
    content: { maximum_length: 20000, maximum_links: 3, forbidden_terms: [], detect_gibberish: true }
    cache_pool: cache.app
    used_tokens: { connection: null, lock_factory: null }
    rate_limiter: null
    refusal: { threshold: 100, weights: {} }
```

| Clé | Valeur par défaut | Sens |
|---|---|---|
| `secret` | `%kernel.secret%` | Signe les jetons et sert de clé à l'empreinte du client ; 16 octets ou plus. Le changer invalide les formulaires déjà rendus. |
| `timing.minimum` | `3` | Les secondes qu'une personne prend au moins pour remplir un formulaire ; un envoi plus rapide lève `too_fast`. 0 le désactive. |
| `timing.maximum` | `3600` | Les secondes après lesquelles un formulaire rendu est périmé ; un envoi plus tardif lève `token_expired`. Les jetons utilisés sont gardés aussi longtemps. |
| `proof_of_work` | `12` | Les bits de difficulté de la preuve du navigateur ; de 0 à 24, 0 la désactivant. Gardez-la à 16 ou moins sur les formulaires que des gens remplissent ([Preuve de travail](proof-of-work.md)). |
| `content.maximum_length` | `20000` | Les caractères qu'un texte peut contenir ; un texte plus long lève `too_long`, et seuls ses `maximum_length` premiers caractères sont lus. Un entier de 1 ou plus, ou `~` pour aucune borne. |
| `content.maximum_links` | `3` | Les liens qu'un texte peut contenir, chaque adresse comptée une fois ; un entier de 0 ou plus, ou `~` pour aucune limite. |
| `content.forbidden_terms` | `[]` | Des mots entiers, ou des suites de mots, qu'un texte ne peut pas contenir, comparés sans égard à la casse, aux accents ni à la ponctuation entre les mots. |
| `content.detect_gibberish` | `true` | Si un texte de caractères aléatoires est retenu. |
| `cache_pool` | `cache.app` | Le pool PSR-6 des jetons utilisés. Donnez-en un que tous les serveurs Web partagent. |
| `used_tokens.connection` | `null` | La connexion Doctrine dont une table garde les jetons utilisés, à la place du pool : `default`. Chaque revendication est une insertion atomique. Dans l'environnement `prod`, celle-ci ou `lock_factory` est exigée. |
| `used_tokens.lock_factory` | `null` | L'identifiant de service d'une fabrique de verrous Symfony sous laquelle le pool revendique chaque jeton : `lock.factory`. Son stockage doit être partagé par tous les serveurs Web. Pas avec `connection`. |
| `rate_limiter` | `null` | L'identifiant de service d'une fabrique de limiteurs de débit de Symfony — `limiter.contact` pour `framework.rate_limiter.contact` — ; aucun par défaut. Dans l'environnement `prod`, son stockage doit être partagé par tous les serveurs web, sinon la construction du conteneur échoue. |
| `single_server` | `false` | Accepte dans l'environnement `prod` un limiteur dont le stockage est local au serveur, pour une application sur un seul serveur. |

```yaml
# a rate limit of 5 submissions per hour, per form and client
framework:
    rate_limiter:
        forms: { policy: fixed_window, limit: 5, interval: '1 hour' }

phil_spam_protection:
    rate_limiter: limiter.forms
```

## Jetons à usage unique en production

Sans `used_tokens`, le pool revendique un jeton en lisant, en écrivant puis en relisant : deux envois d'un même jeton
au même moment peuvent passer tous les deux. Sur une rafale de huit envois simultanés d'un même jeton avec un pool sur
système de fichiers, de un à quatre sont passés. **Dans l'environnement `prod`, un stockage atomique est exigé** : sans `used_tokens.connection` ni
`used_tokens.lock_factory`, la construction du conteneur échoue et l'application ne démarre pas, avec ce message :

```
phil_spam_protection.used_tokens: in the prod environment, the used tokens need a store that is safe under
concurrent submissions. Set "used_tokens.connection" to a Doctrine connection, such as "default", or
"used_tokens.lock_factory" to a lock factory on a store every web server shares, such as "lock.factory". The cache
pool alone, in memory or in files, lets two submissions of one token both pass: it is accepted outside production only.
```

`single_server` n'y change rien : un seul serveur accepte aussi deux envois au même moment. En `dev` et en `test`, le pool
seul, comme un pool en tableau ou sur système de fichiers, continue de fonctionner. Choisissez l'un des deux :

```yaml
# a table of the Doctrine connection, created by the next migration
phil_spam_protection:
    used_tokens: { connection: default }
```

```yaml
# or the cache pool under a lock, both on a store every web server shares
framework:
    lock: '%env(LOCK_DSN)%'          # redis://…, a database…

phil_spam_protection:
    cache_pool: cache.shared
    used_tokens: { lock_factory: lock.factory }
```

Avec `connection`, la table `phil_spam_protection_used_token` rejoint le schéma que l'ORM génère pour cette
connexion : `doctrine:migrations:diff` écrit sa migration ; sans l'ORM, `UsedTokensTable::configure()` l'ajoute à un
schéma. `DbalUsedTokens::purge()` oublie les lignes expirées, depuis une tâche planifiée. Sous un verrou, chaque
revendication tient le verrou de son nonce au plus 10 secondes ; sur Redis, le `SET NX` du stockage des verrous donne
l'étape atomique. La clé du verrou et de l'élément de cache est `phil_spam_protection.` suivi du SHA-256 du nonce,
jamais le nonce lui-même, si bien qu'un stockage de verrous ou un pool qui journalise ou énumère ses clés ne détient
pas le nonce d'un jeton.

Une revendication que le pool ne garde pas est refusée : sous un verrou, quand `save()` répond `false` ; sans verrou,
aussi quand le marqueur ne peut pas être relu. Un pool qui ne garde rien, comme `cache.adapter.null`, refuse donc chaque
jeton.

## La limite de débit en production

Le bundle ajoute le `RateLimitGuard` de `phil/rate-limit` et lui confie le limiteur nommé par `rate_limiter`. Dans
l'environnement `prod`, un limiteur dont le stockage est local à chaque serveur — le `cache.rate_limiter` par défaut du
framework sur le système de fichiers, un pool `cache.adapter.array`, un `InMemoryStorage` — fait échouer la construction
du conteneur : chaque serveur compterait à part, et un client pourrait dépenser la limite une fois par serveur. Donnez
au limiteur un `cache_pool` sur un stockage partagé, ou réglez `single_server: true` sur un seul serveur.

## Services

| Service | Rôle |
|---|---|
| `Application\UsedTokens` | Un alias du stockage choisi ci-dessous ; faites-en l'alias d'un stockage à vous pour le remplacer. |
| `Infrastructure\Cache\CacheUsedTokens` | Les jetons utilisés dans `cache_pool`, sous `used_tokens.lock_factory` quand il est fixé ; sauf si `used_tokens.connection` est fixé. |
| `Infrastructure\Doctrine\DbalUsedTokens` | Les jetons utilisés dans une table de `used_tokens.connection`, seulement quand il est fixé. |
| `Infrastructure\Doctrine\UsedTokensSchemaListener` | Ajoute cette table au schéma que l'ORM génère pour la connexion, seulement quand `used_tokens.connection` est fixé. |
| `Infrastructure\RateLimit\SymfonySubmissionRateLimiter` | La limite de débit, seulement quand `rate_limiter` est fixé. |
| `Application\SubmissionInspector` | Émet et inspecte, construit par `SubmissionInspector::configured()` à partir des valeurs de la configuration ; utilisable hors des formulaires ([Points d'extension](extension-points.md)). |
| `Application\RefusalPolicy` | Les poids et le seuil de `refusal` : si une inspection refuse un envoi ; `score()`, `refuses()`. |
| `Infrastructure\Http\ClientKey` | L'empreinte à clé de l'adresse du client de la requête courante. |
| `Infrastructure\Form\SpamProtectionTypeExtension` | L'option `spam_protection`, étiquetée `form.type_extension` ; journalise sur le canal `spam_protection`. |
