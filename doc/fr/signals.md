<!-- phil-dev source-sha256=8f035f237c951e18838ea9a8a5492b104e623da118aebd70e6e9e54571f64015 -->
# Signaux

Chaque signal est une observation que fait le serveur lui-même, jamais une affirmation du client. L'inspection lève
tous ceux qu'elle trouve, dans cet ordre.

| Signal | Levé quand | Se règle avec |
|---|---|---|
| `honeypot_filled` | Le champ caché `website_url` contient quoi que ce soit. | — |
| `token_missing` | L'envoi ne porte aucun jeton : le formulaire n'a pas été rendu ici. | — |
| `token_forged` | La signature du jeton ne correspond pas, ou il appartient à une autre portée. | `secret` |
| `proof_invalid` | La preuve de travail est absente ou fausse. Vérifiée seulement sur un jeton authentique. | `proof_of_work` |
| `too_fast` | Moins de `timing.minimum` secondes depuis le rendu du formulaire. | `timing.minimum` |
| `token_expired` | Plus de `timing.maximum` secondes depuis le rendu du formulaire. | `timing.maximum` |
| `token_replayed` | Le jeton a déjà été envoyé. Vérifié seulement sur un jeton authentique et à temps. | `cache_pool`, `used_tokens` |
| `malformed` | Un texte n'est pas de l'UTF-8 valide, ce qu'aucun navigateur n'envoie ; il est lu malgré tout pour les quatre signaux suivants, chaque séquence invalide remplacée. | — |
| `too_long` | Un texte contient plus de caractères que permis ; seul ce nombre est lu pour les trois signaux suivants. | `content.maximum_length` |
| `too_many_links` | Un texte contient plus d'adresses `http://`, `https://` ou `www.` que permis, chacune comptée une fois. | `content.maximum_links` |
| `forbidden_term` | Un texte contient un terme interdit comme mot entier ou suite entière de mots, sans égard à la casse, aux accents ni à la ponctuation. | `content.forbidden_terms` |
| `gibberish` | Un texte a un mot de 12 lettres ou plus sans voyelle, ou de 20 lettres ou plus dont moins d'une sur huit est une voyelle (`y` compte). | `content.detect_gibberish` |
| `rate_limited` | Le client a envoyé le formulaire plus souvent que le limiteur ne le permet. | `rate_limiter` |

**Un jeton n'est revendiqué que s'il est authentique et à temps**, pour que les jetons falsifiés ou périmés ne
remplissent jamais le cache des jetons utilisés. **La limite de débit compte chaque envoi** d'une requête qui a une
adresse de client, retenu ou non, pour qu'un robot ne puisse pas la remettre à zéro en passant les autres
vérifications.

## Refus pondérés

Par défaut, un seul signal refuse un envoi. Une application qui trouve un signal trop strict à lui seul — une personne
qui tape vite et lève `too_fast`, un formulaire de soutien où l'on colle plusieurs liens — lui donne un poids plus
léger : chaque signal levé ajoute son poids, et un score qui atteint le seuil refuse.

```yaml
phil_spam_protection:
    refusal:
        threshold: 100
        weights:
            too_fast: 60          # alone, accepted; with another light signal, refused
            too_many_links: 40
            gibberish: 50
```

| Signaux levés | Score | Résultat |
|---|---|---|
| `too_fast` | 60 | Accepté, journalisé au niveau `info` |
| `too_fast`, `too_many_links` | 100 | Refusé |
| `honeypot_filled` | 100 | Refusé : un signal non listé pèse le seuil |
| `token_replayed` | 100 | Refusé, quels que soient les poids |

- **Un signal non listé pèse le seuil**, de sorte que la configuration n'allège jamais que les signaux qu'elle nomme.
- **`token_missing`, `token_forged` et `token_replayed` pèsent toujours le seuil**, et la configuration refuse un poids
  pour eux : ils sont certains, et un rejeu plus léger laisserait un jeton passer deux fois, ce que le stockage
  atomique des jetons utilisés existe pour empêcher
  ([Jetons à usage unique en production](configuration.md#jetons-à-usage-unique-en-production)).
- **Chaque signal compte une fois**, quel que soit le nombre de textes qui le lèvent.
- Un poids de 0 garde un signal dans le journal sans le laisser refuser ; le seuil et les poids sont bornés à partir de
  1, et de 0, jusqu'à 1000.
- Un envoi refusé est journalisé au niveau `warning` avec ses signaux, son score et le seuil ; un envoi accepté dont
  les signaux restent sous le seuil, au niveau `info`, pour qu'un poids trop léger se voie dans le journal.

`RefusalPolicy` est un service : un point d'accès qui appelle directement l'inspecteur lui pose la même question
([Points d'extension](extension-points.md#linspecteur-hors-des-formulaires)), et `Verdicts::of($inspection, $policy)`
donne la même réponse sous forme de verdict de détection de robots.
