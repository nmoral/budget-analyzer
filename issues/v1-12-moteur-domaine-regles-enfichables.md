# Moteur de domaine PHP + règles enfichables par tag (EX-1)

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, type:feature, priority:high, concern:architecture
**Dépend de:** #10

## Contexte

Le moteur est un domaine PHP **sans dépendance à Doctrine**. Les spécificités (régime de retraite, régime indemnitaire, financeur, vue de coût) sont des implémentations d'interfaces, collectées par tag de service Symfony. **Aucun `if` sur un métier dans le moteur** : un profil est une donnée déclarative qui sélectionne ses règles (EX-1).

## Objectif

Poser l'ossature du moteur : interfaces de règles, mécanisme de sélection par profil, découverte par tag de service.

## Tâches

- [ ] Interfaces des familles de règles enfichables : régime de retraite, régime indemnitaire, financeur, vue de coût.
- [ ] Objets de domaine PHP purs (sans Doctrine) alimentés depuis le repository.
- [ ] Résolution : un profil déclare les codes de ses règles ; le moteur résout l'implémentation correspondante parmi les services taggés.
- [ ] Erreur claire si un profil référence une règle sans implémentation.
- [ ] Couche Domain isolée : règle Deptrac interdisant au domaine de dépendre de `Doctrine\*` et de l'infra (configurée dans #00, bloquante en CI).

## Critères d'acceptation

- Le moteur ne contient aucun `if`/`match` sur un code métier (corps/profil).
- Ajouter une implémentation d'interface taggée la rend découvrable sans modifier le moteur.
- Les objets de domaine n'importent rien de Doctrine, et Deptrac fait échouer la CI si cette frontière est franchie.

## Références

Spec EX-1 · §Moteur de calcul · §Rendu (Symfony)
