# python/ — agrégation des sources (brique V0)

Un script par source, idempotent, rejouable depuis le brut archivé. Produit les CSV normalisés et leurs manifestes dans `../data/`.

## Sources disponibles

Chaque source a un script `src/sources/<nom>.py` (fonction `run()`), un brut versionné `data/sources/<nom>.json`, un CSV `data/data/<nom>.csv` et un manifeste `data/manifests/<nom>.json`.

En ligne de commande, depuis `python/` : `PYTHONPATH=src uv run python -m sources.<nom>` exécute `run()` et imprime le chemin du CSV écrit (le `pythonpath=["src"]` du `pyproject` ne vaut que pour pytest).

- `point_indice` — valeur du point d'indice de la fonction publique (schéma `parametre_date`).
- `taux_cas_civil` — taux de cotisation employeur CAS Pensions, versant civil, unité `PCT_TIB` (schéma `parametre_date`). Deux périodes datées : 78.28 en 2025, 82.28 à partir du 2026-01-01 (fin ouverte).
- `taux_cas_militaire` — taux de cotisation employeur CAS Pensions, versant militaire, unité `PCT_TIB` (schéma `parametre_date`). Une période : 126.07 à partir du 2025-01-01 (fin ouverte).
- `taux_cnracl` — taux de cotisation employeur CNRACL, unité `PCT_TIB` (schéma `parametre_date`). Trajectoire 2026→2028 : 37.65, 40.65, puis 43.65 à partir du 2028-01-01 (fin ouverte).

Le versant civil et le versant militaire du CAS Pensions sont des sources distinctes (publications et périmètres différents), d'où un CSV par versant.

Grilles indiciaires des deux profils V1, au grade d'entrée de chaque corps (une grille datée + ses échelons par corps) :

- `grille_pe` — grille du professeur des écoles (FPE), grade `classe_normale` (schéma `grille`). Une version courante `PE_classe_normale_2024`, effet 2024-01-01 (fin ouverte). Source : décret n° 2017-789.
- `echelon_pe` — échelons de `PE_classe_normale_2024` (schéma `echelon`). 11 rangs contigus, indice brut / indice majoré et durée par échelon ; durée vide au dernier rang. Source : décret n° 2017-789.
- `grille_isg` — grille de l'infirmier en soins généraux (FPH), grade `grade_1` (schéma `grille`). Une version courante `ISG_grade_1_2024`, effet 2024-01-01 (fin ouverte). Source : arrêté du 19 mai 2016 (corps ISGS, décret statutaire n° 2010-1139).
- `echelon_isg` — échelons de `ISG_grade_1_2024` (schéma `echelon`). 11 rangs contigus ; durée vide au dernier rang. Source : arrêté du 19 mai 2016.

La clé d'identité d'une grille est `{body_code}_{grade}_{version}` ; chaque ligne d'échelon s'y rattache par sa colonne `grille`. Une seule version courante par corps en V1 (indices majorés intégrant les +5 points au 01/01/2024), sans historique antérieur.

Taux de primes moyen par corps des deux profils V1 (schéma `taux_primes`) :

- `taux_primes` — une ligne par corps (`PE`, `ISG`), colonnes `body_code, value, observation_year, source, fallback`. `value` est le ratio décimal du traitement indiciaire brut (`0.21` = 21 % du TIB). `fallback` distingue l'observation directe (`false`) du repli versant × catégorie DGAFP (`true`) : `PE`→false, `ISG`→true.

La source retenue, la raison du choix et le statut de repli pour chaque corps sont documentés dans `../data/catalogue_primes.md`. Les publications sources sont archivées dans `../data/sources/publications/` (`dgafp-ra-2025.pdf` pour `PE`, `insee-ip-2067.pdf` pour `ISG`) pour rester traçables même si les URL disparaissent ; `manifest.url` pointe le permalien de la publication (permalien DGAFP pour ce jeu).

Coûts moyens chargés PAP par catégorie d'emploi, saisis à la main, chaque ligne sourcée au document et à la page (règle de transparence n° 7) :

- `couts_pap` — coût moyen chargé d'une catégorie d'emploi d'un programme (schéma `couts_pap`), colonnes `program, employment_category, average_loaded_cost, year, document, page, url`. Une ligne en V0 : programme 140, `enseignant_1er_degre` (professeur des écoles), 46139 € en 2025, sourcée `PAP2025-ENSSCOL-P140` page 49 (PAP 2025, annexe « Enseignement scolaire »). `document` et `page` sont requis : une ligne privée de l'un ou l'autre est rejetée à la validation.

`average_loaded_cost` est le **coût global** (coût moyen sur la carrière, et non entrée/sortie), en base **HCAS** (hors CAS Pensions) — seule valeur publiée par catégorie dans le PAP. Le coût « y compris CAS Pensions » n'est pas calculé ici : il relève de #22, qui appliquera `taux_cas_civil`. Une seule catégorie du programme 140 est saisie (celle du calibrage #22) ; les autres sont hors périmètre V0.

Reste à scaffolder : les sources restantes (une par issue, cf. #03, #04).

## Contrôles de cohérence (`python -m checks`)

Commande unique qui applique des **invariants métier** sur les CSV normalisés présents dans `data/data/`, en complément de la validation de schéma `frictionless` (qui ne contrôle que la forme). Chaque fichier est relié à son schéma par un mapping explicite ; les schémas sans CSV réel sont ignorés sans erreur.

Usage, depuis `python/` :

```sh
PYTHONPATH=src BUDGET_DATA_DIR=../data uv run python -m checks
```

`BUDGET_DATA_DIR` désigne la racine du dépôt de données (celle qui contient `data/`, `schemas/` et `coherence.toml`). La commande lit les CSV de `<BUDGET_DATA_DIR>/data/`, les schémas de `<BUDGET_DATA_DIR>/schemas/` et les bornes de `<BUDGET_DATA_DIR>/coherence.toml`. Elle imprime un rapport texte (une ligne par anomalie + un récapitulatif), sort **0** si aucune anomalie et **1** sinon, en rapportant **toutes** les anomalies (pas d'arrêt à la première).

Invariants appliqués :

- **Indices croissants** — dans chaque grille d'un fichier `echelon` (groupée par la colonne `grille`), `gross_index` et `increased_index` sont strictement croissants par `rank` croissant. Les deux colonnes sont contrôlées indépendamment.
- **Périodes sans chevauchement** — les périodes d'effet d'une même clé ne se chevauchent pas : `parametre_date` groupé par `key`, `grille` par `body_code` + `grade`. Période `[start_date ; end_date]` bornes incluses, `end_date` vide = ouverte (∞) ; l'adjacence (fin le jour J, début le jour J+1) est autorisée.
- **Valeurs dans les bornes** — chaque valeur soumise à bornes reste dans `[min ; max]` défini dans `coherence.toml` (voir ci-dessous).
- **Source renseignée** — toute ligne d'un schéma portant une colonne `source` a une `source` non blanche (après `strip`). La présence de la colonne est dérivée du schéma livré, pas d'une liste codée en dur ; les schémas sans colonne `source` (ex. `echelon`, `couts_pap`) ne sont pas concernés.

### `data/coherence.toml` — liste blanche des bornes

Les bornes de l'invariant « valeurs dans les bornes » sont versionnées dans `data/coherence.toml`. C'est une **liste blanche** : seules les clés et schémas listés sont contrôlés ; une clé `parametre_date` absente (ou tout autre schéma) n'est pas bornée. Fichier absent = aucune borne appliquée.

- `[parametre_date.key]` — une table inline `{ min, max }` par valeur de la colonne `key` (la valeur contrôlée est la colonne `value`). Ex. `taux_cas_civil`, `taux_cas_militaire`, `taux_cnracl` en `[0 ; 200]` (admet les taux > 100 %, p. ex. 126,07 du versant militaire), `point_indice` avec `min = 0` seul.
- `[taux_primes]` — une borne `value = { min, max }` appliquée à la colonne `value` de tout fichier du schéma `taux_primes` (ratio du TIB, `[0 ; 1]`).

Les bornes sont **incluses**. Une borne peut être omise : `min` seul = pas de plafond, `max` seul = pas de plancher.

### Câblage

`python -m checks` fait partie de `make qa-python` et d'un hook `pre-commit` (`id: checks`), déclenché quand un CSV de `data/data/` ou un fichier de `src/checks/` change. Le cœur `src/checks` est couvert à 100 % et inclus dans les cibles mutmut (cf. `CLAUDE.md`).

Qualité (#00) : pytest, mutmut, mypy strict, ruff — lancés via `make qa` depuis la racine.
