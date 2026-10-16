# Run from the module directory. MAGENTO_ROOT points at the Magento install that loads this module.
MAGENTO_ROOT ?= ../../../..
PHP ?= php
MAGENTO = $(PHP) $(MAGENTO_ROOT)/bin/magento
BIN = $(MAGENTO_ROOT)/vendor/bin

.PHONY: install phpcs phpstan unit test consumer dispatch burst scenarios

install: ## Enable the module, apply the schema, regenerate interceptors (do not use --keep-generated)
	$(MAGENTO) module:enable Company_SupplierSync
	$(MAGENTO) setup:upgrade
	$(MAGENTO) cache:flush

phpcs:
	$(BIN)/phpcs --standard=phpcs.xml.dist

phpstan:
	$(BIN)/phpstan analyse -c phpstan.neon.dist --autoload-file=$(MAGENTO_ROOT)/vendor/autoload.php --memory-limit=1G

unit: ## Run from dev/tests/unit so core's Allure extension finds its config
	cd $(MAGENTO_ROOT)/dev/tests/unit && $(abspath $(BIN))/phpunit -c phpunit.xml.dist $(CURDIR)/Test/Unit

test: phpcs phpstan unit

consumer: ## Start the stock update consumer
	$(MAGENTO) queue:consumers:start supplierSyncUpdateApply

dispatch: ## Publish due outbox rows and run the sweep once
	$(MAGENTO) supplier-sync:outbox:dispatch

scenarios: ## All scenario harnesses + burst; needs developer mode with runtime DI (no generated/metadata)
	./scripts/run-scenarios.sh $(MAGENTO_ROOT)

burst: ## 20 concurrent one-unit reservations (developer mode only)
	./scripts/reserve-burst.sh $(MAGENTO_ROOT)
