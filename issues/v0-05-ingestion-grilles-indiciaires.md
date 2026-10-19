# Ingestion des grilles indiciaires des deux profils V1

**Milestone:** V0 — Socle de données
**Labels:** area:data, type:feature
**Dépend de:** #02

## Contexte

Grilles par corps (décrets statutaires, API Légifrance / PISTE). Donnent, par échelon, l'indice brut, l'indice majoré et la durée en mois — base de la carrière dans les horizons régime permanent et cohorte. Profils V1 : professeur des écoles (FPE) et infirmier en soins généraux (FPH).

## Objectif

Produire les CSV de grilles et d'échelons datés pour les deux corps V1.

## Tâches

- [ ] Script d'extraction depuis le décret statutaire / Légifrance par corps et grade.
- [ ] CSV grille (corps, grade, version, début, fin, source) + CSV échelons (grille, rang, indice brut, indice majoré, durée en mois).
- [ ] Les deux corps V1 couverts : professeur des écoles, infirmier en soins généraux.
- [ ] Brut + manifeste.

## Critères d'acceptation

- Les indices majorés sont strictement croissants par rang dans une grille (vérifié en #08).
- Chaque grille porte sa version, ses dates d'effet et sa source (article du décret).
- Les deux corps V1 sont présents.

## Références

Spec §Sources de données · §Modèle de données (Grille, Échelon) · §Périmètre V1
