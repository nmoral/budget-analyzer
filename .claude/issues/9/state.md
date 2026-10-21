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

## Décisions (validées 2026-10-21 — « ok à tout »)

1. Distribution : `symfony/skeleton` minimal (packs ajoutés quand #19/#20 le demanderont).
2. Version : dernière Symfony 7.x stable, PHP 8.4.
3. Serveur web : PHP intégré `php -S 0.0.0.0:8000 -t public` en `command` du service symfony (remplace `sleep infinity`).
4. Dépendances : `composer install` au build + volume anonyme `/app/vendor` dans le compose (le vendor de l'image n'est pas masqué par le bind-mount).
5. QA PHP « dans le conteneur » : `qa-symfony` via `docker compose run --rm symfony …` ; `qa.yml` adapté pour build + exécution du conteneur (fait partie de #9).
6. Outils bloquants dès #9 : PHP-CS-Fixer, PHPStan (level max), PHPUnit. Infection (MSI ≥ 85 %) et Deptrac reportés à #12 (restent non bloquants).
7. Doctrine : DoctrineBundle installé + connexion à base vide vérifiée ; entités/migrations = #10.
8. Arborescence : `App\Domain\` (PHP pur, zéro Doctrine) et `App\Infrastructure\` (Doctrine/infra).

## Critères d'acceptation (vérifiables)

- **AC1** — `make build` produit une image contenant `vendor/` : `docker compose run --rm symfony test -f vendor/autoload.php` → exit 0.
- **AC2** — `make up` démarre la stack et l'app répond : `curl -fsS localhost:$WEB_PORT/health` → HTTP 200.
- **AC3** — Connexion Postgres sur base vide : `docker compose run --rm symfony php bin/console dbal:run-sql "SELECT 1"` → renvoie 1 ; aucune table applicative présente.
- **AC4** — Séparation domaine/infra : `src/Domain/` et `src/Infrastructure/` existent, mappés dans `composer.json` (`App\Domain\`, `App\Infrastructure\`) ; aucune occurrence `Doctrine` sous `src/Domain/`.
- **AC5** — `make qa` (→ `qa-symfony`) exécute dans le conteneur et passe, bloquant : PHP-CS-Fixer (dry-run), PHPStan level max, PHPUnit — vert.
- **AC6** — CI `qa.yml` build l'image symfony et exécute la chaîne PHP ; job `qa` vert.

## Hors périmètre

Entités/migrations Doctrine (#10) · commande d'import (#11) · moteur de domaine + règles taggées (#12) ·
Infection/Deptrac bloquants (#12) · API JSON (#19) · front/Twig (#20) · infra de prod (#28).

## Tests proposés (statut de validation)

- T1 — (en cours de proposition)

## Journal

- 2026-10-21 — Création du state, lecture issue #9 + existant.
- 2026-10-21 — Décisions 1→8 validées (« ok à tout »). Critères AC1→AC6 et hors-périmètre posés. Passage aux tests.
