.DEFAULT_GOAL := help
.PHONY: help up down install test lint lint-fix analyse kphp-check
# =============================================================================
# lphenom/realtime — Makefile
# All commands run inside Docker containers — no host PHP/Composer required.
# =============================================================================
COMPOSE        = docker-compose
COMPOSE_EXEC   = $(COMPOSE) exec app
DOCKER_BUILD   = DOCKER_BUILDKIT=1 COMPOSE_DOCKER_CLI_BUILD=1
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
	  awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-15s\033[0m %s\n", $$1, $$2}'
up: ## Build and start all services (app, db, redis), then run composer install
	$(DOCKER_BUILD) $(COMPOSE) up -d --build
	$(COMPOSE_EXEC) composer install --no-interaction --prefer-dist --optimize-autoloader
down: ## Stop and remove containers, networks, and volumes
	$(COMPOSE) down --remove-orphans
install: ## Run composer install inside the app container
	$(COMPOSE_EXEC) composer install --no-interaction --prefer-dist --optimize-autoloader
test: ## Run PHPUnit tests inside the app container
	$(COMPOSE_EXEC) vendor/bin/phpunit --colors=always
lint: ## Run PHP-CS-Fixer (dry-run) inside the app container
	$(COMPOSE_EXEC) vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
lint-fix: ## Run PHP-CS-Fixer (fix) inside the app container
	$(COMPOSE_EXEC) vendor/bin/php-cs-fixer fix --allow-risky=yes
analyse: ## Run PHPStan inside the app container
	$(COMPOSE_EXEC) vendor/bin/phpstan analyse --memory-limit=256M
kphp-check: ## Build KPHP binary + PHAR (requires SSH agent forwarded)
	DOCKER_BUILDKIT=1 docker build --ssh default -f Dockerfile.check -t lphenom-realtime-check .
check: lint analyse test ## Run full CI check (lint + analyse + test) inside Docker
