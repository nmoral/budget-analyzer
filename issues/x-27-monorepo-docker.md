# Monorepo + Docker (python / symfony / infra), autonome

**Milestone:** Transverse (fondation)
**Labels:** type:infra, priority:critical
**Dépend de:** —

## Contexte

Le projet est un **monorepo** à trois sous-projets, entièrement **conteneurisé et autonome** : un clone + un `docker compose up` suffit, aucune dépendance installée sur l'hôte (hors Docker). Le dépôt de données reste le seul point de passage entre les briques (spec §Architecture) ; il vit à la racine du monorepo et n'est lu par Symfony qu'à l'import.

## Structure cible

```
budget-analyser/
├── python/      # agrégation : scripts d'ingestion, contrôles, schémas (brique V0)
├── symfony/     # moteur de calcul + API + front (brique V1)
├── infra/       # déploiement : compose prod, images, CI/CD (voir #28)
├── data/        # dépôt de données : raw/, data/, schemas/, manifests/ (contrat #01)
├── docker-compose.yml        # stack de dev (python, symfony, postgres)
├── Makefile                  # entrées uniformes : up, down, qa, import…
└── README.md
```

## Objectif

Poser l'ossature du monorepo, la stack Docker de dev et les commandes uniformes, isolables par worktree.

## Tâches

- [ ] Créer les quatre répertoires (`python/`, `symfony/`, `infra/`, `data/`) avec leur README.
- [ ] `docker-compose.yml` de dev : services python, symfony (PHP-FPM/FrankenPHP), postgres — tout tourne en conteneur, rien sur l'hôte.
- [ ] Autonomie : images construites depuis le repo, dépendances installées dans les images (composer/uv), aucune install hôte requise.
- [ ] Isolation worktree (CLAUDE.md) : ports `FPYYY` configurables via `.env`, noms de conteneurs et volumes préfixés par worktree, **aucun volume/base partagé** entre worktrees.
- [ ] `Makefile` racine : `up`, `down` (conteneurs + volumes dédiés), `qa` (délègue aux deux briques), `import`.
- [ ] `.env.example` documentant ports, préfixes et variables ; `.env`/`.env.local` non versionnés.

## Critères d'acceptation

- Sur une machine avec seulement Docker, `make up` démarre toute la stack sans rien installer d'autre.
- Deux worktrees démarrent en parallèle sans collision de port, de conteneur ni de volume.
- `make down` détruit conteneurs et volumes du worktree sans toucher aux autres.
- `make qa` lance la chaîne qualité (#00) des deux briques dans Docker.

## Références

Spec §Architecture · CLAUDE.md §Workflow worktree · issues #00, #01, #28
