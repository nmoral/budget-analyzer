# Issue #9 — Squelette de l'application Symfony (`symfony/`)

**Étape : analyse**
**Branche de dev prévue : `ccr-9239aabd-epmpye`**

## Résumé de l'issue

Initialiser l'app Symfony dans `symfony/`, branchée sur le Postgres de la stack Docker
de dev. Dépend de #27 (monorepo + Docker). Livrables :
- Projet Symfony + Doctrine dans `symfony/`, `composer install` au build (pas sur l'hôte).
- Connexion Postgres via les variables d'env de la stack.
- Arborescence domaine distincte de l'infra Doctrine (prépare #12).
- Chaîne qualité PHP câblée (PHPUnit, Infection, PHPStan, PHP-CS-Fixer) via `make qa`.

Critères d'acceptation (bruts de l'issue) :
- `make up` démarre l'app Symfony qui répond, sur une base vide prête à l'import.
- `make qa` lance la chaîne qualité PHP **dans le conteneur**.

## État des lieux (existant au démarrage)

Une grande partie de l'infra #27 est **déjà posée** :
- `docker-compose.yml` : services `postgres` (16), `symfony` (build ./symfony, port WEB_PORT:8000,
  `command: sleep infinity`, DATABASE_URL injectée), `python`. Monte `./symfony:/app` et `./data:/data:ro`.
- `.env.example` : ports FPYYY (web 45000, db 55000), creds Postgres.
- `Makefile` : `up`, `down`, `build`, `qa`, `qa-python`, `qa-symfony` (skip tant que `symfony/vendor` absent), `import` (TODO #11).
- `symfony/Dockerfile` : php:8.4-cli + intl/pdo_pgsql/opcache + composer. **Pas de `composer install`** (pas de composer.json).
- `symfony/` : stubs QA posés non bloquants — `.php-cs-fixer.dist.php`, `phpstan.dist.neon` (level max), `phpunit.dist.xml`. README.
- `.github/workflows/qa.yml` : CI lance `make qa` **directement sur le runner ubuntu** (uv pour Python). Pas de PHP, pas de docker compose démarré.
- `.gitignore` : `/symfony/vendor/`, `/symfony/var/`, `/symfony/.phpunit.cache/` déjà ignorés.

## Écarts / trous identifiés

1. Aucun projet Symfony réel (pas de composer.json, src/, public/index.php, bin/console).
2. Service `symfony` en `sleep infinity` → pas de serveur web ; critère "app qui répond" non tenu.
3. `composer install au build` vs bind-mount `./symfony:/app` qui masque `/app/vendor` de l'image (conflit à résoudre).
4. `qa-symfony` skippe et n'exécute aucun outil ; comment lancer "dans le conteneur" ?
5. CI (`qa.yml`) ne démarre ni PHP ni docker → si qa-symfony devient bloquant, la CI casse sans adaptation.
6. Infection (MSI ≥ 85 %) et Deptrac ont besoin de code+tests pour être utiles ; squelette quasi vide.
7. Périmètre Doctrine : bundle + connexion seulement (entités/migrations = #10) — à confirmer.

## Questions ouvertes

(en attente des réponses de l'utilisateur — cf. journal)

## Journal

- 2026-10-21 — Création du state, lecture issue #9 + existant. Aucune question tranchée pour l'instant.
