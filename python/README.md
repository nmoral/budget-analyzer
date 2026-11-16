# python/ — agrégation des sources (brique V0)

Un script par source, idempotent, rejouable depuis le brut archivé. Produit les CSV normalisés et leurs manifestes dans `../data/`.

## Sources disponibles

Chaque source a un script `src/sources/<nom>.py` (fonction `run()`), un brut versionné `data/sources/<nom>.json`, un CSV `data/data/<nom>.csv` et un manifeste `data/manifests/<nom>.json`.

En ligne de commande, depuis `python/` : `PYTHONPATH=src uv run python -m sources.<nom>` exécute `run()` et imprime le chemin du CSV écrit (le `pythonpath=["src"]` du `pyproject` ne vaut que pour pytest). Depuis la racine du dépôt, la cible `make generate KEY=<clé>` lance cette même commande.

- `point_indice` — valeur du point d'indice de la fonction publique (schéma `parametre_date`).
- `taux_cas_civil` — taux de cotisation employeur CAS Pensions, versant civil, unité `PCT_TIB` (schéma `parametre_date`). Deux périodes datées : 78.28 en 2025, 82.28 à partir du 2026-01-01 (fin ouverte).
- `taux_cas_militaire` — taux de cotisation employeur CAS Pensions, versant militaire, unité `PCT_TIB` (schéma `parametre_date`). Une période : 126.07 à partir du 2025-01-01 (fin ouverte).
- `taux_cnracl` — taux de cotisation employeur CNRACL, unité `PCT_TIB` (schéma `parametre_date`). Trajectoire 2026→2028 : 37.65, 40.65, puis 43.65 à partir du 2028-01-01 (fin ouverte).
- `taux_autres_cotisations_fpe` — cotisations employeur hors retraite, versant FPE/État, unité `PCT_TIB` (schéma `parametre_date`). Agrégat 16.07 à partir du 2026-01-01 (fin ouverte), source `cotisations-fpe-2026`.
- `taux_autres_cotisations_fph` — cotisations employeur hors retraite, versant FPH/CNRACL, unité `PCT_TIB` (schéma `parametre_date`). Agrégat 17.13 à partir du 2026-01-01 (fin ouverte), source `cotisations-fph-2026`.
- `taux_rafp` — retraite additionnelle de la fonction publique, part employeur, unité `PCT_TIB` (schéma `parametre_date`). 5.00 à partir du 2026-01-01 (fin ouverte), source `rafp-2026`.

Le versant civil et le versant militaire du CAS Pensions sont des sources distinctes (publications et périmètres différents), d'où un CSV par versant.

Les deux taux « autres cotisations » (`taux_autres_cotisations_fpe`, `taux_autres_cotisations_fph`) sont des **agrégats** : la valeur stockée est la somme des cotisations employeur hors retraite et hors RAFP du versant. La composition ligne à ligne, les sources officielles et l'exclusion du versement mobilité sont documentées dans `../data/catalogue_autres_cotisations.md`. La publication de référence CDG67 « Valeurs de paie au 1er janvier 2026 » est archivée dans `../data/sources/publications/cdg67-valeurs-paie-2026.pdf`.

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

### Budget de référence par financeur

Budget de référence **daté par financeur**, trajectoire pluriannuelle sur le modèle de `taux_cnracl`, chaque ligne sourcée (règle de transparence n° 7) :

- `budget_financeur` — budget total d'un financeur (schéma `budget_financeur`), colonnes `funder_code, value, unit, start_date, end_date (optionnel), source`. `unit = EUR`, `value` = montant total en euros (entier). Deux financeurs, deux millésimes chacun (2024 clos, 2025 à fin ouverte) :
  - `ETAT_P140` (FPE) — crédits complets du programme 140 (LFI, total crédits de paiement, PAP annexe « Enseignement scolaire ») : 26 842 958 249 € en 2024, 27 490 907 364 € en 2025.
  - `ONDAM` (FPH) — ONDAM global voté (LFSS, montant confirmé par la Cour des comptes, RALFSS 2025) : 254 900 000 000 € en 2024, 265 900 000 000 € en 2025.

  Chaque valeur de `source` est un slug présent dans `data/sources.json`, dont le `fileHash` est le sha256 de la publication archivée sous `data/sources/publications/`.

### Masse salariale par corps

Masse salariale **datée par corps**, en coût chargé **HCAS** (hors CAS Pensions) — seul chiffre publié par catégorie d'emploi dans le PAP ; les variantes « avec CAS Pensions » ou « rémunérations d'activité seules » ne sont pas produites —, chaque ligne sourcée (règle de transparence n° 7) :

- `masse_salariale_corps` — masse salariale annuelle d'un corps (schéma `masse_salariale_corps`), colonnes `body_code, value, unit, start_date, end_date (optionnel), source`. `unit = EUR`, `value` = montant en euros (entier). Un corps, deux millésimes (2024 clos, 2025 à fin ouverte) :
  - `PE` — ETPT de la catégorie d'emploi 1106 « Enseignants du 1er degré » × coût moyen chargé HCAS global de cette catégorie, les deux lus dans le PAP « Enseignement scolaire » (programme 140) du millésime : 327 302 × 47 634 = 15 590 703 468 € en 2024 (PAP 2024, p. 47 et 53) ; 324 990 × 46 139 = 14 994 713 610 € en 2025 (PAP 2025, p. 47 et 52).

  Le brut `data/sources/masse_salariale_corps.json` porte les deux facteurs (`etpt`, `average_loaded_cost`) ; le produit est calculé par `sources.masse_salariale_corps`. Chaque `source` est un slug dédié de `data/sources.json` (`pap-2024-ens-scolaire-masse-1106`, `pap-2025-ens-scolaire-masse-1106`), de même URL et `fileHash` que le PDF PAP archivé, dont le `locator` cite la formule, les pages et les deux valeurs. `ISG` n'a pas de ligne (pas de source directe, cf. #74).

### Admis aux concours

Sessions de concours **par corps, session et voie**, chaque ligne sourcée (règle de transparence n° 7) :

- `concours_admis` — une ligne par concours publié (schéma `concours_admis`), colonnes `body_code, session, voie, postes, inscrits, presents, admis, source` (session et effectifs entiers). Clé unique (`body_code`, `session`, `voie`).
  - `PE` — CRPE de l'enseignement public, sessions 2021 à 2025, totaux nationaux (postes offerts, candidats inscrits, présents aux épreuves écrites, admis) transcrits des pages « Les données statistiques des concours de recrutement de professeurs des écoles de la session <année> » de devenirenseignant.gouv.fr, section « Données chiffrées des concours de l'enseignement public ». Voies : `externe`, `externe_supplementaire`, `externe_special` (langues régionales), `troisieme`, `second_interne`, `second_interne_special`, `interne_exceptionnel` (publié depuis 2023) ; une voie non publiée une session n'a pas de ligne. Ex. 2025 externe : 8 102 postes, 43 276 inscrits, 18 387 présents, 7 561 admis (hors Polynésie, voir ci-dessous).
  - Exclus : Polynésie française (corps distinct), enseignement privé (CAFEP), admissibles, listes complémentaires, détail par académie. `ISG` n'a pas de ligne (cf. #78). Les données Polynésie / outre-mer par territoire relèvent de #80.
  - Polynésie retranchée des totaux publiés : la valeur retenue de chaque bloc doté d'un tableau « Résultats par académies » est la somme des académies hors Polynésie. Deux totaux nationaux publiés incluaient la Polynésie :
    - session 2021, `externe` : pas de bloc Polynésie séparé, « Polynésie Française » est une ligne du tableau par académies incluse dans le total publié (8 694 postes, 84 131 inscrits, 26 657 présents, 8 294 admis) ; cette ligne (10 / 395 / 120 / 10) est retranchée → `PE,2021,externe,8684,83736,26537,8284` ;
    - session 2025, `externe` : le total publié (8 117 / 43 525 / 18 524 / 7 576) compte le bloc séparé « … corps de l'Etat créé pour la Polynésie française » (15 / 249 / 137 / 15), absent du tableau par académies ; ce bloc est retranché → `PE,2025,externe,8102,43276,18387,7561`.
    En 2024 `externe`, la ligne Polynésie figure au tableau mais n'est pas comptée dans le total publié : rien à retrancher. Le brut garde, pour ces lignes, le total publié (`published_total`), l'élément retranché (`excluded` : `kind` académie ou bloc, `label`, effectifs) et une `note`.

  Le brut `data/sources/concours_admis.json` garde pour chaque ligne le libellé exact du bloc source (`block`), écarté du CSV par `sources.concours_admis`. Chaque `source` est le slug `menj-crpe-<session>` de `data/sources.json`, dont le `locator` (≤ 255 caractères) cite la page et la section reprise ; la page est archivée sous `data/sources/publications/crpe-session-<session>.html` (`fileHash` = son sha256).

### Admis aux concours par académie

Détail **par académie** (territoire) des mêmes concours, outre-mer et Polynésie française traités comme tout territoire :

- Référentiel `data/academies.json` — une entrée par code officiel d'académie (clé), avec `label` (libellé officiel), `statut` (`metropole`, `drom` ou `com`), `aliases` éventuels (libellés publiés non dérivables du libellé officiel, ex. « Réunion » pour « La Réunion ») et `source`. Codes relus dans les extraits data.education.gouv.fr archivés sous `data/sources/publications/` : `data-education-annuaire-academies.json` (annuaire de l'éducation, codes courants dont Normandie `70`, Nouvelle-Calédonie `40`, Polynésie française `41`, Saint-Pierre-et-Miquelon `44`) et `data-education-dnb-academies.json` (brevet par établissement, codes historiques Caen `05` et Rouen `21`). Caen, Rouen (2021-2022) et Normandie (depuis 2023) restent trois entrées distinctes, sans recomposition. La résolution d'un libellé publié ignore casse, accents et ponctuation (« AIX MARSEILLE » = « Aix-Marseille ») ; un libellé inconnu bloque la génération.
- `concours_admis_academie` — une ligne par (`body_code`, `session`, `voie`, `academie`) (schéma `concours_admis_academie`), colonnes `body_code, session, voie, academie, postes, inscrits, presents, admis, source` ; `academie` = code officiel. `PE` : sessions 2021 à 2025, lignes des tableaux « Résultats par académies » de chaque bloc public repris par `concours_admis` (colonnes Postes, Inscrits, Présents, Admis ; admissibles, listes complémentaires et privé exclus). Ex. `PE,2025,externe,32,26,442,165,26` (Guadeloupe).
  - Polynésie française (`41`), voie `externe` : ligne académie du tableau en 2021 (10 / 395 / 120 / 10) ; bloc « … corps de l'Etat créé pour la Polynésie française » de 2022 à 2025. En 2024 la ligne académie du tableau, identique au bloc (15 / 257 / 125 / 15), n'est pas reprise une seconde fois : une seule ligne, depuis le bloc (`note` dans le brut).
  - Bloc sans tableau nommant son académie : 2021 `externe_supplementaire` (« académie de Créteil ») → une ligne Créteil = totaux du bloc.
  - Le brut `data/sources/concours_admis_academie.json` garde le bloc (`block`), l'origine (`tableau` ou `bloc`) et le libellé publié (`academie_label`), écartés du CSV par `sources.concours_admis_academie`. Chaque `source` est le slug `menj-crpe-<session>-academies` (même page archivée et même `fileHash` que `menj-crpe-<session>`, `locator` citant les tableaux « Résultats par académies »).

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
- **Périodes sans chevauchement** — les périodes d'effet d'une même clé ne se chevauchent pas : `parametre_date` groupé par `key`, `grille` par `body_code` + `grade`, `budget_financeur` par `funder_code`, `masse_salariale_corps` par `body_code` (un corps du référentiel sans ligne, comme `ISG`, n'est pas une anomalie). Période `[start_date ; end_date]` bornes incluses, `end_date` vide = ouverte (∞) ; l'adjacence (fin le jour J, début le jour J+1) est autorisée.
- **Effectifs de concours** — dans `concours_admis` et `concours_admis_academie`, `postes`, `inscrits`, `presents` et `admis` sont ≥ 0 et `admis ≤ presents ≤ inscrits` (bornes incluses). `admis > postes` est admis : les emplois non pourvus d'un concours peuvent être reportés sur un autre (décret n° 90-680, art. 4).
- **Unicité des sessions de concours** — une seule ligne `concours_admis` par (`body_code`, `session`, `voie`) ; le doublon est signalé sur sa ligne en citant la première occurrence. Un corps sans ligne (`ISG`) n'est pas une anomalie. Par académie, la clé est (`body_code`, `session`, `voie`, `academie`).
- **Académie déclarée** — toute `academie` de `concours_admis_academie` est un code de `data/academies.json` (référentiel absent → contrôle neutralisé).
- **Statut d'académie** — le `statut` de chaque entrée de `data/academies.json` vaut exactement `metropole`, `drom` ou `com` (casse et espaces compris : `DROM` ou ` drom ` sont refusés) ; toute autre valeur, y compris un statut absent ou vide, est une anomalie `academie_statut` localisée sur la ligne de la clé de l'entrée (`academies.json:<ligne>`), avec le message « statut absent » ou « statut « <valeur> » hors metropole/drom/com ». Chaque entrée fautive est rapportée. Référentiel absent → contrôle neutralisé.
- **Recoupement national / académies** — pour chaque ligne de `concours_admis`, la somme des lignes `concours_admis_academie` de même (`body_code`, `session`, `voie`), Polynésie française (`41`) exclue, égale chaque effectif national ; l'écart est signalé sur la ligne nationale, par effectif. Neutralisé si l'un des deux fichiers est absent.
- **Valeurs dans les bornes** — chaque valeur soumise à bornes reste dans `[min ; max]` défini dans `coherence.toml` (voir ci-dessous).
- **Source renseignée** — toute ligne d'un schéma portant une colonne `source` a une `source` non blanche (après `strip`). La présence de la colonne est dérivée du schéma livré, pas d'une liste codée en dur ; les schémas sans colonne `source` (ex. `echelon`, `couts_pap`) ne sont pas concernés.
- **Source déclarée** — tout code `source` non blanc d'un CSV doit être déclaré dans `data/sources.json` (pas de source silencieuse) ; un code absent du référentiel est refusé. Les sources blanches relèvent de l'invariant précédent et ne sont pas signalées deux fois. Référentiel absent (`sources.json` manquant) → contrôle neutralisé, comme pour la liste blanche des bornes. La correspondance fine code ↔ `fileHash` ↔ brut reste vérifiée à l'import côté Symfony (`ManifestVerifier`).

### `data/coherence.toml` — liste blanche des bornes

Les bornes de l'invariant « valeurs dans les bornes » sont versionnées dans `data/coherence.toml`. C'est une **liste blanche** : seules les clés et schémas listés sont contrôlés ; une clé `parametre_date` absente (ou tout autre schéma) n'est pas bornée. Fichier absent = aucune borne appliquée.

- `[parametre_date.key]` — une table inline `{ min, max }` par valeur de la colonne `key` (la valeur contrôlée est la colonne `value`). Ex. `taux_cas_civil`, `taux_cas_militaire`, `taux_cnracl` en `[0 ; 200]` (admet les taux > 100 %, p. ex. 126,07 du versant militaire) ; `taux_autres_cotisations_fpe`, `taux_autres_cotisations_fph`, `taux_rafp` en `[0 ; 100]` ; `point_indice` avec `min = 0` seul.
- `[taux_primes]` — une borne `value = { min, max }` appliquée à la colonne `value` de tout fichier du schéma `taux_primes` (ratio du TIB, `[0 ; 1]`).
- `[budget_financeur]` — une borne `value = { min = 0 }` appliquée à la colonne `value` (montant en euros) de tout fichier du schéma `budget_financeur` : montant positif ou nul, sans plafond.
- `[masse_salariale_corps]` — une borne `value = { min = 0 }` appliquée à la colonne `value` (montant en euros) de tout fichier du schéma `masse_salariale_corps` : montant positif ou nul, sans plafond.

Les bornes sont **incluses**. Une borne peut être omise : `min` seul = pas de plafond, `max` seul = pas de plancher.

### Câblage

`python -m checks` fait partie de `make qa-python` et d'un hook `pre-commit` (`id: checks`), déclenché quand un CSV de `data/data/` ou un fichier de `src/checks/` change. Le cœur `src/checks` est couvert à 100 % et inclus dans les cibles mutmut (cf. `CLAUDE.md`).

Qualité (#00) : pytest, mutmut, mypy strict, ruff — lancés via `make qa` depuis la racine.
