# budget-analyser

Simulateur du coût des recrutements publics. Monorepo conteneurisé et autonome : un clone + `make up`, rien à installer sur l'hôte hors Docker.

## Structure

```
budget-analyser/
├── python/   # agrégation des sources publiques (brique V0)
├── symfony/  # moteur de calcul + API + front (brique V1)
├── infra/    # déploiement : images prod, compose, CI/CD
└── data/     # dépôt de données : seul point de passage entre les briques
```

Le dépôt `data/` est le seul lien entre les briques : Python y écrit des CSV normalisés, Symfony les lit uniquement à l'import. Les CSV ne sont jamais lus à l'exécution.

## Démarrage

```sh
cp .env.example .env     # ajuster les ports selon le worktree
make up                  # démarre postgres + python + symfony
make down                # arrête et détruit conteneurs + volumes du worktree
```

## Qualité — ceinture et bretelles

Rien ne se merge sans que toute la chaîne soit verte (`make qa`). Les garde-fous :

- tests unitaires (PHPUnit / pytest), écrits en premier (TDD) ;
- mutation : MSI ≥ 85 % (Infection / mutmut) ;
- analyse statique au max (PHPStan level max / mypy strict) ;
- frontières d'architecture (Deptrac) : domaine sans Doctrine ni infra ;
- style (PHP-CS-Fixer PSR-12 / ruff, imports triés) ;
- couverture ≥ 90 % global, 100 % sur le cœur métier (`src/ingest`, `src/sources`, `src/contract`) ;
- validation des schémas de données (Frictionless).

État actuel : la chaîne **Python est bloquante** ; la brique **PHP a sa config posée
mais non bloquante** (`make qa-symfony` skip tant que `symfony/vendor` est absent),
à durcir quand le squelette Symfony arrivera. Le tableau des seuils réellement
appliqués fait référence dans [`CLAUDE.md`](CLAUDE.md).

### Comment vérifier

```sh
make qa          # entrée unique : qa-python puis qa-symfony
make qa-python   # chaîne Python complète et bloquante
make qa-symfony  # skip explicite tant que symfony/vendor est absent
```

`make qa-python` enchaîne, dans l'ordre et en échouant au premier garde-fou KO :
ruff (lint + tri des imports), ruff format, mypy strict, pytest + couverture
(≥ 90 % global, 100 % sur le cœur), mutation mutmut (porte MSI ≥ 85 %) et
`frictionless validate` sur les schémas. Toutes les commandes Python tournent via
`uv` depuis `python/`.

En local, installer les hooks pour rejouer ruff, ruff format, mypy et pytest avant
chaque commit :

```sh
uv run --project python pre-commit install
uv run --project python pre-commit run --all-files   # lancer à la demande
```

La CI GitHub Actions (`.github/workflows/qa.yml`) rejoue exactement `make qa` sur
chaque push vers `main` et chaque PR : même source unique que le local (le `Makefile`).

## Isolation worktree

Ports au format `FPYYY` (P=5 pour ce projet), conteneurs et volumes préfixés par `COMPOSE_PROJECT_NAME`. Deux worktrees tournent en parallèle sans collision ; `make down` détruit les volumes d'un worktree sans toucher aux autres.

## Licences

Code en AGPL-3.0, données dérivées en Licence Ouverte 2.0 (Etalab).
