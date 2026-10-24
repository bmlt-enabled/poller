COMMIT := $(shell git rev-parse --short=8 HEAD)
PLUGIN := poller
BUILD_DIR := build
ZIP_FILENAME := $(or $(ZIP_FILENAME),$(PLUGIN).zip)
ZIP_FILE := $(BUILD_DIR)/$(ZIP_FILENAME)
VENDOR_AUTOLOAD := vendor/autoload.php

ifeq ($(PROD)x, x)
	COMPOSER_ARGS := --prefer-dist --no-progress
else
	COMPOSER_ARGS := --no-dev
endif

.PHONY: help
help: ## Print the help documentation
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

$(VENDOR_AUTOLOAD):
	composer install $(COMPOSER_ARGS)

.PHONY: composer
composer: $(VENDOR_AUTOLOAD) ## Runs composer install

.PHONY: lint
lint: composer ## PHP Lint
	vendor/squizlabs/php_codesniffer/bin/phpcs

.PHONY: fmt
fmt: composer ## PHP Fmt
	vendor/squizlabs/php_codesniffer/bin/phpcbf

.PHONY: dev
dev: ## Docker up
	docker compose up

.PHONY: mysql
mysql: ## Runs mysql cli in mysql container
	docker exec -it $(PLUGIN)-db-1 mariadb -u root -psomewordpress wordpress

.PHONY: bash
bash: ## Runs bash shell in wordpress container
	docker exec -it -w /var/www/html $(PLUGIN)-wordpress-1 bash

.PHONY: test
test: ## Run tests in Docker (builds fresh each time)
	docker compose -f docker-compose.test.yml up --build --abort-on-container-exit --exit-code-from test
	docker compose -f docker-compose.test.yml down

.PHONY: test-clean
test-clean: ## Remove test containers and images
	docker compose -f docker-compose.test.yml down --rmi local --volumes

.PHONY: build
build: $(ZIP_FILE) ## Build an installable plugin zip from the current commit

$(ZIP_FILE):
	mkdir -p $(BUILD_DIR)
	git archive --format=zip --prefix=$(PLUGIN)/ --output=$(ZIP_FILE) $(COMMIT)

.PHONY: clean
clean: ## Remove build output
	rm -rf $(BUILD_DIR)
