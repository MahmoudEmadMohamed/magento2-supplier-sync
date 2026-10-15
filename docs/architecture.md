# Architecture

This document explains how `Company_SupplierSync` keeps supplier inventory correct under concurrency, retries and failures. The rules it enforces are in [business-rules.md](business-rules.md).

## Overview

Three flows share one ledger, `company_supplier_inventory`:

1. **Intake (synchronous REST).** Validate, normalize and hash the payload. Insert the update and an outbox row in one transaction. Return a receipt.
2. **Apply (asynchronous queue).** A consumer claims the update exclusively and applies the snapshot under the inventory row lock.
3. **Order placement and lifecycle.** A plugin on `OrderManagementInterface::place` reserves stock before payment; two fences stop a released placement from committing an order. One observer reconciles cancellations, shipments and order closure.

## Spike findings (P2)

Verified on Mage-OS 3.0.0 (Magento 2.4.9), MariaDB 11.4.13. A throwaway probe module (a plugin on `OrderManagementInterface::place` plus logging observers) was run on four placement paths. Each path called the same service contracts its transport uses, in-process:

| Path | Service called |
| --- | --- |
| REST guest cart | `GuestPaymentInformationManagementInterface::savePaymentInformationAndPlaceOrder` (area `webapi_rest`) |
| Storefront checkout | the same, area `frontend` |
| GraphQL `placeOrder` | `Magento\QuoteGraphQl\Model\Cart\PlaceOrder::execute` (area `graphql`) |
| Admin order create | `QuoteManagement::submit` (what `AdminOrder\Create::createOrder` calls, area `adminhtml`) |

Each cart held one simple line and one configurable line.

**Result: go.** The fence design holds on every path.

| Question | Finding | Consequence |
| --- | --- | --- |
| Where does payment run relative to order save? | `OrderService::place()` calls `Order::place()`, which dispatches `sales_order_place_before`, then calls `Payment::place()`. `orderRepository->save()` runs afterwards | The pre-payment fence on `sales_order_place_before` runs before any payment code |
| Transaction level at `place()` entry | 0 on all four paths | Nested-transaction rejection never triggers on supported paths |
| Same order object everywhere (I4) | The object passed to `place()` is the one seen in `sales_order_place_before`, `sales_order_payment_place_start` and `sales_order_save_after`, and is returned by `proceed()` | Stamping `attempt_id` on the order object binds the attempt to that exact order |
| Same connection (I1) | The order resource's connection is the same object as `ResourceConnection::getConnection()` | Module writes inside the fence join the order transaction |
| `sales_order_save_after` inside the transaction | Yes, transaction level 1 (2 or more when called from `ShipOrder` or `DB\Transaction`) | The pre-commit fence commits or rolls back together with the order |
| Fence rejection rolls back (I5) | Throwing in `sales_order_save_after`: no `sales_order` row. Throwing in `sales_order_place_before`: `sales_order_payment_place_start` never fires | A released attempt can neither start payment nor commit an order |
| Are order items persisted when `sales_order_save_after` fires? | **No.** `VersionControl\AbstractDb::processAfterSaves()` calls `afterSave()` (the event) **before** `processRelations()` (items, addresses, payment) | Reservations are linked to order items after `proceed()` returns, not in the fence |
| Exception messages | A `LocalizedException` thrown by the module reaches the caller with its message (wrapped in `CouldNotSaveException` by checkout services) | Customer-safe messages work |
| Concurrent submits of one cart | `QuoteManagement::placeOrder` wraps placement in `CartMutex` | Core already serializes one cart on that path. The attempt check still covers admin create and direct `place()` callers |
| MSI place plugin | `AppendReservationsAfterOrderPlacementPlugin` (around `place`, no `sortOrder`); reserves before `proceed()` only in deferred mode | Independent of ours: if MSI fails inside our `proceed()`, our hold is released |
| Async order placement | `Magento_AsyncOrder` is not installed; `checkout/async` is 0 | The module only logs a warning if `checkout/async` is enabled |

### Configurable products

| Field | Parent order item | Child order item |
| --- | --- | --- |
| `qty_ordered` | 3 | 3 (the child *quote* item has qty 1, relative to the parent) |
| `qty_invoiced`, `qty_refunded` | set | set |
| `qty_shipped`, `qty_canceled` | **set** | **stays 0** |

Consequence: the reservation is keyed on the child (the simple product's SKU, quote item and order item), but every quantity is read from the parent order item.

### Order lifecycle events

| Operation | `sales_order_save_commit_after` | `sales_order_item_save_commit_after` (persisted qty) |
| --- | --- | --- |
| First partial shipment (REST `ShipOrder`), state `new → processing` | fires | fires for each changed item |
| **Second partial shipment** (REST `ShipOrder` and admin `DB\Transaction`), state unchanged | **does not fire** | fires |
| Cancel | fires | fires |
| Invoice | fires | fires |
| Offline credit memo, state `→ closed` | fires | fires |

The order-level event is missed because `VersionControl\AbstractDb::save()` takes `processNotModifiedSave()` when the order row itself is unchanged. That path saves relations but registers no commit callback.

**Decision:** the single lifecycle observer listens to `sales_order_item_save_commit_after`. It fires after commit, for every item whose `qty_shipped`, `qty_canceled`, `qty_refunded` or `qty_invoiced` changed. In the same transaction the order state is also persisted, so reconcile reads both from the database. This replaces the plan's `sales_order_save_commit_after` plus shipment and credit-memo fallbacks with one hook.

**Commit-callback exceptions are swallowed.** `AbstractResource::commit()` runs callbacks in `try/catch` and logs `critical`. A reconcile failure therefore can't break a shipment, but it would be silently missed. The reconciliation cron is the recovery path, not just a nicety.

### Platform checks

| Check | Result |
| --- | --- |
| Nullable `UNIQUE` allows multiple NULLs | Yes (MariaDB 11.4) |
| `SELECT … FOR UPDATE SKIP LOCKED` | Supported (MariaDB ≥ 10.6) |
| Default isolation, lock wait | `REPEATABLE-READ`, `innodb_lock_wait_timeout = 50` |
| `oauth/consumer/enable_integration_as_bearer` | No default in `config.xml`, so off; the README shows how to enable it |
| Custom `WebapiException` with HTTP 409 | Passed through unchanged by `ErrorProcessor::maskException()`; `details` render as `parameters`; `trace` is added only in developer mode |
| Webapi input for `string` parameters | JSON numbers are converted to strings (`49.99 → "49.99"`), and `true` becomes `"1"`. Validation runs on the string form; suppliers should send decimals as strings to avoid float parsing in `json_decode` |

## Transaction boundaries and lock order

_To be filled in during P4._

## Hooks

_To be filled in during P7._

## Test map

_To be filled in during P8._
