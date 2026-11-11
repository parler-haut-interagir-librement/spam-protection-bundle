<!-- phil-dev source-sha256=dc35ce65507b1fe95f0cef1d4216e94711c148dc39935f44c35840e31c437232 -->
# Configuration

L'alias est `phil_spam_protection`. Chaque clé est facultative.

```yaml
phil_spam_protection:
    secret: '%kernel.secret%'
    timing: { minimum: 3, maximum: 3600 }
    proof_of_work: 12
    content: { maximum_length: 20000, maximum_links: 3, forbidden_terms: [], detect_gibberish: true }
    cache_pool: cache.app
    rate_limiter: null
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
| `rate_limiter` | `null` | L'identifiant de service d'une fabrique de limiteurs de débit de Symfony — `limiter.contact` pour `framework.rate_limiter.contact` — ; aucun par défaut. |

```yaml
# a rate limit of 5 submissions per hour, per form and client
framework:
    rate_limiter:
        forms: { policy: fixed_window, limit: 5, interval: '1 hour' }

phil_spam_protection:
    rate_limiter: limiter.forms
```

## Services

| Service | Rôle |
|---|---|
| `Infrastructure\Cache\CacheUsedTokens` | Les jetons utilisés dans `cache_pool`. |
| `Infrastructure\RateLimit\SymfonySubmissionRateLimiter` | La limite de débit, seulement quand `rate_limiter` est fixé. |
| `Application\SubmissionInspector` | Émet et inspecte, construit par `SubmissionInspector::configured()` à partir des valeurs de la configuration ; utilisable hors des formulaires ([Points d'extension](extension-points.md)). |
| `Infrastructure\Http\ClientKey` | L'empreinte à clé de l'adresse du client de la requête courante. |
| `Infrastructure\Form\SpamProtectionTypeExtension` | L'option `spam_protection`, étiquetée `form.type_extension` ; journalise sur le canal `spam_protection`. |
