# Formule de coût annuel d'un agent

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, type:feature, priority:high
**Dépend de:** #12, #13

## Contexte

Coût employeur annuel par agent, à la date simulée, depuis le jeu de paramètres choisi (spec §Coût annuel d'un agent) :

```
C = TIB·(1 + p) + TIB·r_retraite + TIB·(1 + p)·r_autres + min(TIB·p, 0,2·TIB)·r_RAFP
```

- TIB = indice majoré de l'échelon × valeur annuelle du point.
- p = taux de primes du corps.
- r_retraite = taux employeur du régime du profil.
- r_autres = autres cotisations employeur par versant (maladie, famille, FNAL, mobilité…).
- Dernier terme = RAFP, assise sur les primes dans la limite de 20 % du TIB.

La formule est celle des **titulaires** ; les contractuels auront leur propre règle (hors V1).

## Objectif

Implémenter le calcul du coût annuel d'un agent titulaire, chaque terme porté par une règle enfichable.

## Tâches

- [ ] Calcul du TIB (indice majoré × point annuel) à la date simulée.
- [ ] Chaque terme (traitement+primes, retraite, autres cotisations, RAFP) comme composant séparé.
- [ ] Plafonnement RAFP à 20 % du TIB.
- [ ] Toutes les valeurs prises à la date simulée depuis le jeu de paramètres.
- [ ] Test chiffré sur un cas connu (un échelon des deux profils V1).

## Critères d'acceptation

- Pour un échelon donné et une date donnée, C est reproductible et décomposable terme à terme.
- Le terme RAFP est bien plafonné quand p dépasse 20 %.
- Les composantes hors retraite sont identiques quelle que soit la vue de coût (préparé pour #18).

## Références

Spec §Coût annuel d'un agent
