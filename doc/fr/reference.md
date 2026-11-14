<!-- phil-dev source-sha256=75bb1abe8286c5b3014d60b16335c2c6e287912eef6ed4b7a5da0d20f2bdd851 -->
# Référence

| Type | Rôle |
|---|---|
| `PhilSpamProtectionBundle` | La classe du bundle : sa configuration et ses services. |
| `Application\Verdicts` | Une inspection sous forme du `Verdict` de `phil/bot-detection-bundle`. |
| `Application\SubmissionInspector` | Émet la protection d'un formulaire et inspecte un envoi. |
| `Application\Protection`, `Application\Submission` | Ce que porte un formulaire rendu ; ce que rapporte un envoi. |
| `Application\UsedTokens`, `Application\SubmissionRateLimiter` | Les ports. |
| `Infrastructure\Cache\CacheUsedTokens` | Les jetons utilisés dans un pool PSR-6, sous un verrou Symfony quand on en donne un. |
| `Infrastructure\Doctrine\DbalUsedTokens` | Les jetons utilisés dans une table, une insertion atomique par revendication ; `purge()`, `insertion()`. |
| `Infrastructure\Doctrine\UsedTokensTable` | La table de `DbalUsedTokens` : `NAME`, `NONCE_LENGTH`, `configure()`. |
| `Infrastructure\Doctrine\UsedTokensSchemaListener` | Ajoute cette table au schéma que l'ORM génère pour sa connexion. |
| `Infrastructure\RateLimit\SymfonySubmissionRateLimiter` | La limite de débit sur un limiteur de débit de Symfony. |
| `Infrastructure\Http\ClientKey` | L'empreinte à clé du réseau du client (adresse IPv4, ou `/64` IPv6) ; `network()` donne ce réseau. |
| `Infrastructure\Form\SpamProtectionTypeExtension` | L'option `spam_protection` de chaque formulaire. |

Les signaux, les jetons, la durée, la preuve de travail et les règles de contenu sont ceux de `phil/spam-protection`,
sous `Phil\SpamProtection`.
