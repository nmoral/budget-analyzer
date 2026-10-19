# Ingestion des taux de cotisation employeur (CAS civil, CNRACL)

**Milestone:** V0 — Socle de données
**Labels:** area:data, type:feature
**Dépend de:** #02

## Contexte

Charges employeur de retraite par régime (décrets CAS Pensions et CNRACL, fiches annuelles des CDG). Valeurs connues au 19 octobre 2026, à vérifier à l'ingestion :

- CAS Pensions civil : **82,28 %** du TIB depuis le 2026-01-01 (78,28 % en 2025).
- CAS Pensions militaires : **126,07 %** en 2025.
- CNRACL employeur : **37,65 %** en 2026, **40,65 %** en 2027, **43,65 %** en 2028 (décret n° 2025-86).

## Objectif

Produire le CSV des taux de cotisation employeur datés, par régime.

## Tâches

- [ ] Script d'ingestion : clés `taux_cas_civil`, `taux_cas_militaire`, `taux_cnracl`, unité % du TIB.
- [ ] Une ligne par valeur datée (le CNRACL a une trajectoire 2026→2028 : trois lignes).
- [ ] Prévoir les autres cotisations employeur par versant (maladie, famille, FNAL, mobilité…) pour `r_autres` du moteur.
- [ ] Brut + manifeste par source (CDG, Retraites de l'État, décret).

## Critères d'acceptation

- Les valeurs ci-dessus figurent avec leur date d'effet et leur source.
- Les périodes d'effet d'une même clé ne se chevauchent pas (vérifié en #08).

## Références

Spec §Sources de données · [CDG 33 2026] · [Retraites de l'État 2026] · [Décret n° 2025-86]
