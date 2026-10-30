COMPOSE = docker compose
DATA_DIR = $(CURDIR)/data
CORE = src/contract/*,src/ingest/*,src/sources/*,src/checks/*

# UID/GID de l'hôte, transmis à docker compose (build args du service symfony) pour
# que les fichiers créés par le conteneur (var/, vendor/) appartiennent à l'utilisateur
# et non à root. Exportés -> visibles par toutes les invocations `docker compose`.
export UID ?= $(shell id -u)
export GID ?= $(shell id -g)

.PHONY: up down build qa qa-python qa-symfony import

# Point de montage du volume anonyme vendor/ pré-créé côté hôte et possédé par
# l'utilisateur : sinon le démon Docker (root) le crée lui-même en root:root.
# Cible « fichier » : jouée seulement si le dossier n'existe pas encore.
symfony/vendor:
	mkdir -p symfony/vendor

up: symfony/vendor
	$(COMPOSE) up -d

down:
	$(COMPOSE) down -v

build: symfony/vendor
	$(COMPOSE) build

qa: qa-python qa-symfony

qa-python:
	cd python && uv run ruff check .
	cd python && uv run ruff format --check .
	cd python && uv run mypy src
	cd python && uv run pytest --cov=src
	cd python && uv run coverage report --fail-under=90
	cd python && uv run coverage report --include="$(CORE)" --fail-under=100
	cd python && PYTHONPATH=src BUDGET_DATA_DIR="$(DATA_DIR)" uv run python -m checks
# mutmut complet par défaut (local, push main). MUTMUT_SKIP=1 neutralise ces lignes
# UNIQUEMENT en CI sur une PR, où le périmètre réduit est joué à part (scripts/mutmut_scoped.sh).
# Non posé en local : le comportement de `make qa-python` reste inchangé (mutmut full).
ifeq ($(strip $(MUTMUT_SKIP)),)
	cd python && BUDGET_DATA_DIR="$(DATA_DIR)" uv run mutmut run
	cd python && uv run mutmut export-cicd-stats
	cd python && uv run python scripts/check_msi.py
endif
	cd python && for schema in ../data/schemas/*.json; do \
		uv run frictionless validate --trusted --type schema "$$schema" || exit 1; \
	done

qa-symfony: symfony/vendor
	$(COMPOSE) build symfony
	$(COMPOSE) up -d --wait postgres
	$(COMPOSE) run --rm symfony vendor/bin/php-cs-fixer fix --dry-run --diff
	$(COMPOSE) run --rm symfony vendor/bin/phpstan analyse
	$(COMPOSE) run --rm symfony vendor/bin/deptrac analyse --no-progress
	$(COMPOSE) run --rm symfony vendor/bin/phpunit -c phpunit.dist.xml
	$(COMPOSE) run --rm symfony vendor/bin/infection --threads=max --no-progress

# Importe le dépôt de données vers Postgres (commande `app:import <commit>`, livrée #11).
# COMMIT : référence opaque étiquetée comme version_id (défaut = HEAD du dépôt courant).
COMMIT ?= $(shell git rev-parse HEAD)

import: symfony/vendor
	$(COMPOSE) up -d --wait postgres
	$(COMPOSE) run --rm symfony php bin/console app:import $(COMMIT)
