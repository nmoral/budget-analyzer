# Règles de régime de retraite (CAS civil, CNRACL)

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, type:feature
**Dépend de:** #12

## Contexte

Chaque terme de la formule de coût est une règle fournie par le régime du profil (EX-1). V1 implémente les deux régimes des profils V1 : CAS civil (professeur des écoles, FPE) et CNRACL (infirmier, FPH). `CAS_MILITAIRE` et `IRCANTEC` restent de futurs ajouts servant de cas de test de conception.

## Objectif

Implémenter les règles `CAS_CIVIL` et `CNRACL` comme services taggés, fournissant le taux employeur retraite daté.

## Tâches

- [ ] Implémentation `CAS_CIVIL` : taux employeur du TIB à la date simulée (82,28 % en 2026).
- [ ] Implémentation `CNRACL` : taux employeur à la date simulée (trajectoire 37,65 % → 40,65 % → 43,65 %).
- [ ] Les taux proviennent des paramètres datés (#04), pas de valeurs codées en dur.
- [ ] Les deux services sont taggés et résolus par le profil (#12).

## Critères d'acceptation

- Pour une date en 2026, `CAS_CIVIL` renvoie 82,28 % et `CNRACL` 37,65 %.
- Pour une date en 2027, `CNRACL` renvoie 40,65 %.
- Aucun taux en dur : changer le paramètre daté change le résultat.

## Références

Spec §Moteur de calcul · §Sources de données · EX-1
