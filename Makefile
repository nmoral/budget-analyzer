COMPOSE = docker compose
DATA_DIR = $(CURDIR)/data
CORE = src/contract/*,src/ingest/*,src/sources/*,src/checks/*

.PHONY: up down build qa qa-python qa-symfony import

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down -v

build:
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
	cd python && BUDGET_DATA_DIR="$(DATA_DIR)" uv run mutmut run
	cd python && uv run mutmut export-cicd-stats
	cd python && uv run python scripts/check_msi.py
	cd python && for schema in ../data/schemas/*.json; do \
		uv run frictionless validate --trusted --type schema "$$schema" || exit 1; \
	done

qa-symfony:
	$(COMPOSE) build symfony
	$(COMPOSE) up -d --wait postgres
	$(COMPOSE) run --rm symfony vendor/bin/php-cs-fixer fix --dry-run --diff
	$(COMPOSE) run --rm symfony vendor/bin/phpstan analyse
	$(COMPOSE) run --rm symfony vendor/bin/phpunit -c phpunit.dist.xml

import:
	@echo "TODO: commande d'import Symfony d'un commit du dépôt de données — voir issue #11"
