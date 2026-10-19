# Taux de primes moyen par corps

**Milestone:** V0 — Socle de données
**Labels:** area:data, type:feature
**Dépend de:** #02

## Contexte

En V1 les primes sont un taux moyen observé par corps (primes/TIB), pas un codage règle par règle. Choix de la source (spec §Choix de la source du taux de primes) :

1. Source la plus proche du métier publiant la décomposition traitement/primes (DREES, Insee, DGAFP).
2. À défaut, moyenne versant × catégorie DGAFP, **signalée en clair** sur la fiche (ex. « taux approché : moyenne catégorie A FPH »).
3. Source retenue et raison du choix documentées dans le catalogue du profil.

Infirmiers FPH : vérifier d'abord l'étude annuelle DREES sur les salaires hospitaliers, sinon repli.

## Objectif

Produire le CSV des taux de primes par corps pour les deux profils V1, avec marquage du repli éventuel.

## Tâches

- [ ] Script/saisie du taux de primes (corps, valeur, année d'observation, source).
- [ ] Champ indiquant si la valeur est une observation directe ou un repli versant × catégorie.
- [ ] Documenter la raison du choix de source dans le catalogue du profil.

## Critères d'acceptation

- Chaque taux porte son année d'observation, sa source et le drapeau repli oui/non.
- Le taux est dans des bornes plausibles (vérifié en #08).

## Références

Spec §Choix de la source du taux de primes · §Modèle de données (Taux de primes)
