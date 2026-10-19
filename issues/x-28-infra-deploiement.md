# Sous-projet `infra/` — déploiement

**Milestone:** Transverse (fondation)
**Labels:** type:infra, priority:high
**Dépend de:** #27

## Contexte

`infra/` est le troisième sous-projet du monorepo (#27) : il porte tout le déploiement de l'outil public open source, de façon reproductible et autonome. Il consomme les images des briques `python/` et `symfony/` et orchestre la mise en ligne.

## Objectif

Fournir le nécessaire pour déployer l'outil de bout en bout : images de prod, orchestration, pipeline CI/CD.

## Tâches

- [ ] Dockerfiles de production des briques (multi-stage, images minimales, non-root).
- [ ] `docker-compose.prod.yml` (ou manifestes équivalents) : symfony + postgres + reverse proxy, variables par environnement.
- [ ] Pipeline CI/CD : build des images, exécution de la chaîne qualité (#00) comme gate, publication des images, déploiement.
- [ ] Procédure d'import en prod : chargement d'un commit du dépôt de données via la commande d'import (#11), dans une transaction.
- [ ] Secrets et configuration par environnement, hors du repo (documentés dans `.env.example`).
- [ ] Sauvegarde/restauration de Postgres documentée.
- [ ] README `infra/` : déployer, mettre à jour, importer un nouveau jeu de paramètres, rollback.

## Critères d'acceptation

- Un déploiement se fait depuis `infra/` avec des images construites par la CI, sans étape manuelle non documentée.
- La CI bloque la publication si la chaîne qualité (#00) échoue.
- L'obligation AGPL (publier les modifications d'une version déployée, #23) est rappelée dans le README `infra/`.

## Références

Spec §Rendu (Symfony) · §Règles de transparence (6) · issues #00, #11, #23, #27
