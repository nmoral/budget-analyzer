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

La source retenue, la raison du choix et le statut de repli pour chaque corps sont documentés dans `../data/catalogue_primes.md`.

Coûts moyens chargés PAP par catégorie d'emploi, saisis à la main, chaque ligne sourcée au document et à la page (règle de transparence n° 7) :

- `couts_pap` — coût moyen chargé d'une catégorie d'emploi d'un programme (schéma `couts_pap`), colonnes `program, employment_category, average_loaded_cost, year, document, page, url`. Une ligne en V0 : programme 140, `enseignant_1er_degre` (professeur des écoles), 46139 € en 2025, sourcée `PAP2025-ENSSCOL-P140` page 49 (PAP 2025, annexe « Enseignement scolaire »). `document` et `page` sont requis : une ligne privée de l'un ou l'autre est rejetée à la validation.

`average_loaded_cost` est le **coût global** (coût moyen sur la carrière, et non entrée/sortie), en base **HCAS** (hors CAS Pensions) — seule valeur publiée par catégorie dans le PAP. Le coût « y compris CAS Pensions » n'est pas calculé ici : il relève de #22, qui appliquera `taux_cas_civil`. Une seule catégorie du programme 140 est saisie (celle du calibrage #22) ; les autres sont hors périmètre V0.

Reste à scaffolder : les sources restantes (une par issue, cf. #03, #04) et #08 (contrôles de cohérence, dont la non-superposition des périodes d'une même clé et les indices majorés croissants par rang).

Qualité (#00) : pytest, mutmut, mypy strict, ruff — lancés via `make qa` depuis la racine.
