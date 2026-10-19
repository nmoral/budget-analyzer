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

Rien ne se merge sans que toute la chaîne soit verte (`make qa`) :

- tests unitaires (PHPUnit / pytest), écrits en premier (TDD) ;
- mutation : MSI ≥ 85 %, MSI couvert ≥ 90 % (Infection / mutmut) ;
- analyse statique au max (PHPStan level max / mypy strict) ;
- frontières d'architecture (Deptrac) : domaine sans Doctrine ni infra ;
- style (PHP-CS-Fixer PSR-12 / ruff) ;
- couverture ≥ 90 % global, 100 % sur le cœur métier ;
- validation des données (schémas Frictionless + contrôles de cohérence).

## Isolation worktree

Ports au format `FPYYY` (P=5 pour ce projet), conteneurs et volumes préfixés par `COMPOSE_PROJECT_NAME`. Deux worktrees tournent en parallèle sans collision ; `make down` détruit les volumes d'un worktree sans toucher aux autres.

## Licences

Code en AGPL-3.0, données dérivées en Licence Ouverte 2.0 (Etalab).
