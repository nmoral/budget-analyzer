# Commande d'import et jeu de paramètres versionné

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, type:feature, priority:high
**Dépend de:** #01, #10

## Contexte

Une commande d'import valide le dépôt de données à un commit donné, charge Postgres dans une transaction et enregistre le jeu de paramètres (spec §Rendu). Les CSV ne sont jamais lus à l'exécution, seulement à l'import. La reproductibilité repose sur la version = commit du dépôt.

## Objectif

Commande CLI Symfony d'import d'un commit du dépôt de données vers Postgres, atomique et traçable.

## Tâches

- [ ] Commande `import` (nom impératif) prenant la référence de commit du dépôt.
- [ ] Validation côté Symfony contre le contrat de schéma (#01) avant chargement.
- [ ] Chargement dans une transaction unique (tout ou rien).
- [ ] Enregistrement du jeu de paramètres : identifiant de version = commit, date d'import.
- [ ] Vérification de cohérence des manifestes (empreintes).

## Critères d'acceptation

- Un import échoué (CSV invalide) ne laisse aucune donnée partielle en base.
- Après import, le jeu de paramètres référence le commit importé et sa date.
- Deux imports du même commit sont reproductibles.

## Références

Spec §Rendu (Symfony) · §Modèle de données (Jeu de paramètres)
