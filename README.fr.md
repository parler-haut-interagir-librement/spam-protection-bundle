<!-- phil-dev source-sha256=e31bd307aba05521219e82fdbe87d0b79f36c0a9f70a7f303c0af8d0032479c0 -->
# phil/spam-protection-bundle

**Tenez le pourriel hors de vos formulaires Symfony sans captcha, sans tiers et sans identifiant.**

phil/spam-protection-bundle protège un formulaire Symfony par des couches qu'une personne ne voit jamais. Un champ
piège attrape les robots naïfs. Un jeton signé à usage unique fixe un temps de remplissage minimal et maximal. Le
navigateur résout une preuve de travail sans que la personne ne s'en aperçoive. Des règles sur le texte attrapent les
liens, les termes interdits et le charabia, et une limite de débit s'applique par formulaire et par client.

Il s'adresse à toute application Symfony qui a des formulaires publics, comme des formulaires de contact,
d'inscription ou de commentaires, et qui veut rester agréable pour les gens qui les remplissent. Il n'affiche aucun
captcha, n'appelle aucun tiers, ne télécharge aucune liste de réputation d'adresses et ne garde aucun identifiant. La
limite de débit compte une empreinte à clé de l'adresse du client, et le journal nomme le formulaire et les raisons,
jamais le client.

Les signaux viennent de `phil/spam-protection`, la bibliothèque en PHP simple à utiliser hors de Symfony. Ce bundle
ajoute ce qui a besoin du cadriciel : une extension de type de formulaire, les jetons utilisés gardés dans un cache
PSR-6, le limiteur de débit de Symfony, et le contrôleur Stimulus de la preuve. `Verdicts` donne une inspection sous
forme de `Verdict` de `phil/bot-detection-bundle`. Un seul signal levé en fait un robot : une application compte donc
le pourriel comme n'importe quel autre verdict de robot.

## État

| Partie | État |
|---|---|
| Application : `SubmissionInspector`, `Verdicts`, les ports `UsedTokens` et `SubmissionRateLimiter` | Implémenté et testé |
| Infrastructure : extension de type de formulaire, jetons utilisés en PSR-6, limiteur de débit de Symfony, clé du client | Implémenté et testé |
| La preuve de travail du navigateur : un contrôleur Stimulus (`assets/src/proof_of_work_controller.js`) | Implémenté, testé avec Node |
| Un test de noyau d'un formulaire protégé | Implémenté |

## Documentation

| Document | Ce qu'il couvre |
|---|---|
| [Installation](doc/fr/installation.md) | Prérequis, installation, enregistrer le bundle et activer son contrôleur Stimulus. |
| [Configuration](doc/fr/configuration.md) | Chaque clé, sa valeur par défaut, et les services qu'enregistre le bundle. |
| [Protéger un formulaire](doc/fr/forms.md) | L'option `spam_protection`, les trois champs qu'elle ajoute, et ce que reçoit un envoi retenu. |
| [Signaux](doc/fr/signals.md) | Chaque signal, ce qui le lève, et comment le régler. |
| [Preuve de travail](doc/fr/proof-of-work.md) | Le calcul que fait le navigateur, son coût, et le script. |
| [Vie privée](doc/fr/privacy.md) | Ce qui est gardé, pendant combien de temps, et ce qui n'est jamais recueilli. |
| [Points d'extension](doc/fr/extension-points.md) | Les ports `UsedTokens` et `SubmissionRateLimiter`, et l'inspecteur hors des formulaires. |
| [Tests](doc/fr/testing.md) | Les tests du bundle, et comment tester un formulaire protégé. |
| [Référence](doc/fr/reference.md) | Chaque type du bundle, avec son rôle. |

## Démarrage rapide

```sh
composer require phil/spam-protection-bundle
```

```php
$builder->setDefault('spam_protection', ['scope' => 'contact', 'content_fields' => ['message']]);
```

Avec StimulusBundle, le contrôleur de preuve de travail est trouvé grâce au `assets/package.json` du bundle.

## Prérequis

PHP 8.5 ou plus récent avec `ext-sodium`, `phil/spam-protection`, `phil/bot-detection-bundle`, ainsi que les
composants form, HTTP foundation et translation contracts de Symfony 8.2 ; un pool de cache PSR-6 et une horloge
PSR-20. Facultatif : `symfony/rate-limiter`. Dans le navigateur : Stimulus 3, par StimulusBundle.

## Contribuer

Les contributions sont les bienvenues sur le monodépôt PHIL : lisez le [guide de contribution](CONTRIBUTING.md) et le
[code de conduite](CODE_OF_CONDUCT.md). Signalez une vulnérabilité en privé, comme l'explique la
[politique de sécurité](SECURITY.md), jamais dans un ticket public.

## Licence

MIT, © Philippe Gamache, doing business as Le Gnome Archiviste; a Parler Haut Interagir Librement project. Voir
[LICENSE](LICENSE).
