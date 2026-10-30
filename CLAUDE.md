# CLAUDE.md — standards, seuils et chaîne qualité

Doc technique et point d'entrée du monorepo **budget-analyser** (simulateur du coût
des recrutements publics). Deux briques reliées par le seul dépôt `data/` :

- `python/` — agrégation des sources publiques (brique V0, livrée).
- `symfony/` — moteur de calcul + API + front (brique V1 ; squelette livré #9,
  modèle de données Doctrine #10, commande d'import `app:import` #11, ossature du
  moteur de calcul #12, premières règles de retraite — taux employeur daté — #13).

Ce fichier est la **source unique** des conventions et des seuils. La CI et le
`Makefile` les appliquent ; ils doivent rester alignés avec le tableau ci-dessous.

## Lancer la chaîne qualité

```sh
make qa            # tout : qa-python puis qa-symfony (entrée unique)
make qa-python     # chaîne Python complète (voir ci-dessous)
make qa-symfony    # chaîne PHP complète, dans le conteneur (voir ci-dessous)
```

`make qa-symfony` build l'image `symfony`, démarre Postgres (`up -d --wait postgres`,
nécessaire aux tests d'intégration DBAL), puis exécute **dans le conteneur**, dans
l'ordre, en échouant au premier garde-fou KO :

1. `vendor/bin/php-cs-fixer fix --dry-run --diff` (style PSR-12 + `strict_types`)
2. `vendor/bin/phpstan analyse` (`level: max`)
3. `vendor/bin/deptrac analyse --no-progress` (frontière : `App\Domain` sans `Doctrine\*` ni `App\Infrastructure\*`)
4. `vendor/bin/phpunit -c phpunit.dist.xml` (suite de tests)
5. `vendor/bin/infection --threads=max --no-progress` (mutation testing sur `src/Domain`, MSI ≥ 85 %)

`make qa-python` enchaîne, dans l'ordre, et échoue au premier garde-fou KO :

1. `ruff check .` (lint + tri des imports, règle `I`)
2. `ruff format --check .`
3. `mypy src` (mode strict)
4. `pytest --cov=src`
5. `coverage report --fail-under=90` (couverture globale)
6. `coverage report --include=<cœur> --fail-under=100` (couverture du cœur)
7. `python -m checks` (contrôles de cohérence métier sur `data/data/`, via `BUDGET_DATA_DIR`)
8. `mutmut run` + `mutmut export-cicd-stats` + `scripts/check_msi.py` (porte MSI)
9. `frictionless validate --type schema` sur chaque `data/schemas/*.json`

L'étape 7 applique les invariants métier décrits dans `python/README.md` (indices
croissants, périodes sans chevauchement, valeurs dans les bornes de
`data/coherence.toml`, `source` renseignée) ; elle échoue (exit 1) à la moindre
anomalie. Elle s'ajoute à la validation de schéma `frictionless` (étape 9), qui ne
contrôle que la forme.

Toutes les commandes Python tournent via `uv` depuis `python/` (aucune installation
hôte hors `uv`). En local, installer les hooks : `uv run --project python pre-commit install`.

### pre-commit

`.pre-commit-config.yaml` (hooks locaux alignés sur le Makefile) : `ruff check`,
`ruff format --check`, `mypy src`, `pytest`, `checks` (contrôles de cohérence
`python -m checks`, déclenché quand un CSV de `data/data/` ou `src/checks/` change).

```sh
uv run --project python pre-commit run --all-files
```

### CI

`.github/workflows/qa.yml` applique **les mêmes garde-fous qu'en local** (source
unique = le `Makefile`) mais **diverge dans l'orchestration** pour réduire le temps
mur. Au lieu d'un job unique lançant `make qa`, la CI éclate la chaîne en **deux jobs
parallèles** plus un job agrégateur :

- **`qa-python`** : installe `uv` (avec **cache uv** `enable-cache`, clé sur
  `python/uv.lock`), synchronise les dépendances puis lance `make qa-python` (ruff,
  format, mypy, cov 90/100, checks, mutmut+MSI, frictionless). **Mutation scopée sur
  PR** : sur un `pull_request`, mutmut ne mute que les fichiers du cœur
  (`src/{contract,ingest,sources,checks}`) modifiés depuis le merge-base avec `main`
  (via `MUTMUT_SKIP=1 make qa-python` puis `python/scripts/mutmut_scoped.sh`), MSI
  bloquant sur ce périmètre ; aucun cœur modifié → étape no-op verte. Sur **push
  `main`**, mutmut reste **complet** (4 packages, MSI global). Aucun seuil n'est
  changé : seul le périmètre muté sur PR l'est.
- **`qa-symfony`** (en parallèle, aucun `needs` vers `qa-python`) : dérive le `.env`
  depuis `.env.example`, pré-build l'image `symfony` avec **buildx + cache de layers
  persistant** (`type=gha`) pour réchauffer le cache réutilisé par `docker compose
  build symfony`, puis lance `make qa-symfony` (php-cs-fixer, phpstan, deptrac,
  phpunit, infection).
- **`qa`** : job final **agrégateur** (`needs: [qa-python, qa-symfony]`) qui ne fait
  que réussir une fois les deux chaînes vertes. Son nom `qa` est **préservé tel quel**
  car c'est le **seul check requis** par la protection de `main` (voir plus bas) ; il
  passe au rouge dès que l'une des deux chaînes échoue, ce qui bloque le merge.

En revanche, **`make qa` local reste la chaîne complète séquentielle inchangée**
(`qa-python` puis `qa-symfony`, mutmut complet) : c'est l'entrée unique en local et la
référence des garde-fous. Seule l'orchestration CI (jobs parallèles, caches uv/buildx)
diffère ; les commandes et les seuils sont identiques.

## Seuils versionnés (appliqués par la chaîne)

| Garde-fou | Outil | Seuil / règle | Bloquant | Où c'est appliqué |
|-----------|-------|---------------|----------|-------------------|
| Lint | ruff check | 0 erreur, imports triés (`I`) | oui | `Makefile` / `python/pyproject.toml` |
| Format | ruff format --check | 0 écart | oui | `Makefile` |
| Typage | mypy | `strict = true` | oui | `python/pyproject.toml` |
| Couverture globale | coverage | ≥ 90 % | oui | `Makefile` (`--fail-under=90`) |
| Couverture cœur | coverage | 100 % sur `src/ingest`, `src/sources`, `src/contract`, `src/checks` | oui | `Makefile` (`--fail-under=100`) |
| Mutation | mutmut + `scripts/check_msi.py` | MSI ≥ 85 % | oui | seuil dans `python/scripts/check_msi.py` (`THRESHOLD`), cibles dans `python/pyproject.toml` (`[tool.mutmut]`) |
| Schéma données | frictionless validate | schémas `data/schemas/*.json` valides | oui | `Makefile` (`--type schema`) |
| Cohérence données | `python -m checks` | invariants métier des CSV de `data/data/` (indices, périodes, bornes, `source`) | oui | `Makefile` (`BUDGET_DATA_DIR`), bornes dans `data/coherence.toml` |

Le MSI (Mutation Score Indicator) est calculé comme le badge mutmut :
`(killed + timeout) / (total - skipped)`.

### Seuils PHP (brique Symfony)

| Garde-fou | Outil | Seuil / règle visé | État |
|-----------|-------|--------------------|------|
| Style | PHP-CS-Fixer | PSR-12 + `declare(strict_types=1)` | bloquant (livré #9), config `symfony/.php-cs-fixer.dist.php` |
| Typage | PHPStan | `level: max` | bloquant (livré #9), config `symfony/phpstan.dist.neon` |
| Tests | PHPUnit | TDD, suite verte | bloquant (livré #9), config `symfony/phpunit.dist.xml` |
| Mutation | Infection | MSI ≥ 85 % (mutation sur `src/Domain`) | bloquant (#12), config `symfony/infection.json.dist` |
| Architecture | Deptrac | domaine sans Doctrine ni infra | bloquant (#12), config `symfony/deptrac.yaml` |

Depuis #9, `make qa-symfony` exécute PHP-CS-Fixer, PHPStan et PHPUnit dans le
conteneur et est **bloquant** au premier garde-fou KO (cf. « Lancer la chaîne
qualité »). Depuis #12, **Deptrac** y est câblé en bloquant (frontière `App\Domain`
⊥ `Doctrine\*` et `App\Infrastructure\*`, config `symfony/deptrac.yaml`) et
**Infection** en bloquant (mutation testing sur `src/Domain`, `minMsi` 85 dans
`symfony/infection.json.dist` ; le driver de couverture `pcov` est embarqué dans
l'image, cf. `symfony/Dockerfile`).

## Standards de code

### Commun

- **TDD** : test d'abord (rouge → vert → refacto). Aucun code sans test l'exerçant.
- **SOLID**, responsabilités étroites, pas de code mort ni de commentaire périmé.
- Rien ne se merge sans `make qa` vert de bout en bout.

### Python

- Cible 3.13+ ; `uv` pour l'environnement et l'exécution.
- Typage complet (mypy strict) ; pas de `# type: ignore` sans justification.
- Style et imports gérés par ruff (`ruff format`, lint `I` pour le tri).
- Cœur (`src/ingest`, `src/sources`, `src/contract`, `src/checks`) couvert à 100 %.

### PHP (Symfony, à venir)

- PHP 8.4, **`declare(strict_types=1)`** en tête de chaque fichier, PSR-12.
- Domaine métier **sans dépendance à Doctrine ni à l'infra** (frontières Deptrac) ;
  règles de calcul en services taggés, enfichables.
- PHPStan `level: max`, Infection MSI ≥ 85 %, PHPUnit en TDD.

## Protection de la branche `main`

À activer par un **administrateur du dépôt** (étape manuelle, hors CI). Commande
prête à copier — exige le check CI `qa`, une revue de PR et interdit les
contournements :

```sh
gh api -X PUT repos/nmoral/budget-analyzer/branches/main/protection \
  -H "Accept: application/vnd.github+json" \
  --input - <<'JSON'
{
  "required_status_checks": {
    "strict": true,
    "contexts": ["qa"]
  },
  "enforce_admins": true,
  "required_pull_request_reviews": {
    "required_approving_review_count": 1,
    "dismiss_stale_reviews": true
  },
  "required_linear_history": true,
  "allow_force_pushes": false,
  "allow_deletions": false,
  "restrictions": null
}
JSON
```

Le contexte `qa` correspond au job du workflow `.github/workflows/qa.yml`. Adapter
le nombre de revues requis selon la politique de l'équipe.

**Méthode de merge des PR : squash.** L'historique linéaire est imposé
(`required_linear_history`), donc pas de merge commit ; les PR sont intégrées en
**squash** (un commit par PR sur `main`, le détail des jalons restant dans la PR).
