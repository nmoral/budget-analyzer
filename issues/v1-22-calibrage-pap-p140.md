# Calibrage contre les coûts moyens du PAP programme 140

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, type:quality, priority:high
**Dépend de:** #16, #07

## Contexte

Le coût en régime permanent est calibré contre le coût moyen chargé publié dans le PAP du programme 140 (professeurs des écoles). C'est le test de réalisme du moteur V1 (spec §Trois horizons / §Roadmap V1).

## Objectif

Comparer le coût régime permanent calculé au coût moyen chargé du PAP et documenter l'écart.

## Tâches

- [ ] Rapprocher le coût régime permanent du professeur des écoles (#16) et le coût moyen PAP saisi (#07).
- [ ] Documenter l'écart et ses causes probables (périmètre des cotisations, taux de primes approché, hypothèse stationnaire).
- [ ] Définir un seuil d'écart acceptable et le consigner dans la méthodologie.

## Critères d'acceptation

- L'écart entre calcul et PAP est mesuré, chiffré et expliqué.
- L'écart est dans le seuil retenu, ou les écarts résiduels sont documentés et assumés.

## Références

Spec §Trois horizons (2) · §Roadmap (V1) · §Décisions prises (coûts PAP)
