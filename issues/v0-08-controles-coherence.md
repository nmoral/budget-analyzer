# Contrôles de cohérence avant commit

**Milestone:** V0 — Socle de données
**Labels:** area:data, type:feature, type:quality
**Dépend de:** #01

## Contexte

Contrôles de cohérence exécutés avant commit, en plus de la validation de schéma (spec §Agrégation). Toute modification de données passe par une pull request dont le diff montre la valeur changée et sa source.

## Objectif

Fournir une commande de vérification des invariants métier sur les CSV, branchable en CI / pre-commit.

## Tâches

- [ ] Indices majorés strictement croissants par rang dans chaque grille.
- [ ] Périodes d'effet d'une même clé de paramètre sans chevauchement.
- [ ] Taux (cotisation, primes) dans des bornes plausibles configurables.
- [ ] Toute valeur porte une source non vide.
- [ ] Commande unique à lancer avant commit ; code de sortie non nul en cas d'anomalie.

## Critères d'acceptation

- Un jeu de données valide passe ; une grille à indices décroissants, un chevauchement de périodes et un taux hors bornes sont chacun détectés et rapportés.

## Références

Spec §Agrégation (contrôles de cohérence)
