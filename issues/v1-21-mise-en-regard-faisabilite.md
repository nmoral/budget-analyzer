# Mise en regard budgétaire et indicateur de faisabilité

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, area:frontend, type:feature
**Dépend de:** #19

## Contexte

La fiche met le coût en regard du budget du financeur et de la faisabilité RH (spec §Contenu d'une fiche de résultat). Données : concours (postes/candidats/admis, DGAFP), plafonds et schémas d'emplois (LFI, data.gouv), ONDAM/LFSS pour la FPH.

## Objectif

Calculer et afficher la mise en regard budgétaire et l'indicateur de faisabilité.

## Tâches

- [ ] Part du budget du financeur (programme 140 pour la FPE, ONDAM pour la FPH).
- [ ] Équivalent en points de pourcentage de la masse salariale du corps.
- [ ] Indicateur de faisabilité : volume demandé vs nombre d'admis aux dernières sessions de concours.
- [ ] Le financeur affiché correspond toujours au versant du profil (jamais FPH en part du budget de l'État).

## Critères d'acceptation

- La part de budget utilise le bon financeur selon le profil.
- L'indicateur de faisabilité compare le volume aux admis récents et le présente clairement.

## Références

Spec §Contenu d'une fiche de résultat · §Règles de transparence (4) · §Sources de données
