# Catalogue des taux de primes par corps (profils V1)

Ce catalogue documente, pour chaque corps du fichier `data/data/taux_primes.csv`,
la source retenue pour le taux de primes moyen, la raison de ce choix et le statut
de repli (observation directe ou repli versant × catégorie DGAFP).

Le taux `value` est exprimé en ratio du traitement indiciaire brut (TIB) :
`0.21` = 21 % du TIB. La conversion part-du-brut → part-du-TIB utilise
`p = s / (1 − s)`.

## PE — professeur des écoles (FPE)

- **Code corps** : `PE`
- **Taux** : `0.21` (année d'observation `2023`)
- **Source** : `dgafp-ra-2025` — DGAFP, rapport annuel sur l'état de la fonction
  publique, édition 2025 (données 2023).
- **Raison du choix** : part des primes (y compris SFT et indemnité de résidence)
  des enseignants = 17,2 % du salaire brut, convertie en part du TIB :
  `0,172 / 0,828 ≈ 0,21`. Chiffre directement observé pour les enseignants.
- **Repli** : non (observation directe).

## ISG — infirmier en soins généraux (FPH)

- **Code corps** : `ISG`
- **Taux** : `0.33` (année d'observation `2023`)
- **Source** : `insee-ip-2067` — Insee Première n° 2067 (données 2023).
- **Raison du choix** : aucun chiffre de primes spécifique au corps infirmier n'est
  extractible (publication DREES non exploitable) ; on se replie sur la part des
  primes de la catégorie A de la fonction publique hospitalière = 24,9 % du brut,
  convertie en part du TIB : `0,249 / 0,751 ≈ 0,33`.
- **Repli** : oui (repli versant × catégorie DGAFP).
