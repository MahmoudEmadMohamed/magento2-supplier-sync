# Run from the module directory. MAGENTO_ROOT points at the Magento install that loads this module.
MAGENTO_ROOT ?= ../../../..
PHP ?= php
MAGENTO = $(PHP) $(MAGENTO_ROOT)/bin/magento
BIN = $(MAGENTO_ROOT)/vendor/bin

.PHONY: install phpcs phpstan unit integration test consumer dispatch burst

install: ## Enable the module and apply the schema
	$(MAGENTO) module:enable Company_SupplierSync
	$(MAGENTO) setup:upgrade --keep-generated
	$(MAGENTO) cache:flush

phpcs:
	$(BIN)/phpcs --standard=phpcs.xml.dist

phpstan:
	$(BIN)/phpstan analyse -c phpstan.neon.dist --autoload-file=$(MAGENTO_ROOT)/vendor/autoload.php --memory-limit=1G

unit: ## Run from dev/tests/unit so core's Allure extension finds its config
	cd $(MAGENTO_ROOT)/dev/tests/unit && $(abspath $(BIN))/phpunit -c phpunit.xml.dist $(CURDIR)/Test/Unit

integration: ## Needs dev/tests/integration/etc/install-config-mysql.php pointing at a dedicated test database
	cd $(MAGENTO_ROOT)/dev/tests/integration && $(abspath $(BIN))/phpunit -c phpunit.xml.dist $(CURDIR)/Test/Integration

test: phpcs phpstan unit

consumer: ## Start the stock update consumer
	$(MAGENTO) queue:consumers:start supplierSyncUpdateApply

dispatch: ## Publish due outbox rows and run the sweep once
	$(MAGENTO) supplier-sync:outbox:dispatch

burst: ## 20 concurrent one-unit reservations (developer mode only)
	./scripts/reserve-burst.sh
