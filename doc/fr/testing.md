<!-- phil-dev source-sha256=5f5190eea02201bf215f37dfea5be5d304544affe7d5a0158d16e9320b57de48 -->
# Tests

## Les tests du bundle

`tests/Unit` couvre l'inspecteur avec des ports en mémoire et une horloge simulée ; les adaptateurs sur un cache en
tableau, un limiteur de débit en mémoire et une pile de requêtes ; l'extension de formulaire par une fabrique de
formulaires ; la configuration du bundle sur un `ContainerBuilder`, et `Verdicts`. Les signaux eux-mêmes sont testés
dans `phil/spam-protection`. `tests/Functional` rend et envoie un formulaire de contact dans un noyau à lui, comme une
personne — preuve résolue — et comme des robots. `tests/js` exécute le contrôleur Stimulus et son calcul sous
l'exécuteur de tests de Node, Stimulus étant résolu en *stub* par `tests/js/register.mjs`.

```sh
vendor/bin/phpunit packages/spam-protection-bundle/tests
node --import ./packages/spam-protection-bundle/tests/js/register.mjs --test packages/spam-protection-bundle/tests/js/proof-of-work.test.mjs
```

Chaque mutant de `src/` est tué par les tests PHP. Quand le paquet est installé seul, la suite fonctionnelle est
sautée : avec `symfony/event-dispatcher` requis pour le développement seulement, le conteneur de Symfony laisse de côté
le répartiteur d'événements et aucun noyau ne démarre.

## Tester un formulaire protégé

Dans un test fonctionnel, produisez le rendu du formulaire, lisez-y le jeton et le défi, résolvez la preuve avec
`ProofOfWork::verify()` dans une boucle, et envoyez après `timing.minimum` secondes — ou configurez
`timing.minimum: 0` et `proof_of_work: 0` dans l'environnement de test.
