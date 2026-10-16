# Business rules

These rules are documented assumptions made for this assignment. The assignment brief does not state them. Every rule below is enforced in code and covered by a test; the test names are listed in [architecture.md](architecture.md#test-map).

Scope: products that have no supplier assignment keep normal Magento behaviour. A supplier-backed product is a simple product (or a configurable child) with an explicit supplier assignment for the website the order is placed on.

## 1. Inventory counters

The module keeps its own ledger in `company_supplier_inventory`, one row per supplier, SKU and website.

| Counter | Meaning |
| --- | --- |
| `quantity` | The last applied supplier snapshot |
| `reserved_qty` | Units held by open, unshipped reservations |
| `fulfilled_pending_qty` | Units shipped by Magento that a later snapshot has not yet reflected |

```
available = max(0, quantity - reserved_qty - fulfilled_pending_qty)
shortage  = max(0, reserved_qty + fulfilled_pending_qty - quantity)
```

- Checkout uses `available`.
- `shortage` is never hidden by the clamp. It appears in `GET /V1/supplier-sync/inventory`, in `supplier-sync:inventory:show`, and as a warning in the log whenever a snapshot creates or increases it.
- An assigned SKU that has never had a snapshot applied has no inventory row, so `available = 0` and placement fails. The first applied update creates the row.
- Reservations change only `reserved_qty`. Snapshots change only `quantity` (and recompute `fulfilled_pending_qty`, see §3). Neither flow ever writes the other's counter.

## 2. Stock snapshots

- A stock update is an **absolute snapshot** of what the supplier has for one supplier, SKU and website. It is not a delta.
- The snapshot includes units held by this module's open (unshipped) reservations. In other words, the supplier still has those units on the shelf; they are promised but not yet shipped.
- **Ordering:** updates are ordered by server acceptance (`update_id`, assigned when the REST call is accepted). An update is applied only if `update_id > inventory.last_update_id`. An older update that is processed late is marked `skipped_stale` and changes nothing.
- **Snapshot below reservations:** existing reservations are kept, new reservations are blocked (because `available = 0`), and the shortage is logged and exposed. Nothing is cancelled automatically.
- **Supplier-side staleness:** if the supplier sends an older snapshot after a newer one, the module can't tell, because the payload has no supplier revision or timestamp. The documented extension is a supplier revision field.

## 3. Fulfilment and the snapshot watermark

When Magento ships units, they move from `reserved_qty` to `fulfilled_pending_qty`. `quantity` is not touched: the supplier will report the lower stock in a later snapshot. Until a snapshot that reflects the shipment is applied, the shipped units must keep counting against availability, or they would be sold twice.

The module decides which shipments a snapshot reflects as follows.

At intake, in the same transaction that inserts the update:

```
update.fulfil_watermark = highest fulfil event id for this inventory row (0 if none or no row yet)
update.accepted_at      = database NOW()
```

When the update is applied (under the inventory row lock):

```
quantity              = snapshot qty
reserved_qty          = unchanged
fulfilled_pending_qty = sum of fulfil events for this row where
                          event_id > update.fulfil_watermark
                          OR created_at > update.accepted_at - max_lag
```

`max_lag` is `supplier_sync/snapshot/max_lag_seconds` (default 300). All timestamps use database time.

### Guarantees

**What the module guarantees:**

- Fulfil events are inserted while holding the inventory row lock, so for one inventory row the `event_id` order equals commit order.
- The watermark read takes no lock. A fulfil that commits after the read gets a higher id and stays pending. This errs toward under-selling.
- With no inventory row at intake the watermark is 0. No reservation can exist without a row, so every later fulfil stays pending.
- An older accepted update never overwrites a newer applied one.

**Integration contract (assumed, not verifiable from the payload):** a snapshot reflects every shipment Magento recorded before the snapshot was accepted, minus the lag window. Fulfil events inside the lag window are always treated as pending, even if the snapshot already included them. That under-sells until the next snapshot.

**What cannot be guaranteed:** a snapshot generated more than `max_lag` seconds before it was accepted, which misses a shipment recorded in that gap, is over-credited. The payload carries no supplier send time or revision. The production fix is a supplier `reflects_shipments_until` timestamp, or a revision with a fulfilment acknowledgement.

## 4. Pricing

- `unit_price` in the payload is the **supplier purchase cost**, stored as `unit_cost` per supplier, SKU and website.
- It is in the website base currency only. A payload whose currency is not the website base currency is rejected (`400 currency_mismatch`).
- It does **not** change any customer-facing Magento price. It is exposed through `GET /V1/supplier-sync/inventory` and the CLI.
- Writing it to the product `cost` attribute is a documented extension.

## 5. Suppliers, websites and assignments

- Supplier codes are 1-64 letters, digits, `_` or `-` (for example `SUPPLIER_001`) and are unique ignoring case: `ACME` and `acme` are the same supplier, including for idempotency.
- One supplier per SKU and website, through an explicit assignment managed with the CLI. There is no auto-assignment.
- An existing SKU with no assignment for the website: `400 unassigned_sku`.
- An update from a supplier that is not the assigned one: `409 assignment_mismatch`.
- **Reassignment** keeps existing reservations on the original supplier. Their releases and fulfilments still go to the original supplier's inventory row.
- **Product scope:** the SKU must exist, be a simple product, and be assigned to the target website. Otherwise `400 unknown_sku`, `unsupported_product_type` or `product_not_in_website`.
- **Disabled supplier** (`company_supplier.is_active = 0`):
  - new idempotency keys are rejected (`400 supplier_disabled`);
  - new reservations are rejected (placement fails);
  - releases still happen;
  - updates already accepted finish processing;
  - identical replays still return their stored receipt.
- **Disabled supplier website** (`company_supplier_website.is_active = 0`): the same as a disabled supplier, but only for that website (`400 website_disabled`).

## 6. Reservations

- Taken during **synchronous** order placement, **before payment**. Insufficient supplier stock, or any reservation failure, fails placement with a customer-safe message. No payment is attempted.
- Placement creates a *placement attempt*. Two fences guard it:
  - A pre-payment fence moves the attempt from `started` to `paying`. If the attempt is no longer `started`, payment never begins.
  - A pre-commit fence, inside the order save transaction, requires `paying` and moves the attempt to `order_saved`. If the attempt was released in the meantime, the order rolls back.
- A hold is released only when the attempt row proves no order can commit for it (see §8).
- Quantities for configurable products come from the parent order item; the SKU comes from the child.
- The same SKU on two cart lines is checked as one aggregated quantity.

## 7. Cancellation, refunds and shipment

Releases and fulfilments are computed from the order item's persisted quantities, never from the event that triggered them. Repeated or out-of-order events therefore have no extra effect.

```
fulfil_delta   = min(item.qty_shipped  - r.qty_fulfilled, r.qty_reserved - r.qty_fulfilled - r.qty_released)
cancel_delta   = min(item.qty_canceled - r.qty_released,  r.qty_reserved - r.qty_fulfilled - r.qty_released)
terminal_delta = r.qty_reserved - r.qty_fulfilled - r.qty_released     (only when the order is closed, canceled or complete)
```

Each delta is applied only when it is greater than 0, in the order fulfil, cancel, terminal.

- **Cancellation** releases the newly cancelled quantity.
- **Refunds** never release on their own. When the order reaches a terminal state (closed, canceled, complete), the remainder is released. A partial refund of unshipped units on an order that stays open keeps those units reserved until the order closes: this can under-sell, never oversell.
- **Shipment** moves the shipped quantity from reserved to fulfilled pending (§3).
- **Admin "Edit order"** cancels the original order (released) and places a new one (new reservation).

## 8. Placement failures and uncertain payment

| Situation | Outcome |
| --- | --- |
| Reservation fails (not enough stock, disabled supplier) | Placement fails before payment |
| Payment declines or `proceed()` throws before the order is saved | Hold released; the customer can retry |
| Exception after the order was saved | Reservations are linked to the order, not released |
| Worker crashed before payment started (`started`, lease expired) | Released by recovery; a resumed worker is blocked at the pre-payment fence |
| Worker crashed during payment (`paying`, lease expired) | **Hold kept** and detached from the cart, so the customer can retry. Listed for an operator |
| Worker crashed after the order was saved (`order_saved`) | Linked by recovery |

**Inventory correctness and payment correctness are separate.** The module guarantees the first: a released attempt can never become an order through the supported flow. It does not guarantee exactly-once payment. A gateway that captures and then times out leaves a payment that must be reconciled with the gateway's own tooling. Core Magento has the same exposure.

A detached `paying` attempt lets the customer retry while the first request might still finish. Both holds stay counted, so stock is safe, but two orders are possible. An operator resolves the old attempt with `supplier-sync:attempt:resolve --release`.

## 9. MSI

- Normal MSI checks and reservations are untouched. The module never writes `inventory_source_item`, `inventory_reservation` or the legacy stock tables.
- Supplier availability is an extra restriction: effective sellable quantity = `min(MSI salable qty, supplier available)`.
- For supplier-backed SKUs, MSI must still allow the sale. Use a dedicated drop-ship source with a high quantity, or set "Manage Stock" to No. If MSI says 0, MSI blocks first and the supplier ledger is never consulted.
- The storefront and cart show MSI salability only. A product can look in stock and fail at "Place Order". Cart-level supplier messages are an optional extension.

## 10. Input validation

| Field | Rule |
| --- | --- |
| `quantity` | ≥ 0; at most 4 decimal places; decimals only when the product allows decimal qty |
| `unit_price` | ≥ 0; at most 6 decimal places |
| `currency` | Must equal the website base currency (case-insensitive) |
| `idempotency_key` | 1–128 characters, `[A-Za-z0-9._:-]` |
| `website_code`, `supplier_code` | Must exist |

All quantity and price math uses bcmath on strings. Values are normalized before hashing so that `49.99` and `49.990` produce the same idempotency hash.

Suppliers should send decimals as JSON strings. Magento's REST layer turns JSON numbers into strings through PHP floats before the module sees them, so a number with more significant digits than a double holds would arrive rounded. Exponent notation sent as a JSON number (`1e3`) arrives as `"1000"`; as a string it is rejected.

## 11. Supported scope

**Supported:** synchronous checkout through `OrderManagementInterface::place` — storefront, REST and GraphQL carts, and admin order create — for simple products and configurable children.

**Unsupported, documented:**

- Asynchronous order placement (`checkout/async`). Disable it; the module logs a warning if it detects the async consumer context.
- Bundle and grouped products.
- Orders created without `OrderManagementInterface::place` (imports, custom creation). No supplier hold is taken.
- Order placement inside an existing database transaction. Supplier-backed quotes are rejected before any write.

Outbound reservation messages to suppliers are not required; they are a documented extension.

## 12. Worked examples

`available = max(0, quantity - reserved_qty - fulfilled_pending_qty)`. Examples 4 and 6 assume both shipments fall outside the lag window.

### Example 1. Concurrent reserve

Start: `quantity = 10`, nothing reserved. Two placements each request 6.

| Step | quantity | reserved_qty | fulfilled_pending_qty | available | Result |
| --- | --- | --- | --- | --- | --- |
| Start | 10 | 0 | 0 | 10 | — |
| Reserve A (6) | 10 | 6 | 0 | 4 | placement continues |
| Reserve B (6) | 10 | 6 | 0 | 4 | fails (`6 > 4`) |

Both placements lock the same inventory row, so B checks after A commits. Exactly one succeeds. The 20-against-10 concurrency proof is the same rule.

### Example 2. Idempotency

Same supplier, key `supplier-update-10001`.

| Call | Payload hash | Supplier | HTTP | Enqueued |
| --- | --- | --- | --- | --- |
| First | `H1` | enabled | `200`, `replayed=false`, new `update_id` | yes |
| Retry | `H1` | enabled | `200`, `replayed=true`, same `update_id` | no |
| Conflict | `H2` | enabled | `409 idempotency_conflict` | no |
| Replay after disable | `H1` | disabled | `200`, `replayed=true`, same `update_id` | no |
| New key after disable | `H3` | disabled | `400 supplier_disabled` | no |

### Example 3. Stale apply

The consumer processes update 7 before update 5.

| Applied `update_id` | Payload qty | `last_update_id` after | quantity | Status |
| --- | --- | --- | --- | --- |
| 7 | 20 | 7 | 20 | `applied` |
| 5 | 50 | 7 | 20 | `skipped_stale` |

### Example 4. Snapshot accepted after a shipment

| Step | quantity | reserved_qty | fulfilled_pending_qty | available |
| --- | --- | --- | --- | --- |
| Snapshot 25 applied | 25 | 0 | 0 | 25 |
| Reserve 5 | 25 | 5 | 0 | 20 |
| Ship 2 (fulfil event E1) | 25 | 3 | 2 | 20 |
| Snapshot 8 accepted (watermark E1), applied | 8 | 3 | 0 | 5 |

No fulfil events are above E1, so pending fulfilment clears: the supplier's 8 already excludes the 2 shipped units.

### Example 5. Cancel twice, then close

Reservation of 10, nothing shipped.

| Event | item.qty_canceled | qty_released | status | inventory reserved_qty |
| --- | --- | --- | --- | --- |
| After placement | 0 | 0 | open | 10 |
| Partial cancel 3 | 3 | 3 | open | 7 |
| Same event replayed | 3 | 3 | open | 7 |
| Order closed | 3 | 10 | closed | 0 |

The replay delta is `3 - 3 = 0`. The close releases `10 - 0 - 3 = 7`.

### Example 6. Snapshot accepted before a shipment, applied after it

| Time | Event | quantity | reserved_qty | fulfilled_pending_qty | available |
| --- | --- | --- | --- | --- | --- |
| T0 | Snapshot 10 applied | 10 | 0 | 0 | 10 |
| T0b | Reserve 3 | 10 | 3 | 0 | 7 |
| T1 | Update U (qty 10) accepted, watermark E0, waits in queue | 10 | 3 | 0 | 7 |
| T2 | Ship 3 (E1) | 10 | 0 | 3 | 7 |
| T3 | U applied | 10 | 0 | 3 | 7 |

E1 is above U's watermark, so the 3 shipped units stay pending. Clearing them would wrongly report 10 available.

### Example 7. Declined payment, then retry

`quantity = 5`; the customer orders 2.

| Step | Attempt | Reservation | reserved_qty | available |
| --- | --- | --- | --- | --- |
| A created, reserves 2 | A `started` | `pending_placement` | 2 | 3 |
| Pre-payment fence | A `paying` | unchanged | 2 | 3 |
| Gateway declines | A `failed` | `closed`, 2 released | 0 | 5 |
| Retry: B reserves 2, order saved | B `order_saved` | new `pending_placement` | 2 | 3 |
| Plugin links | B `completed` | `open` | 2 | 3 |

### Example 8. Crash recovery by attempt state

Worker W created attempt A and reserved 2 of 5 units (`available = 3`), then crashed. A's lease expires and recovery runs.

| A's status | Recovery | reserved_qty | If W resumes | Customer retry |
| --- | --- | --- | --- | --- |
| `started` | released | 0 | blocked at the pre-payment fence; payment never starts | allowed |
| `paying` | detached, hold kept | 2 | pre-commit fence accepts; order commits and is linked on the next pass | allowed; a second hold makes `reserved_qty` 4. No oversell; a duplicate order is possible |
| `paying`, then operator release | released | 0 | pre-commit fence rejects; the order rolls back (a capture may remain) | allowed |
| `order_saved` | linked | 2 | nothing left to do | the cart is already converted |
