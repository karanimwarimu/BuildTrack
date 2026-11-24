# BuildTrack shortcuts. Everything here is a thin wrapper around ./start.sh and bin/console.
PHP ?= php
.DEFAULT_GOAL := help

help: ## Show this help
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  make %-12s %s\n", $$1, $$2}'

up: ## Start the app (creates .env + database on first run)
	./start.sh

demo: ## Start with demo data loaded (empty database only)
	./start.sh --seed

daemon: ## Start in the background
	./start.sh --daemon

stop: ## Stop the background server
	./start.sh --stop

status: ## Is the background server running?
	./start.sh --status

migrate: ## Create/upgrade the SQLite schema
	$(PHP) bin/console migrate

seed: ## Load demo data
	$(PHP) bin/console seed

reset-demo: ## Back up, wipe and re-seed the database with demo data
	$(PHP) bin/console seed --fresh

admin: ## Promote an account: make admin EMAIL=you@example.com
	@test -n "$(EMAIL)" || (echo "Usage: make admin EMAIL=you@example.com" && exit 1)
	$(PHP) bin/console make-admin $(EMAIL)

doctor: ## Health check
	$(PHP) bin/console doctor

backup: ## Snapshot the database to data/backups (keeps newest 14)
	$(PHP) bin/console backup --keep=14

logs: ## Follow app, mail and server logs
	@mkdir -p data/logs && touch data/logs/app.log data/logs/mail.log data/logs/server.log
	tail -n 50 -f data/logs/app.log data/logs/mail.log data/logs/server.log

test: ## Run the automated test suite
	$(PHP) tests/run.php

lint: ## Syntax-check every PHP file
	@find . -name '*.php' -not -path './data/*' -not -path './tests/tmp/*' -print0 | xargs -0 -n1 $(PHP) -l | grep -v '^No syntax errors' || true
	@$(PHP) -l bin/console | grep -v '^No syntax errors' || true
	@echo "Lint finished."

check: lint test ## Lint + tests

.PHONY: help up demo daemon stop status migrate seed reset-demo admin doctor backup logs test lint check
