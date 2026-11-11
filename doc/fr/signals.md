<!-- phil-dev source-sha256=16641294bf33df9cba8c11d8ef15bbb1b45c23202c3115f824b055c43d3ebd18 -->
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
| `token_replayed` | Le jeton a déjà été envoyé. Vérifié seulement sur un jeton authentique et à temps. | `cache_pool` |
| `too_long` | Un texte contient plus de caractères que permis ; seul ce nombre est lu pour les trois signaux suivants. | `content.maximum_length` |
| `too_many_links` | Un texte contient plus d'adresses `http://`, `https://` ou `www.` que permis, chacune comptée une fois. | `content.maximum_links` |
| `forbidden_term` | Un texte contient un terme interdit comme mot entier ou suite entière de mots, sans égard à la casse, aux accents ni à la ponctuation. | `content.forbidden_terms` |
| `gibberish` | Un texte a un mot de 12 lettres ou plus sans voyelle, ou de 20 lettres ou plus dont moins d'une sur huit est une voyelle (`y` compte). | `content.detect_gibberish` |
| `rate_limited` | Le client a envoyé le formulaire plus souvent que le limiteur ne le permet. | `rate_limiter` |

**Un jeton n'est revendiqué que s'il est authentique et à temps**, pour que les jetons falsifiés ou périmés ne
remplissent jamais le cache des jetons utilisés. **La limite de débit compte chaque envoi** d'une requête qui a une
adresse de client, retenu ou non, pour qu'un robot ne puisse pas la remettre à zéro en passant les autres
vérifications.
