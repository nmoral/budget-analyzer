# Documentation technique — brique Symfony

Ce document décrit l'architecture du squelette de l'application `symfony/` tel qu'il
existe aujourd'hui, et comment le lancer et le tester en local. Les seuils et la
chaîne qualité ne sont pas dupliqués ici : ils font foi dans `CLAUDE.md` (source
unique).

## Pile technique

- PHP 8.4 (`require: php >=8.4`), Symfony 7.x.
- Application construite sur le `MicroKernelTrait` (`App\Kernel`, `src/Kernel.php`).
- `doctrine/doctrine-bundle` branché en **DBAL seule** (aucun ORM, voir plus bas).
- Serveur web : **serveur PHP intégré**, pas de PHP-FPM / nginx à ce stade.

## Organisation du code et séparation domaine / infrastructure

Le code applicatif vit sous `symfony/src/` avec un mapping PSR-4 déclaré dans
`composer.json` :

| Préfixe PSR-4 | Dossier | Rôle |
|---------------|---------|------|
| `App\` | `src/` | racine de l'application |
| `App\Domain\` | `src/Domain/` | domaine métier en **PHP pur**, sans dépendance à Doctrine ni à l'infra |
| `App\Infrastructure\` | `src/Infrastructure/` | adaptateurs techniques (contrôleurs, persistance, etc.) |

Les tests sont sous `tests/` (`App\Tests\`, mapping `autoload-dev`).

À ce stade, `src/Domain/` et `src/Infrastructure/` sont versionnés via des `.gitkeep`
(aucun code métier encore). La frontière « le domaine ne connaît ni Doctrine ni
l'infra » est une décision structurante (voir `CLAUDE.md`, section PHP) et sera
rendue bloquante par Deptrac avec le moteur de calcul (#12).

Le seul composant applicatif livré est le contrôleur de santé
`App\Infrastructure\Controller\HealthController`, routé par attribut sur `/health`
(méthode `GET`), qui renvoie `{"status":"ok"}`. Les routes sont chargées par attribut
depuis `src/Infrastructure/Controller/` (`config/routes.yaml`).

## Kernel et amorçage

- `App\Kernel` utilise `MicroKernelTrait` ; la configuration est en YAML sous
  `config/` (`bundles.php`, `packages/framework.yaml`, `packages/doctrine.yaml`,
  `routes.yaml`, `services.yaml`).
- `public/index.php` est le point d'entrée HTTP **et** le script routeur du serveur
  PHP intégré : il amorce l'environnement via `symfony/dotenv` (`.env` s'il existe),
  détermine `APP_ENV` / `APP_DEBUG`, puis instancie et exécute le `Kernel`.
- `bin/console` fournit la console Symfony (style classique, sans `symfony/runtime`,
  cohérent avec `public/index.php`). Les commandes se lancent dans le conteneur :
  `docker compose run --rm symfony php bin/console <cmd>`.

## Persistance : Doctrine DBAL seule

`config/packages/doctrine.yaml` ne configure **que** le bloc `dbal`, avec l'URL de
connexion lue dans `DATABASE_URL` (`%env(resolve:DATABASE_URL)%`). Aucun ORM n'est
installé : pas d'entité, pas de mapping, pas de migration. Le schéma `public` de la
base reste donc vide à ce stade. Le modèle de données arrive avec #10.

Conséquence pour les tests : la connexion DBAL est fournie par `docker compose` au
conteneur ; les tests qui l'exercent (`DatabaseConnectionTest`) exigent une base
Postgres saine, donc `make up` (ou le `up --wait postgres` de `make qa-symfony`). Le
test du endpoint `/health` (`HealthControllerTest`, `WebTestCase`) tourne, lui, sans
base.

## Docker et exécution locale

La stack est décrite dans `docker-compose.yml` à la racine du monorepo. Services
concernés par la brique Symfony :

- `postgres` (image `postgres:16`) avec `healthcheck` `pg_isready` ;
- `symfony` : image construite depuis `symfony/Dockerfile`, dépend de `postgres`
  (démarrage conditionné à `service_healthy`).

Le service `symfony` :

- expose le serveur PHP intégré via la commande
  `php -S 0.0.0.0:8000 -t public public/index.php` (port interne `8000`, publié sur
  `${WEB_PORT}`) ;
- reçoit `DATABASE_URL`, `APP_ENV=dev`, `APP_DEBUG=1`, `APP_SECRET` par
  l'environnement du compose ;
- monte `./symfony` dans `/app` et utilise un **volume anonyme `/app/vendor`** pour
  préserver le `vendor/` construit dans l'image malgré le bind-mount ;
- tourne sous un **utilisateur non-root `app`** dont l'UID/GID est aligné sur celui
  de l'hôte (build args `UID`/`GID`, exportés par le `Makefile` depuis `id -u`/`id -g`,
  défaut `1000`). Ainsi tout ce que le conteneur écrit sur le bind-mount
  (`var/cache`, `var/log`, caches des outils…) appartient à l'utilisateur et non à
  `root` : un `git worktree remove` ou un `rm` ordinaire suffit, sans `sudo`. Le point
  de montage `symfony/vendor` est pré-créé par le `Makefile` (`mkdir -p`) pour que le
  démon Docker ne le crée pas lui-même en `root`.

Le `Dockerfile` (base `php:8.4-cli`) installe les extensions `intl`, `pdo_pgsql`,
`opcache`, copie Composer, crée l'utilisateur `app` (UID/GID hôte), puis — sous cet
utilisateur — fait le `composer install` au build (couche de dépendances isolée) et un
`composer dump-autoload --optimize` après copie du code.

### Ports et `.env`

`docker compose` lit un `.env` (gitignoré, propre au worktree) dérivé de
`.env.example`. Les ports suivent le format `FPYYY` (F = famille service, P = projet,
YYY = worktree) :

- `WEB_PORT` (famille 4) → `45YYY`, publie le serveur web ;
- `DB_PORT` (famille 5) → `55YYY`, publie Postgres ;
- `POSTGRES_DB` / `POSTGRES_USER` / `POSTGRES_PASSWORD` et `COMPOSE_PROJECT_NAME`
  isolent la stack du worktree.

### Commandes utiles

```sh
make up        # docker compose up -d (stack complète)
make down      # docker compose down -v
make build     # build des images

# sonde de santé une fois la stack démarrée
curl -fsS localhost:$WEB_PORT/health     # -> {"status":"ok"}

# console Symfony
docker compose run --rm symfony php bin/console <cmd>

# logs
docker compose logs symfony
```

## Chaîne qualité et CI

La chaîne qualité PHP est exécutée **dans le conteneur** et **bloquante** depuis #9.
`make qa-symfony` build l'image `symfony`, démarre Postgres (`up -d --wait postgres`)
puis enchaîne PHP-CS-Fixer (dry-run), PHPStan (`level: max`) et PHPUnit, en échouant
au premier garde-fou KO. `make qa` lance `qa-python` puis `qa-symfony`.

En CI (`.github/workflows/qa.yml`, job `qa`), le `.env` est dérivé de `.env.example`
(`cp .env.example .env`) avant `make qa`, ce qui build l'image `symfony` et exécute
la chaîne PHP.

Les seuils et outils (PHP-CS-Fixer PSR-12 + `strict_types`, PHPStan `level: max`,
PHPUnit ; Infection et Deptrac à venir avec #12) sont décrits dans `CLAUDE.md`, qui
fait foi.
