<!-- phil-dev source-sha256=a591eb34835fe190df3cf1f563c04715b97e5f83bfb236cfa6cf405a8db152a2 -->
# Installation

## Prérequis

| Prérequis | Pourquoi |
|---|---|
| PHP 8.5 ou plus récent, `ext-sodium` | L'hexadécimal du nonce, à temps constant. |
| `phil/spam-protection` | Les signaux, les jetons, la durée, la preuve de travail et les règles de contenu. |
| `phil/bot-detection-bundle` | Le `Verdict` que donne une inspection. |
| `symfony/form`, `symfony/options-resolver` | L'option `spam_protection` de chaque formulaire. |
| `symfony/http-foundation` | L'adresse du client, tirée de la requête courante, pour la clé de la limite de débit. |
| `symfony/translation-contracts` | Le message d'un envoi retenu. |
| `psr/cache`, `psr/clock`, `psr/log` | Les jetons utilisés, l'heure, et le journal des envois retenus. |
| `symfony/rate-limiter` (facultatif) | La limite de débit par formulaire et par client. |

L'application enregistre FrameworkBundle, avec les formulaires, un pool de cache et le service `clock`.

## Installer

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

## Le contrôleur Stimulus

La preuve de travail est résolue dans le navigateur par un contrôleur Stimulus fourni avec le bundle, déclaré dans
`assets/package.json` comme les bundles Symfony UX déclarent les leurs. Avec StimulusBundle et AssetMapper ou Webpack
Encore, il est trouvé comme n'importe quel contrôleur UX :

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

L'identifiant du contrôleur est `phil--spam-protection-bundle--proof-of-work` ; l'extension de formulaire le place
sur le champ de preuve avec ses deux valeurs. Une page sans le contrôleur n'envoie aucune preuve : avec une
difficulté au-dessus de 0, ses formulaires sont retenus. Réglez `proof_of_work: 0` jusqu'à ce que le contrôleur soit
chargé partout ([Preuve de travail](proof-of-work.md)).
