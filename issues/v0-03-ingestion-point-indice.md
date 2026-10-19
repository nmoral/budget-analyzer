# Ingestion du point d'indice

**Milestone:** V0 — Socle de données
**Labels:** area:data, type:feature
**Dépend de:** #02

## Contexte

La valeur du point d'indice (décrets, portail de la fonction publique) sert au calcul du TIB. Valeur connue au 19 octobre 2026 : **59,0734 € par an** (4,92278 €/mois), inchangée depuis le 1er juillet 2023 — à vérifier à l'ingestion.

## Objectif

Produire le CSV `data/point_indice.csv` (paramètre daté) à partir de la source officielle.

## Tâches

- [ ] Script d'ingestion du point d'indice, clé `point_indice`, unité €/an.
- [ ] Chaque valeur datée (début d'effet, fin éventuelle) avec sa source et son URL.
- [ ] Archiver le brut + manifeste (#02).

## Critères d'acceptation

- Le CSV contient au moins la valeur en vigueur (59,0734 €/an, effet 2023-07-01) avec source et date d'effet.
- Le CSV valide contre le schéma « paramètre daté ».

## Références

Spec §Sources de données · [Portail fonction publique]
