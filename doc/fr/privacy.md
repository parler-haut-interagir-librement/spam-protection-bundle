<!-- phil-dev source-sha256=5a0d4860171ab8318dc6b2ce5d3d3b2ac7282d29ac5e5b2236b2dd488e36771b -->
# Vie privée

| Donnée | Gardée | Pendant combien de temps |
|---|---|---|
| Le nonce du jeton | Dans le stockage des jetons utilisés : le pool de cache, ou la table de `used_tokens.connection` | `timing.maximum` secondes ; dans la table, jusqu'à la prochaine purge |
| Une empreinte à clé du réseau du client, par formulaire : l'adresse IPv4, ou le `/64` d'une adresse IPv6 | Dans le stockage du limiteur de débit | La fenêtre du limiteur |
| La portée du formulaire et les signaux d'un envoi retenu | Dans le journal, canal `spam_protection` | La durée de rétention du journal |

**Jamais recueillis** : l'adresse elle-même, les témoins, les empreintes de navigateur, le comportement de la souris
ou du clavier, les scores de réputation de tiers. **Jamais envoyé nulle part** : le contrôleur Stimulus calcule une
empreinte localement et écrit un nombre dans le formulaire.

L'empreinte du client est un HMAC-SHA256 sous le secret de l'application : on ne peut pas la ramener à une adresse
sans le secret, et elle change avec lui. Une adresse IPv6 est hachée sous la forme de son `/64`, le bloc qu'un hôte
reçoit et dans lequel il peut faire tourner ses adresses librement, pour qu'en changer n'échappe pas à la limite de
débit ; une adresse IPv4 écrite en IPv6 (`::ffff:203.0.113.7`) est hachée comme l'adresse IPv4. Le jeton contient la
portée, une date et un nonce aléatoire — rien sur la personne.
