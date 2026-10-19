# Horizon année 1 (prorata + année pleine)

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, type:feature
**Dépend de:** #14

## Contexte

Année 1 = coût budgétaire de l'année civile du recrutement, au prorata de la date d'entrée (un recrutement au 1er septembre pèse 4/12 d'une année pleine). Le coût en **année pleine est toujours affiché juste à côté** pour éviter qu'un faible coût d'année 1 soit lu comme un faible coût réel. Pour les profils à calendrier scolaire (enseignement), une vue en année scolaire est proposée en option (décision prise).

## Objectif

Calculer le coût année 1 au prorata, le coût année pleine, et l'option année scolaire selon le calendrier du profil.

## Tâches

- [ ] Prorata temporel à partir de la date de recrutement (mois de présence / 12).
- [ ] Coût année pleine systématiquement calculé en regard.
- [ ] Vue année scolaire optionnelle quand le profil a un calendrier scolaire.

## Critères d'acceptation

- Recrutement au 1er septembre → année 1 = 4/12 du coût année pleine.
- Le coût année pleine est toujours disponible à côté du coût année 1.
- Un profil à calendrier civil n'expose pas la vue année scolaire.

## Références

Spec §Trois horizons (1) · §Décisions prises
