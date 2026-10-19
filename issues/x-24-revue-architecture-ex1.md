# Revue d'architecture EX-1 (profils tests)

**Milestone:** Transverse
**Labels:** concern:architecture, type:quality, priority:high
**Dépend de:** #12, #13, #18

## Contexte

EX-1 : ajouter un métier doit se faire par ajout de données et, au besoin, ajout d'une classe — **jamais** par modification du moteur ou des profils existants. Les militaires, forces de l'ordre et enseignement supérieur servent de cas de test de conception dès la V1. Critère d'acceptation EX-1 : l'ajout d'un troisième profil en V1.x ne modifie aucun fichier existant hors catalogue de données et configuration.

## Objectif

Vérifier, par une revue d'architecture sur des profils fictifs, que l'ajout d'un profil ne touche pas l'existant.

## Tâches

- [ ] Esquisser l'ajout (sur papier/branche jetable) des trois profils tests :
  - militaires : régime `CAS_MILITAIRE` à 126,07 %, bonifications spécifiques ;
  - forces de l'ordre : grilles et régime indemnitaire propres ;
  - enseignement supérieur : financement par les opérateurs (universités).
- [ ] Vérifier qu'un nouveau régime = une nouvelle implémentation d'interface découverte par tag, sans toucher au moteur.
- [ ] Lister tout point nécessitant une modification de l'existant → corriger la conception avant de clore.

## Critères d'acceptation

- Pour chacun des trois profils tests, l'ajout se limite à des données + éventuellement une nouvelle classe taggée.
- Aucun fichier existant du moteur ou des profils V1 ne doit être modifié ; les écarts constatés sont corrigés.

## Références

Spec EX-1 · §Roadmap (V1.x)
