# Company_SupplierSync

A Magento 2 module that keeps a supplier stock and cost ledger alongside Magento's own inventory:

- **Intake.** Suppliers send stock and cost snapshots over REST. Each request is validated synchronously, de-duplicated by idempotency key, and stored together with an outbox row in one transaction.
- **Apply.** A queue consumer applies snapshots asynchronously, in server acceptance order, with retries, leases and a recovery sweep.
- **Reserve.** Order placement takes a supplier hold per order item before payment. Two fences stop a released hold from ever becoming an order. Cancellations, shipments and refunds settle holds idempotently.

Correctness under concurrency, retries and failures is the point of the design. The rules are in [docs/business-rules.md](docs/business-rules.md); how they are enforced is in [docs/architecture.md](docs/architecture.md).

## What is guaranteed

| Property | How |
| --- | --- |
| No oversell from placement | Inventory rows are locked in ascending id order and checked under `FOR UPDATE`. Proof: 20 processes reserving 1 unit each against 10 units always yield exactly 10 holds |
| No double release or double fulfilment | Releases and fulfilments are deltas against persisted counters and order-item quantities. Proof: 20 concurrent reconciles of one cancellation write one release |
| A released hold never becomes an order | A pre-payment fence and a pre-commit fence inside the order transaction (invariants I1–I5) |
| Duplicate deliveries never re-apply or burn retries | Exclusive claim per update; 20 concurrent deliveries apply once with `attempts = 1` |
| Older snapshots never overwrite newer ones | Applied only if `update_id > last_update_id` |
| Identical retries are safe; conflicting reuse is refused | `UNIQUE(supplier_id, idempotency_key)` plus a SHA-256 of the normalized payload |

Payment correctness is **not** in scope: a gateway that captures and then times out leaves a payment to reconcile with the gateway's own tooling (see [business rules §8](docs/business-rules.md#8-placement-failures-and-uncertain-payment)).

## Tested environment

| Component | Version |
| --- | --- |
| Platform | Mage-OS 3.0.0 (based on Magento Open Source 2.4.9) |
| PHP | 8.5.6 (`bcmath` required) |
| Database | MariaDB 11.4.13 |
| Search | OpenSearch 3.6.0 |
| Message broker | RabbitMQ 4.2.6 (Erlang/OTP 27.3) |
| Composer | 2.9.8 |

The Composer constraints target the `magento/*` packages (Mage-OS replaces them), so the module also installs on Magento Open Source 2.4.7+. Only the environment above was tested. MySQL 8 should behave the same (`FOR UPDATE SKIP LOCKED` needs MySQL 8.0 or MariaDB 10.6).

## Installation

### As a Composer package

```bash
composer config repositories.supplier-sync vcs https://github.com/MahmoudEmadMohamed/magento2-supplier-sync
composer require company/module-supplier-sync:dev-main
bin/magento module:enable Company_SupplierSync
bin/magento setup:upgrade          # do NOT pass --keep-generated (see below)
bin/magento cache:flush
```

### In `app/code`

```bash
git clone https://github.com/MahmoudEmadMohamed/magento2-supplier-sync app/code/Company/SupplierSync
bin/magento module:enable Company_SupplierSync
bin/magento setup:upgrade
bin/magento cache:flush
```

`setup:upgrade` must regenerate generated code. Mage-OS compiles plugin chains into the generated interceptors, so with `--keep-generated` the order placement plugin is silently skipped. In production mode run `setup:di:compile` as usual.

## Configuration

### 1. Suppliers and assignments (CLI)

```bash
bin/magento supplier-sync:supplier:create acme "ACME Supplies" --website=base
bin/magento supplier-sync:assignment:set SKU-123 base acme
bin/magento supplier-sync:supplier:list
bin/magento supplier-sync:assignment:list
```

A SKU is supplier-backed on a website only when it has an assignment there. Unassigned SKUs keep normal Magento behaviour. Only simple products (including configurable children) can be assigned.

Supplier codes may contain letters, digits, `_` and `-` (for example `SUPPLIER_001`). They are unique ignoring case: `ACME` and `acme` are the same supplier, in the CLI, in lookups and in the idempotency hash.

### 2. MSI must still allow the sale

The supplier ledger is an extra restriction on top of MSI and never raises salable quantity. For supplier-backed SKUs, give MSI enough stock to sell: a dedicated drop-ship source with a high quantity, or "Manage Stock = No". If MSI says 0, MSI blocks first and the supplier ledger is never consulted.

The storefront shows MSI salability only, so a product can look in stock and fail at "Place Order" with *The requested quantity for "SKU" is not available from the supplier.*

### 3. API access (integration token)

1. Create an integration in **System › Extensions › Integrations**. Under API, grant only the resources it needs: `Supplier Sync › Submit stock updates`, `Read stock update status`, `Read supplier inventory`.
2. Activate it and copy the access token.
3. Since Magento 2.4.4, integration tokens are not accepted as bearer tokens by default. Either sign requests with OAuth 1.0a, or enable bearer use:

```bash
bin/magento config:set oauth/consumer/enable_integration_as_bearer 1
bin/magento cache:flush
```

### 4. Consumer and cron

```bash
bin/magento queue:consumers:start supplierSyncUpdateApply     # long-running; supervise it
```

Magento cron must run (`bin/magento cron:run` every minute). The module adds two jobs:

| Job | Schedule | Purpose |
| --- | --- | --- |
| `supplier_sync_outbox_dispatch` | every minute | Recover expired claims and lost messages, publish due outbox rows |
| `supplier_sync_reconcile` | every 5 minutes | Link saved orders, recover expired placement attempts, re-reconcile open reservations (200 orders per run, continuing from a cursor so every open order is revisited) |

The queue uses Magento's default connection: AMQP when configured, otherwise the database queue. Only RabbitMQ was tested.

### 5. Settings

| Path | Default | Meaning |
| --- | --- | --- |
| `supplier_sync/snapshot/max_lag_seconds` | 300 | Shipments this recent stay pending even for a later snapshot ([business rules §3](docs/business-rules.md#3-fulfilment-and-the-snapshot-watermark)) |
| `supplier_sync/queue/lease_seconds` | 120 | How long a processing claim is protected before the sweep may recover it |
| `supplier_sync/queue/max_attempts` | 5 | Claims before an update is marked `failed` |
| `supplier_sync/placement/lease_seconds` | 900 | How long a placement attempt is protected before recovery may act on it |

Asynchronous order placement (`checkout/async`) is not supported; keep it disabled.

## API

| Method | Path | ACL resource |
| --- | --- | --- |
| `POST` | `/rest/V1/supplier-sync/updates` | `Company_SupplierSync::updates_write` |
| `GET` | `/rest/V1/supplier-sync/updates/:updateId` | `Company_SupplierSync::updates_read` |
| `GET` | `/rest/V1/supplier-sync/inventory?sku=…&websiteCode=…` | `Company_SupplierSync::inventory_read` |

```bash
curl -s -X POST https://magento.test/rest/V1/supplier-sync/updates \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"supplier_code":"acme","idempotency_key":"acme-2026-10-15-0001","sku":"SKU-123",
       "quantity":"25","unit_price":"49.99","currency":"USD","website_code":"base"}'
# {"update_id":1,"status":"queued","replayed":false}
```

The response is synchronous validation only: `200` means *accepted and queued*. Poll `GET /V1/supplier-sync/updates/:id` for the outcome (`applied`, `skipped_stale`, `rejected`, `failed`). Send decimals as JSON strings: JSON numbers pass through PHP floats before the module sees them.

Errors keep Magento's format and add a machine-readable `error_code`:

```json
{"message": "Currency %currency does not match the website base currency %base.",
 "parameters": {"error_code": "currency_mismatch", "currency": "EUR", "base": "USD"}}
```

Every request, response and error code is in [docs/api-examples.md](docs/api-examples.md).

## Operations

| Command | Use |
| --- | --- |
| `supplier-sync:update:list [--status=failed] [--sku=]` | Recent updates |
| `supplier-sync:update:inspect <id>` | One update, its outbox row and its audit trail |
| `supplier-sync:update:retry <id> [--force]` | Requeue a `failed` update (or, with `--force`, a `rejected` one after fixing the cause) with a fresh attempt budget |
| `supplier-sync:outbox:dispatch` | Run the sweep and dispatcher once (what the minute cron does) |
| `supplier-sync:inventory:show <sku> [--website=]` | Quantity, reserved, fulfilled pending, available, shortage, cost |
| `supplier-sync:attempt:list [--detached]` | Placement attempts; `--detached` lists the ones waiting for an operator |
| `supplier-sync:attempt:inspect <id>` | An attempt and its reservations |
| `supplier-sync:attempt:resolve <id> --release` | Release the hold of a detached attempt, after checking the payment outcome in the gateway |
| `supplier-sync:reservation:reconcile [--order=]` | Run reconciliation once (what the 5-minute cron does) |

All module logging goes to `var/log/supplier_sync.log`, with ids, codes and statuses only.

### Troubleshooting

| Symptom | Likely cause | Action |
| --- | --- | --- |
| Updates stay `queued` | Consumer not running, or the broker is unreachable | Start the consumer. If messages were lost, the minute cron republishes them after 10 minutes, or run `outbox:dispatch` |
| Update `retry_pending` | A transient database error (deadlock, lock wait, connection) | Nothing: it is retried at `next_attempt_at` (1m, 5m, 15m, 1h) |
| Update `failed` | Five failed attempts, or a permanent processing error | `update:inspect <id>` for the error code, fix the cause, `update:retry <id>` |
| Update `rejected` | The SKU was deleted or reassigned after acceptance | Send a corrected update, or fix the cause and `update:retry <id> --force` |
| Checkout says supplier stock is not available | `available` is lower than the order quantity, the supplier or website is disabled, or no snapshot was applied yet | `inventory:show <sku>` |
| `inventory:show` reports a shortage | A snapshot came in below open commitments | New holds are blocked until the next snapshot; existing ones are kept |
| `attempt:list --detached` is not empty | A worker died while payment may have been in flight; the hold is kept | Check the payment in the gateway, then `attempt:resolve <id> --release` if no order exists |
| Supplier holds never taken at checkout | The plugin is missing from the generated interceptor | Run `setup:upgrade` without `--keep-generated` (or `setup:di:compile`) |
| Scenario harness exits with "Compiled DI detected" | `setup:di:compile` was run; the harnesses need runtime DI to inject their test doubles | See [Tests](#tests) |

## Tests

| Suite | What it proves | Run |
| --- | --- | --- |
| Unit (155 tests) | Decimal normalization and math, payload normalization and hashing, failure classification, the full state-transition matrices, reservation status derivation, that `AttemptResolver` locks the attempt before any change, the uninstaller | `make unit` |
| Queue scenarios (53 checks) | Ordering, duplicate and early deliveries, crash after claim, takeover, retry eligibility, lock ownership, poison messages, CLI retry, rejections after acceptance, lost messages, a broker outage at intake followed by outbox recovery through RabbitMQ and the real consumer process; real-process concurrency for first applies, deliveries and identical submissions | `php Test/Scenario/queue-scenarios.php <magento_root>` |
| Placement scenarios (78 checks) | Real orders through every supported checkout service (guest and logged-in payment-information placement as used by REST and the storefront, cart `placeOrder`, the GraphQL `PlaceOrder` model, admin `QuoteManagement::submit`): success, insufficient stock, configurables, declined payment and retry, failures before and after commit, skipped fence, nested transactions, double submit, recovery by attempt state (with a slow payment in a separate process), the reconciliation cursor reaching an order behind a full batch, shipments, cancellations, refunds, snapshot examples, lag window, shortage, reassignment, disabled suppliers, and fulfil events racing in eight processes while a ninth checks they become visible in id order | `php Test/Scenario/placement-scenarios.php <magento_root>` |
| REST scenarios (45 checks) | The real REST controller: token auth, ACL, receipts, replay (including upper-case supplier codes), conflict, every intake error code, disabled supplier, per-website ledgers on a second website, supplier code rules, error shapes in production and developer mode, status and inventory reads, decimal quantities, safe logging | `php Test/Scenario/rest-scenarios.php <magento_root>` |
| Concurrency proof | 20 processes reserve 1 unit each against 10 units: exactly 10 succeed | `make burst` |

`make scenarios` runs all of them and reports every failing suite (about two minutes). `make test` runs phpcs (Magento2 standard), phpstan (level 6) and the unit tests; CI runs the same on every push.

The scenario harnesses need a Magento install in **developer mode with runtime DI**. They swap in test doubles (a failing payment method, a failing order save, a publisher that can simulate a broker outage, an adjustable lag window) through ObjectManager preferences inside their own processes, never through the module's DI configuration. After `setup:di:compile`, constructor arguments come from `generated/metadata` and those preferences no longer take effect, so the harnesses refuse to run and say so. Remove `generated/metadata` (or run `bin/magento setup:upgrade`, which clears generated code), run the scenarios, then compile again if you need to. Each harness also checks that its doubles reached the objects that use them before running any scenario.

They run against the configured database because separate processes must see committed data: Magento's integration-test framework wraps each test in a transaction, and the concurrency proofs need several processes. Fixtures use dedicated SKUs, suppliers (`scenario-*`) and a temporary second website (`ss_second`), and are deleted afterwards. Orders and quotes they create stay, like any test order.

## Removal

1. Stop taking supplier-backed orders and stop the consumer. Let `supplier_sync.update.apply` drain, or purge it.
2. Run `bin/magento supplier-sync:reservation:reconcile`, then export the `company_supplier*` tables if you need an audit trail. **Open supplier holds are discarded with the tables.** MSI reservations and orders are unaffected; settle any supplier-side commitments outside Magento.
3. Remove the module:
   - Composer installs: `bin/magento module:uninstall Company_SupplierSync --remove-data`, then `composer remove company/module-supplier-sync`.
   - `app/code` installs: `bin/magento module:disable Company_SupplierSync && bin/magento setup:upgrade`, then delete the directory.

   **Disabling the module and running `setup:upgrade` drops all ten module tables** (declarative schema, verified). Do not disable it temporarily on a live store.
4. Delete the integration and the `supplier_sync.update.apply` queue and binding in RabbitMQ. With `module:uninstall --remove-data`, the module's `Uninstall` class removes its `core_config_data` rows (`supplier_sync/*`) and its reconciliation cursor; after a plain disable, delete those rows yourself.
5. `bin/magento setup:di:compile` (production mode) and `bin/magento cache:flush`.

## Limitations

**Not covered by automated tests**, and why:

- **HTTP transports of checkout.** Checkout is exercised through the exact service contracts that the storefront controllers, REST checkout endpoints, the GraphQL resolver and admin order create call. Magento's HTTP layers on top of them (storefront controllers, the GraphQL HTTP endpoint, REST checkout routes) were not exercised, because the test environment could not reach the web server. None of those layers opens a transaction or changes the order object (verified in the P2 spike), and the module's own REST endpoints are tested through Magento's real REST controller.
- **Third-party and redirect payment methods** (PayPal, hosted card fields, 3-D Secure). Only check/money order and a failing test double were used. Methods that create orders outside `OrderManagementInterface::place` are unsupported by design.
- **Multi-address checkout** (one cart, several orders) was not tested.
- **A clean Composer install and uninstall on a separate Magento instance** has not been run yet; installation was verified only in `app/code` on the development instance.


- Asynchronous order placement, bundle and grouped products, and orders created without `OrderManagementInterface::place` (imports, custom creation) are not supported. No supplier hold is taken for them.
- A placement attempt that dies while payment may be in flight keeps its hold and is detached so the customer can retry. Two orders for one cart are then possible; core Magento has the same exposure.
- A snapshot generated long before it is accepted, and that misses a recent shipment, over-credits stock. The payload carries no supplier timestamp or revision ([business rules §3](docs/business-rules.md#3-fulfilment-and-the-snapshot-watermark)).
- A partial refund of unshipped units on an order that stays open keeps the hold until the order closes (under-sells, never oversells).
- A valid token without the required ACL resource gets Magento's standard `401` ("The consumer isn't authorized to access …"), not `403`.

Extensions (multi-supplier routing, supplier confirmations, a supplier revision field, writing cost to the product `cost` attribute) are described in [docs/architecture.md](docs/architecture.md#extensions).
