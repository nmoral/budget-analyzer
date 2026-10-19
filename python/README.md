# python/ — agrégation des sources (brique V0)

Un script par source, idempotent, rejouable depuis le brut archivé. Produit les CSV normalisés et leurs manifestes dans `../data/`.

## Sources disponibles

Chaque source a un script `src/sources/<nom>.py` (fonction `run()`), un brut versionné `data/sources/<nom>.json`, un CSV `data/data/<nom>.csv` et un manifeste `data/manifests/<nom>.json`.

- `point_indice` — valeur du point d'indice de la fonction publique (schéma `parametre_date`).
- `taux_cas_civil` — taux de cotisation employeur CAS Pensions, versant civil, unité `PCT_TIB` (schéma `parametre_date`). Deux périodes datées : 78.28 en 2025, 82.28 à partir du 2026-01-01 (fin ouverte).
- `taux_cas_militaire` — taux de cotisation employeur CAS Pensions, versant militaire, unité `PCT_TIB` (schéma `parametre_date`). Une période : 126.07 à partir du 2025-01-01 (fin ouverte).
- `taux_cnracl` — taux de cotisation employeur CNRACL, unité `PCT_TIB` (schéma `parametre_date`). Trajectoire 2026→2028 : 37.65, 40.65, puis 43.65 à partir du 2028-01-01 (fin ouverte).

Le versant civil et le versant militaire du CAS Pensions sont des sources distinctes (publications et périmètres différents), d'où un CSV par versant.

Reste à scaffolder : les sources restantes (une par issue, cf. #03–#07) et #08 (contrôles de cohérence, dont la non-superposition des périodes d'une même clé).

Qualité (#00) : pytest, mutmut, mypy strict, ruff — lancés via `make qa` depuis la racine.
