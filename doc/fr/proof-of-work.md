<!-- phil-dev source-sha256=d694a84aea5e27125eb0f1254e2759ecbfb578e63301ac577efe7b3eb6323558 -->
# Preuve de travail

Avant l'envoi, le navigateur trouve le plus petit nombre `n` tel que le SHA-256 de `challenge:n` commence par
`difficulty` bits à zéro, et l'écrit dans `_phil_spam_proof`. Le défi est le nonce du jeton : une preuve ne vaut donc
que pour un seul rendu. Le serveur la vérifie avec un seul calcul d'empreinte.

| Difficulté | Empreintes en moyenne | Sur un portable (environ 500 000 empreintes par seconde) |
|---|---|---|
| 8 | 256 | Instantané |
| 12 (par défaut) | 4 096 | Environ 10 ms |
| 16 | 65 536 | Environ 0,15 s |
| 20 | environ un million | Environ 2 s |
| 24 (le maximum) | environ 16 millions | Environ 30 s |

Les durées sont des moyennes mesurées avec le SHA-256 du contrôleur lui-même sous Node, sur un portable récent ; un
défi isolé peut prendre plusieurs fois sa moyenne, et un téléphone de milieu de gamme est plusieurs fois plus lent. Un
formulaire envoyé avant que la preuve soit trouvée est retenu : gardez donc la difficulté à 16 ou moins sur les
formulaires que des gens remplissent. 20 et 24 conviennent aux points de terminaison où un délai de quelques secondes
est acceptable.

Une personne paie le coût une fois, pendant qu'elle lit le formulaire ; un robot qui publie des milliers d'envois le
paie à chaque fois. À elle seule, la preuve n'arrête rien, mais elle ajoute un coût à tout le reste.

## Le contrôleur

`assets/src/proof_of_work_controller.js` est un contrôleur Stimulus sur `assets/src/proof_of_work.js`, un module sans
dépendance qui calcule lui-même le SHA-256 (FIPS 180-4), de façon synchrone : un appel Web Crypto attendu par
candidat coûterait un aller-retour de promesse par empreinte, plusieurs fois le coût de l'empreinte. La recherche rend
la main au navigateur après environ une image de travail (16 ms) : la page reste réactive pendant qu'elle s'exécute.
Quand le champ de preuve se connecte — la page se charge, ou un formulaire arrive plus tard dans une fenêtre modale ou
un cadre Turbo —, le contrôleur résout le défi de sa valeur `challenge` à sa valeur `difficulty` et écrit la solution
dans le champ. Il ne lit rien d'autre et n'envoie rien nulle part.

```html
<input type="hidden" name="contact[_phil_spam_proof]"
       data-controller="phil--spam-protection-bundle--proof-of-work"
       data-phil--spam-protection-bundle--proof-of-work-challenge-value="0f3a…"
       data-phil--spam-protection-bundle--proof-of-work-difficulty-value="12">
```

Une solution ne comporte que des chiffres décimaux, au plus 12 ; tout le reste est refusé avant le calcul de
l'empreinte. Sans le contrôleur, un formulaire dont la difficulté est au-dessus de 0 est toujours retenu : désactivez
la preuve — `proof_of_work: 0` — sur une application qui ne peut pas le charger.
