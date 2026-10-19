COMPOSE = docker compose

.PHONY: up down build qa qa-python qa-symfony import

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down -v

build:
	$(COMPOSE) build

qa: qa-python qa-symfony

qa-python:
	@echo "TODO: chaîne qualité Python (pytest, mutmut, mypy, ruff) — voir issue #00"

qa-symfony:
	@echo "TODO: chaîne qualité PHP (phpunit, infection, phpstan, php-cs-fixer, deptrac) — voir issue #00"

import:
	@echo "TODO: commande d'import Symfony d'un commit du dépôt de données — voir issue #11"
