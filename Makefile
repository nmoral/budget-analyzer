COMPOSE = docker compose
DATA_DIR = $(CURDIR)/data
CORE = src/contract/*,src/ingest/*,src/sources/*

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
	cd python && BUDGET_DATA_DIR="$(DATA_DIR)" uv run mutmut run
	cd python && uv run mutmut export-cicd-stats
	cd python && uv run python scripts/check_msi.py
	cd python && for schema in ../data/schemas/*.json; do \
		uv run frictionless validate --trusted --type schema "$$schema" || exit 1; \
	done

qa-symfony:
	@if [ -d symfony/vendor ]; then \
		echo "qa-symfony: chaîne qualité PHP (phpstan, php-cs-fixer, phpunit) — à activer avec #9"; \
	else \
		echo "qa-symfony: SKIP — symfony/vendor absent (brique PHP non encore livrée, cf. #9)"; \
	fi

import:
	@echo "TODO: commande d'import Symfony d'un commit du dépôt de données — voir issue #11"
