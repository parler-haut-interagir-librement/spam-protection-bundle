<!-- phil-dev source-sha256=914b8ec4948c2ff68db631fd3df1028c52fa5811940e601bcd830a404780ee88 -->
# Référence

| Type | Rôle |
|---|---|
| `PhilSpamProtectionBundle` | La classe du bundle : sa configuration et ses services. |
| `Application\Verdicts` | Une inspection sous forme du `Verdict` de `phil/bot-detection-bundle`. |
| `Application\SubmissionInspector` | Émet la protection d'un formulaire et inspecte un envoi. |
| `Application\Protection`, `Application\Submission` | Ce que porte un formulaire rendu ; ce que rapporte un envoi. |
| `Application\UsedTokens`, `Application\SubmissionRateLimiter` | Les ports. |
| `Infrastructure\Cache\CacheUsedTokens` | Les jetons utilisés dans un pool PSR-6. |
| `Infrastructure\RateLimit\SymfonySubmissionRateLimiter` | La limite de débit sur un limiteur de débit de Symfony. |
| `Infrastructure\Http\ClientKey` | L'empreinte à clé du réseau du client (adresse IPv4, ou `/64` IPv6) ; `network()` donne ce réseau. |
| `Infrastructure\Form\SpamProtectionTypeExtension` | L'option `spam_protection` de chaque formulaire. |

Les signaux, les jetons, la durée, la preuve de travail et les règles de contenu sont ceux de `phil/spam-protection`,
sous `Phil\SpamProtection`.
