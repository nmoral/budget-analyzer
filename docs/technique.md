# Documentation technique — brique Symfony

Ce document décrit l'architecture du squelette de l'application `symfony/` tel qu'il
existe aujourd'hui, et comment le lancer et le tester en local. Les seuils et la
chaîne qualité ne sont pas dupliqués ici : ils font foi dans `CLAUDE.md` (source
unique).

## Pile technique

- PHP 8.4 (`require: php >=8.4`), Symfony 7.x.
- Application construite sur le `MicroKernelTrait` (`App\Kernel`, `src/Kernel.php`).
- `doctrine/doctrine-bundle` avec **Doctrine ORM** (`doctrine/orm ^3.7`) et
  `doctrine/doctrine-migrations-bundle ^3.7` (voir « Persistance » plus bas).
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

`src/Domain/` reste en **PHP pur** (aucune occurrence de Doctrine, frontière vérifiée
par `DomainBoundaryTest`). Le modèle de données Doctrine (entités et repositories) vit
sous `src/Infrastructure/` : c'est un **modèle de persistance**, pas le domaine métier.
La frontière « le domaine ne connaît ni Doctrine ni l'infra » est une décision
structurante (voir `CLAUDE.md`, section PHP) et sera rendue bloquante par Deptrac avec
le moteur de calcul (#12).

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

## Persistance : Doctrine ORM

`config/packages/doctrine.yaml` configure le bloc `dbal` (URL de connexion lue dans
`DATABASE_URL`, `%env(resolve:DATABASE_URL)%`) **et** le bloc `orm`. Le mapping est
déclaré par **attributs** sur les entités de `src/Infrastructure/Entity`
(`prefix App\Infrastructure\Entity`, alias `App`). La naming strategy
`underscore_number_aware` produit des colonnes en `snake_case`. Les migrations sont
gérées par `doctrine-migrations-bundle` (`config/packages/doctrine_migrations.yaml`,
namespace `App\Migrations` → `symfony/migrations/`). La migration initiale
(`Version20261029171156`) crée l'ensemble du schéma.

### Modèle de données

Le modèle de persistance comprend **12 entités** dans `src/Infrastructure/Entity/`.
Le code est en anglais, aligné sur les colonnes CSV du dépôt `data/` ; les noms de
domaine français (Corps, Grille, Échelon) sont conservés comme noms d'entité. Les
identifiants sont des entiers auto-générés ; les montants sont en `DECIMAL` (mappés en
`string` PHP) ; les dates en `date_immutable`.

- **Source** — origine documentaire d'une valeur réglementaire : `code` (clé métier
  unique, slug référencé par la colonne `source` des CSV, ex. `decret-2023-519`, résolu
  à l'import #11), `label`, `publisher`, `url`, `retrievedAt`, `fileHash`.
- **Corps** — `code` (unique), `label`, `versant`, `category` (A/B/C).
- **Grille** — version d'une grille indiciaire : `body` (FK Corps), `grade`, `version`,
  `startDate`, `endDate` (nullable), `source` (FK), `parameterSet` (FK). Valeur datée.
- **Echelon** — `grille` (FK), `rank`, `grossIndex`, `increasedIndex`,
  `durationMonths` (nullable). Valeur datée **via sa grille** (pas de FK directe vers
  le jeu de paramètres).
- **RetirementScheme** — `code` (unique), `label`.
- **Funder** — `code` (unique), `label`, puis codes de nomenclature tous nullables :
  `mission` / `program` / `action` (État), `ondamSubObjective`, `cofog`.
- **DatedParameter** — valeur scalaire réglementaire : `key` (`point_indice`,
  `taux_cas_civil`…), `value`, `unit`, `startDate`, `endDate` (nullable), `source` (FK),
  `parameterSet` (FK). Valeur datée. La colonne `key` est échappée (`` `key` ``).
- **PrimeRate** — taux de primes (primes V1) : `body` (FK Corps), `value`,
  `observationYear`, `source` (FK), `fallback` (booléen), `parameterSet` (FK).
- **Profile** — unité de simulation : `body` (FK), `grade`, `entryEchelon` (FK Echelon),
  `retirementScheme` (FK), `indemnitaryScheme` (chaîne nullable — pas d'entité dédiée,
  les primes V1 passent par `PrimeRate`), `funder` (FK), `status`, `referenceCalendar`.
- **Scenario** — `title`, `provenance`, `announcedAmount` (DECIMAL nullable),
  `parameterSet` (FK).
- **ScenarioLine** — `scenario` (FK), `profile` (FK), `volume`, `recruitmentDate`,
  `spreadYears`.
- **ParameterSet** — jeu de paramètres pour la reproductibilité : `versionId` (unique,
  commit du dépôt de données), `importedAt`. Les valeurs datées (DatedParameter, Grille,
  Echelon via sa grille, PrimeRate) et les Scénarios portent une FK vers ce jeu.

Les entités sont **purement déclaratives** (aucun `repositoryClass`, aucune logique) ;
le peuplement réel est assuré par la commande d'import `app:import` (voir « Import du
dépôt de données » plus bas).

### Valeurs datées : sémantique et repositories

Les accès « datés » sont des **services autonomes** de `src/Infrastructure/Repository/`
(l'`EntityManagerInterface` est injecté ; les entités ne déclarent pas de repository).

- **Bornes de validité incluses** : une valeur datée est en vigueur sur
  `[startDate, endDate]`, bornes **incluses** ; `endDate` à `NULL` = période ouverte
  (∞). L'adjacence est autorisée (fin au jour J, début au jour J+1). Cette sémantique
  suit le contrat Python (`python/src/checks` `check_periods_no_overlap`, bornes
  incluses) et les données (`taux_cas_civil` : 2025-12-31 → 2026-01-01).
- **« Valeur en vigueur à la date D »** = l'unique ligne où `startDate ≤ D` et
  (`endDate IS NULL OR endDate ≥ D`), regroupée par `key` (`DatedParameterRepository::`
  `valueInEffectAt`) ou par `body_code` + `grade` (`GrilleRepository::valueInEffectAt`),
  et **scopée à un jeu de paramètres**. L'unicité repose sur l'invariant de
  non-chevauchement des périodes d'un même groupe, garanti en amont (contrôles Python) ;
  l'import #11 n'ajoute **pas** de contrainte DB d'unicité (décision Q7, voir « Import du
  dépôt de données »). Retourne `null` si aucune période ne couvre D.
- **Scope du jeu de paramètres** : les deux méthodes prennent un `ParameterSet`
  optionnel ; à défaut, elles utilisent le **dernier jeu importé**
  (`ParameterSetRepository::latest()`, tri `importedAt` puis `id` décroissants).
  Si aucun jeu n'existe, le résultat est `null`.
- **Taux de primes « pour l'année Y »** (`PrimeRateRepository::forYear`) : dernier
  `observationYear ≤ Y` pour le corps ; à année égale, le tri `fallback ASC` fait
  gagner la ligne non-fallback. L'entité renvoyée porte le drapeau `fallback`. Cette
  méthode n'est **pas scopée à un jeu de paramètres** (le scope n'est pas requis par
  #10 ; il pourra être ajouté si le moteur de calcul #12 l'exige). `null` si aucune
  observation `≤ Y`.

### Tests et exécution

Les tests de persistance (`tests/Persistence/`) s'appuient sur `IntegrationTestCase`,
qui **recrée le schéma depuis le mapping ORM** en `setUp`/`tearDown` et laisse la base
vide après chaque test (le test #9 `DatabaseConnectionTest`, qui exige un schéma
`public` vide, reste donc vert). `MappingValidationTest` valide le mapping sans dépendre
de l'état de la base. Les repositories datés sont exposés `public` **en environnement de
test seulement** (`config/services.yaml`, bloc `when@test`) pour être récupérables
depuis le conteneur de test, faute de référence applicative tant que le moteur #12 ne
les injecte pas.

Les tests d'intégration ORM exigent une base Postgres saine, donc `make up` (ou le
`up --wait postgres` de `make qa-symfony`). Le test du endpoint `/health`
(`HealthControllerTest`, `WebTestCase`) tourne, lui, sans base.

### Commandes Doctrine

```sh
docker compose run --rm symfony php bin/console doctrine:migrations:migrate --no-interaction
docker compose run --rm symfony php bin/console doctrine:schema:validate
docker compose run --rm symfony php bin/console doctrine:query:sql '<SQL>'
```

`migrate` sur base vide suivi de `schema:validate` confirme que le mapping est correct
et que le schéma est « in sync ».

## Import du dépôt de données (`app:import`)

La commande console `app:import <commit>` peuple le modèle de persistance à partir du
dépôt de données. Elle vit sous `src/Infrastructure/Command/ImportCommand.php` et délègue
tout le travail au service `App\Infrastructure\Import\Importer`.

```sh
docker compose run --rm symfony php bin/console app:import <commit>
docker compose run --rm symfony php bin/console app:import <commit> --data-dir=/chemin/fixture
```

- Argument `commit` (requis) : référence du commit du dépôt de données. C'est une
  **chaîne opaque** utilisée comme `version_id` du `ParameterSet` ; elle n'est **pas**
  résolue ni checkoutée (le conteneur ne monte pas `.git` — décision Q1). L'appelant
  (p. ex. CI via `git rev-parse HEAD`) fournit la valeur.
- Option `--data-dir` : racine du dépôt de données à importer. Défaut lié au paramètre
  `env(BUDGET_DATA_DIR)` (`/data`, montée en lecture seule dans le conteneur) ; surchargée
  pour viser une racine fixture.
- Codes de sortie : `0` au succès ; en cas d'`ImportException`, la commande affiche le
  message d'erreur et retourne `Command::FAILURE` (≠ 0).

### Composants de l'import

Sous `src/Infrastructure/Import/` :

- **`Importer`** : orchestre tout l'import dans **une seule transaction**
  (`EntityManagerInterface::wrapInTransaction`). Tout échec lève une `ImportException`
  (classe de base) et provoque le **rollback total** — aucune écriture partielle.
- **`CsvReader`** : lecture d'un CSV (`data/data/<fichier>.csv`) en en-tête + lignes
  associatives.
- **`FrictionlessSchemaValidator`** : validation de schéma **maison** (aucune lib
  Frictionless en PHP). Contrôle, contre `data/schemas/<schéma>.json` : présence des
  colonnes requises, valeurs requises non vides, et typage des valeurs non vides
  (`number`, `integer`, `year`, `boolean`, `date` ISO ; `string` et types non contraints
  acceptés). Les colonnes non requises peuvent être vides (ex. `end_date`). Lève
  `SchemaValidationException`. Ne contrôle que la **forme** (décision Q6) : les invariants
  métier restent côté Python.
- **`ManifestVerifier`** : vérifie l'intégrité des bruts par
  `sha256(data/sources/<manifest.raw>) == manifest.sha256`, manifeste lu dans
  `data/manifests/<fichier>.json`. Toute divergence (ou brut/manifeste manquant) lève
  `ManifestMismatchException` (décision Q4).
- **`SourceReferential`** / **`CorpsReferential`** : chargent les référentiels curatés
  `data/sources.json` (slug → `label`/`publisher`/`url`/`retrievedAt`/`fileHash`) et
  `data/corps.json` (code → `label`/`versant`/`category`).

### Périmètre, mapping et ordre de chargement

Le mapping fichier → schéma est la constante `Importer::FILE_TO_SCHEMA`, alignée sur le
`FILE_TO_SCHEMA` Python **hors `couts_pap`** (non importé, aucune entité cible — hors
périmètre #11). Avant tout chargement, l'`Importer` valide le schéma **et** vérifie
l'empreinte de **chacun** des 9 fichiers du mapping (C3 « avant tout chargement », C4).

Le chargement peuple ensuite, dans l'ordre :

1. `DatedParameter` (fichiers `point_indice`, `taux_cas_civil`, `taux_cas_militaire`,
   `taux_cnracl`) — FK `source`, FK `parameterSet` ;
2. `Grille` (`grille_pe`, `grille_isg`) — FK `body` (Corps), `source`, `parameterSet` ;
   chaque grille est indexée en mémoire par la clé `{body_code}_{grade}_{version}` ;
3. `Echelon` (`echelon_pe`, `echelon_isg`) — rattaché à sa `Grille` par la colonne
   `grille` du CSV, résolue via cette clé composite ; clé introuvable → `ImportException`
   (« grille inconnue ») ;
4. `PrimeRate` (`taux_primes`) — FK `body` (Corps), `source`, `parameterSet`.

Sur le dépôt réel, cela produit les comptes : `dated_parameter` = 7, `grille` = 2,
`echelon` = 22, `prime_rate` = 2, `source` = 8, `corps` = 2.

### Résolution des FK et référentiels

Les FK `source` et `body` (Corps) sont résolues par **code** via des caches
code → entité (une seule entité par code dans la transaction) :

- une source citée par une colonne `source` est créée/mise à jour depuis
  `data/sources.json` ; un slug référencé mais **absent** du référentiel → refus
  (`UnknownSourceException`), même si une ligne `Source` de même code existait déjà en base
  (le référentiel fait foi) ;
- un corps cité par `body_code` est résolu depuis `data/corps.json` ; code absent → refus
  (`UnknownCorpsException`).

Ces référentiels sont des fichiers **curatés** (pas de CSV ni de schéma Frictionless) : les
colonnes `Source`/`Corps` étant toutes NOT NULL, le référentiel doit tout fournir
(décision Q3).

### Idempotence (réimport du même commit)

Réimporter le même commit laisse la base dans le même état (décision Q5, C6). En tête de
transaction, `purgeExistingCommit()` supprime en DQL les entités **scopées** au
`ParameterSet` du commit (Echelon d'abord — FK Grille —, puis Grille / DatedParameter /
PrimeRate, enfin le `ParameterSet`), puis `em->clear()` ; le set est ensuite recréé et
rechargé. Les référentiels **globaux** `Source`/`Corps` (pas de FK vers `ParameterSet`)
ne sont pas purgés mais mis à jour par **upsert sur `code`** (`findOneBy`). Aucune nouvelle
contrainte DB d'unicité n'est introduite (décision Q7) : l'atomicité vient de la
transaction, l'unicité des données de l'invariant amont.

### Câblage et configuration

L'`Importer` et ses collaborateurs sont des services autowirés (`config/services.yaml`,
bloc `App\`). La commande reçoit par `bind` le paramètre `string $defaultDataDir` =
`%env(BUDGET_DATA_DIR)%` (défaut `/data`). Aucune migration n'est ajoutée par #11 (le
schéma #10 suffit).

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
