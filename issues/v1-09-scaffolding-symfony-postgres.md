# Squelette de l'application Symfony (sous-projet `symfony/`)

**Milestone:** V1 — Simulateur deux profils
**Labels:** area:backend, type:infra, priority:high
**Dépend de:** #27

## Contexte

La brique de rendu est une application Symfony qui calcule et affiche ; elle charge Postgres à l'import et n'y touche qu'à l'import (volumétrie : quelques Mo). Elle vit dans `symfony/` du monorepo ; la stack Docker, les ports `FPYYY` et l'isolation worktree sont posés par #27 — cette issue ne porte que le squelette applicatif.

## Objectif

Initialiser l'application Symfony dans `symfony/`, branchée sur le service Postgres de la stack Docker de dev (#27).

## Tâches

- [ ] Projet Symfony + Doctrine installé dans `symfony/`, dépendances dans l'image (composer install au build, pas sur l'hôte).
- [ ] Connexion à Postgres via les variables d'environnement de la stack (#27).
- [ ] Arborescence du domaine distincte de l'infra Doctrine (préparation du moteur sans Doctrine, #12).
- [ ] Chaîne qualité PHP (#00) câblée : PHPUnit, Infection, PHPStan, PHP-CS-Fixer, exécutables via `make qa`.

## Critères d'acceptation

- `make up` (#27) démarre l'app Symfony qui répond, connectée à une base vide prête à l'import.
- `make qa` lance la chaîne qualité PHP dans le conteneur.

## Références

Spec §Rendu (Symfony) · issues #00, #27
