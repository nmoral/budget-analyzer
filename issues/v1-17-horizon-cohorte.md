# Horizon cohorte (année par année, avancement)

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, type:feature
**Dépend de:** #14

## Contexte

Cohorte = coût année par année des N agents recrutés, de l'entrée à un horizon choisi (10 ans par défaut, carrière complète en option), avec **avancement d'échelon**. Pas de départs en V1 (l'attrition viendra plus tard).

## Objectif

Produire la série annuelle du coût d'une cohorte de N agents avec progression d'échelon selon la grille.

## Tâches

- [ ] Progression d'échelon année par année selon la durée en mois de chaque échelon (#05).
- [ ] Série de coûts de l'année d'entrée à l'horizon (10 ans par défaut, carrière complète en option).
- [ ] Pas de départs ni d'attrition en V1.
- [ ] Valeurs prises à la date de chaque année simulée (point gelé par défaut, revalorisation paramétrable #18/hypothèses).

## Critères d'acceptation

- Pour N agents entrant à l'échelon 1, le coût annuel reflète le passage à l'échelon suivant quand la durée est atteinte.
- La série couvre bien l'horizon demandé.

## Références

Spec §Trois horizons (3)
