# tadmor-php developer tasks.
#
# Everything runs against the committed vendor/ tree: nothing is installed
# from Packagist, and no target touches the network except vendor-update.
PHP ?= php

# Connection strings. Override on the command line, e.g.
#   make run DATABASE_URL=postgres://user:pass@host:5432/db
DATABASE_URL ?= postgres://tadmor:tadmor@127.0.0.1:5432/tadmor_php?sslmode=disable
TEST_DATABASE_URL ?= postgres://tadmor:tadmor@127.0.0.1:5432/tadmor_php_test?sslmode=disable
CONFORMANCE_DATABASE_URL ?= postgres://tadmor:tadmor@127.0.0.1:5432/tadmor_php_conformance?sslmode=disable
HTTP_ADDR ?= 127.0.0.1:8080

.DEFAULT_GOAL := help
.PHONY: help run migrate adduser test conformance check vendor-check vendor-update db

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | \
		awk 'BEGIN{FS=":.*## "}{printf "  make %-14s %s\n", $$1, $$2}'

run: migrate ## Migrate, then run the development server on HTTP_ADDR
	DATABASE_URL='$(DATABASE_URL)' tools/serve.sh $(HTTP_ADDR)

migrate: ## Apply pending shared-schema migrations
	DATABASE_URL='$(DATABASE_URL)' $(PHP) artisan tadmor:migrate

adduser: ## Create or reset an administrator: make adduser EMAIL=... NAME=... (password on stdin)
	DATABASE_URL='$(DATABASE_URL)' $(PHP) artisan tadmor:adduser --email="$(EMAIL)" --name="$(NAME)"

test: ## Run the PHPUnit suite (wipes the _test database)
	TEST_DATABASE_URL='$(TEST_DATABASE_URL)' $(PHP) vendor/bin/phpunit $(ARGS)

conformance: ## Run tadmor's conformance suite against a fresh server (wipes the _conformance DB)
	DATABASE_URL='$(CONFORMANCE_DATABASE_URL)' tools/conformance.sh $(ARGS)

check: vendor-check ## Lint every PHP file and check the vendor tree
	@find app bootstrap config public routes tests tools -name '*.php' -print0 | \
		xargs -0 -n1 $(PHP) -l -d display_errors=stderr >/dev/null

vendor-check: ## Verify vendor/ and dependencies.json against composer.json and composer.lock (offline)
	$(PHP) tools/vendor.php check

vendor-update: ## Re-resolve dependencies under the 7-day cooldown and install them (network)
	$(PHP) tools/vendor.php update

db: ## Start a local Postgres 17 container (podman) for development
	podman run -d --name tadmor-php-pg -e POSTGRES_USER=tadmor -e POSTGRES_PASSWORD=tadmor \
		-e POSTGRES_DB=tadmor_php -p 127.0.0.1:5432:5432 docker.io/library/postgres:17
