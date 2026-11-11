<!-- phil-dev source-sha256=6bb533c350c1e094f0eafd37b0c4f8416599a36a6fe7743bc0d23a1ac0f517e6 -->
# Protéger un formulaire

Tout formulaire accepte l'option `spam_protection` :

```php
public function configureOptions(OptionsResolver $resolver): void
{
    $resolver->setDefaults([
        'data_class' => ContactRequest::class,
        'spam_protection' => ['scope' => 'contact', 'content_fields' => ['message', 'subject']],
    ]);
}
```

| Valeur | Sens |
|---|---|
| `false` (par défaut) | Aucune protection. |
| `true` | Une protection, dont la portée est le nom du formulaire, sans texte inspecté. |
| `['scope' => …, 'content_fields' => […]]` | La portée — le formulaire auquel appartiennent le jeton et la limite de débit — et les champs dont les règles de contenu lisent le texte. Un champ qui n'est pas une chaîne, ou une liste qui n'est pas une liste de noms, est refusé. |

## Les trois champs

Le formulaire reçoit trois champs, aucun lié à ses données :

- **`website_url`**, le champ piège : un champ texte déplacé hors de l'écran, caché aux technologies d'assistance,
  hors de l'ordre de tabulation et sans saisie automatique. Une personne ne le remplit jamais ; un robot qui remplit
  tous les champs, si. Ne donnez ce nom à aucun champ à vous.
- **`_phil_spam_token`**, caché : le jeton signé émis à la construction du formulaire.
- **`_phil_spam_proof`**, caché : là où le contrôleur Stimulus `phil--spam-protection-bundle--proof-of-work` écrit la
  preuve de travail, à partir du défi et de la difficulté de ses valeurs.

Ils sont rendus avec le reste du formulaire — `form_rest(form)` ou `form_end(form)` les couvre.

## Un envoi retenu

Après l'envoi, l'inspecteur lit les trois champs et les `content_fields`. Un envoi retenu comme pourriel reçoit **une
seule erreur sur le formulaire**, `phil_spam_protection.rejected` dans le domaine `phil_spam_protection` — « This
submission could not be accepted. Please try again. » —, qui ne dit jamais pourquoi : dire à un robot quelle
vérification il a ratée lui apprend à la passer. La cause de l'erreur est l'`Inspection`, avec les signaux.

Les raisons vont au journal, sur le canal `spam_protection`, au niveau warning, avec la portée et les signaux —
jamais l'adresse, le jeton ni le texte.

Un formulaire réaffiché après son envoi — retenu comme pourriel, ou avec un champ à corriger — affiche le jeton émis
pour cette requête et une preuve vide, jamais le jeton envoyé. Le jeton envoyé est revendiqué par l'inspection, et le
défi de la preuve sur la page est le nonce du nouveau jeton. Une personne dont l'envoi est retenu — un gestionnaire de
mots de passe a rempli le champ piège, la page est restée ouverte des heures — ou qui corrige un champ envoie de
nouveau avec ce nouveau jeton, et n'est pas retenue pour avoir rejoué l'ancien.
