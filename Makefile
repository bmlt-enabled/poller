COMMIT := $(shell git rev-parse --short=8 HEAD)
PLUGIN := poller
BUILD_DIR := build
ZIP_FILENAME := $(or $(ZIP_FILENAME),$(PLUGIN).zip)
ZIP_FILE := $(BUILD_DIR)/$(ZIP_FILENAME)

.PHONY: help
help: ## Print the help documentation
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

.PHONY: test
test: ## Run the checks that do not need WordPress
	php tests/run.php
	php bin/preview.php

.PHONY: build
build: $(ZIP_FILE) ## Build an installable plugin zip from the current commit

$(ZIP_FILE):
	mkdir -p $(BUILD_DIR)
	git archive --format=zip --prefix=$(PLUGIN)/ --output=$(ZIP_FILE) $(COMMIT)

.PHONY: clean
clean: ## Remove build output
	rm -rf $(BUILD_DIR)
