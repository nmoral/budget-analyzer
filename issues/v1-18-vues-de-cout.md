# Vues de coût enfichables (4 méthodes retraite)

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, type:feature, concern:architecture
**Dépend de:** #14

## Contexte

La composante retraite peut être valorisée de plusieurs façons, toutes défendables. L'outil les calcule toutes ; l'utilisateur choisit celle(s) qu'il affiche. Chaque vue est une méthode de valorisation **enfichable** au même titre que les régimes (EX-1) : en ajouter une ne modifie pas le moteur. Les autres composantes (traitement, primes, autres cotisations) sont identiques dans toutes les vues.

| Vue | Taux / méthode retraite | Hypothèses à afficher |
| --- | --- | --- |
| Budgétaire (défaut) | Taux réel du régime (82,28 % CAS civil, 37,65 % CNRACL en 2026) | Aucune |
| Équivalent privé | Taux employeur de droit commun (base + complémentaire) | Périmètre du taux retenu |
| Équilibre technique | Taux d'équilibre publié ou calculé par régime | Source/année, démographie du régime |
| Actuarielle | Valeur actuelle des droits acquis dans l'année | Table de mortalité, taux d'actualisation, âge de départ, revalorisation |

## Objectif

Implémenter les quatre vues de coût comme méthodes de valorisation enfichables ; la vue budgétaire par défaut.

## Tâches

- [ ] Interface de vue de coût (valorise la composante retraite) + 4 implémentations taggées.
- [ ] Vue budgétaire par défaut ; sélection d'une ou plusieurs vues.
- [ ] Hypothèses de la vue actuarielle modifiables par l'utilisateur, valeurs par défaut sourcées ; valeur modifiée signalée (fiche + URL de partage, voir #20).
- [ ] Chaque vue expose sa définition, la question à laquelle elle répond, ses hypothèses et ses sources.

## Critères d'acceptation

- Les quatre vues produisent un coût ; seules les composantes retraite diffèrent entre elles.
- Ajouter une 5e vue (service taggé) ne modifie pas le moteur.
- Modifier une hypothèse actuarielle change le résultat et marque la valeur comme modifiée.

## Références

Spec §Vues de coût · §Décisions prises · EX-1
