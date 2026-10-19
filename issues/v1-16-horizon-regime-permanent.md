# Horizon régime permanent (population stationnaire)

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, type:feature
**Dépend de:** #14

## Contexte

Régime permanent = coût annuel de N postes durablement pourvus. Hypothèse V1 : **population stationnaire**, chaque échelon pondéré par sa durée dans la carrière. Calibré contre le coût moyen chargé publié dans le PAP (#22).

## Objectif

Calculer le coût en régime permanent pour N postes, par moyenne pondérée des échelons.

## Tâches

- [ ] Coût moyen par agent = somme des coûts d'échelon pondérés par la durée de chaque échelon (depuis les grilles #05).
- [ ] Multiplication par N postes.
- [ ] Exposer l'hypothèse « population stationnaire » pour affichage sur la fiche.

## Critères d'acceptation

- Le coût moyen pondéré est reproductible pour un corps donné à une date donnée.
- La pondération par durée d'échelon est correcte (somme des poids = carrière complète).

## Références

Spec §Trois horizons (2)
