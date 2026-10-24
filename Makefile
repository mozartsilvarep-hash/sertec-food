# SerTec Food — atalhos de desenvolvimento (docker compose)
# Primeira vez: make setup    |   Dia a dia: make up / make test

DC  := docker compose
PHP := $(DC) exec php
ART := $(PHP) php artisan

.PHONY: help setup env up down restart logs sh migrate fresh seed rollback-check test test-back test-front lint lint-back lint-front ci

help:
	@grep -E '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

env: ## Cria .env (raiz e backend) com senhas aleatórias, se ainda não existirem
	@if [ ! -f .env ]; then \
		cp .env.example .env; \
		sed -i.bak "s/^UID=.*/UID=$$(id -u)/; s/^GID=.*/GID=$$(id -g)/" .env; \
		sed -i.bak "s/^DB_PASSWORD=.*/DB_PASSWORD=$$(openssl rand -hex 16)/" .env; \
		sed -i.bak "s/^DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=$$(openssl rand -hex 16)/" .env; \
		rm -f .env.bak; echo "✔ .env criado"; fi
	@if [ ! -f backend/.env ]; then \
		cp backend/.env.example backend/.env; \
		sed -i.bak "s/^DB_PASSWORD=.*/DB_PASSWORD=$$(grep '^DB_PASSWORD=' .env | cut -d= -f2)/" backend/.env; \
		rm -f backend/.env.bak; echo "✔ backend/.env criado"; fi

setup: env ## Sobe tudo do zero: build, dependências, chave, migrations e dados de demonstração
	$(DC) up -d --build
	$(PHP) composer install
	$(ART) key:generate
	$(ART) migrate --seed
	@echo ""
	@echo "Pronto. Acesse:"
	@echo "  Painel SerTec ........ http://admin.cardapios.localhost"
	@echo "  Cardápio demo ........ http://pizzaria-demo.cardapios.localhost"
	@echo "  Painel restaurante ... http://pizzaria-demo.cardapios.localhost/painel"
	@echo "As senhas de demonstração foram exibidas acima (uma única vez)."

up: ## Sobe os containers
	$(DC) up -d

down: ## Para os containers
	$(DC) down

restart: down up ## Reinicia

logs: ## Acompanha logs
	$(DC) logs -f --tail=100

sh: ## Shell no container PHP
	$(PHP) bash

migrate: ## Roda migrations pendentes
	$(ART) migrate

fresh: ## Recria o banco com dados de demonstração (APAGA dados locais)
	$(ART) migrate:fresh --seed

rollback-check: ## Testa migrate -> rollback completo -> migrate no banco de TESTE (DoD)
	$(DC) exec -e DB_DATABASE=sertec_food_test php php artisan migrate:fresh --force
	$(DC) exec -e DB_DATABASE=sertec_food_test php php artisan migrate:rollback --force
	$(DC) exec -e DB_DATABASE=sertec_food_test php php artisan migrate --force

test: test-back test-front ## Roda todas as suítes

test-back: ## Pest (inclui testes de isolamento multi-tenant)
	$(PHP) vendor/bin/pest

test-front: ## Vitest
	$(DC) exec node pnpm test

lint: lint-back lint-front ## Lint e análise estática

lint-back:
	$(PHP) vendor/bin/pint --test
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

lint-front:
	$(DC) exec node pnpm lint
	$(DC) exec node pnpm typecheck

ci: lint rollback-check test ## O mesmo que o GitHub Actions roda
