# Dépôt de données et contrat de schéma (Frictionless)

**Milestone:** V0 — Socle de données
**Labels:** area:data, type:feature, priority:high
**Dépend de:** #27

## Contexte

Le dépôt de données est le seul point de passage entre l'agrégation Python et le rendu Symfony : aucune brique ne lit les sources de l'autre (spec §Architecture). Il vit dans `data/` à la racine du monorepo (#27). Toute valeur réglementaire est datée (début d'effet, fin éventuelle, source).

## Objectif

Poser la structure du dépôt de données et le contrat de schéma versionné partagé par les deux briques.

## Tâches

- [ ] Arborescence sous `data/` : `raw/` (fichiers bruts archivés), `data/` (CSV normalisés), `schemas/` (Frictionless Table Schema), `manifests/`.
- [ ] Un schéma Frictionless par type de CSV : paramètre daté, grille/échelon, taux de primes, coûts moyens PAP.
- [ ] Convention de normalisation CSV : UTF-8, dates ISO 8601, point décimal, une ligne par valeur datée.
- [ ] Versionner le contrat ; documenter la règle « changement incompatible = incrément de version majeure ».
- [ ] README du dépôt décrivant le contrat et le flux (brut → script → CSV → manifeste).

## Critères d'acceptation

- Les schémas valident un CSV d'exemple conforme et rejettent un CSV non conforme (champ manquant, date non ISO, décimale à virgule).
- La version du contrat est lisible programmatiquement (fichier de version dédié).

## Références

Spec §Architecture · §Contrat de données · §Agrégation (Python)
