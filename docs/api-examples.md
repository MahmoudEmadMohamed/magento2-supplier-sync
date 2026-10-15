# API examples

All examples use an integration token as a bearer token (see the README for enabling that) against `https://magento.test`. Response bodies are the ones the REST scenario harness receives from Magento's REST controller.

```bash
export BASE=https://magento.test/rest/V1/supplier-sync
export AUTH="Authorization: Bearer $TOKEN"
```

## Submit a stock update

```bash
curl -s -X POST "$BASE/updates" -H "$AUTH" -H 'Content-Type: application/json' -d '{
  "supplier_code": "acme",
  "idempotency_key": "acme-2026-10-15-0001",
  "sku": "SKU-123",
  "quantity": "25",
  "unit_price": "49.99",
  "currency": "USD",
  "website_code": "base"
}'
```

`200`:

```json
{"update_id": 1, "status": "queued", "replayed": false}
```

Field names may also be sent in camelCase (`supplierCode`, `idempotencyKey`, `unitPrice`, `websiteCode`). Send `quantity` and `unit_price` as strings: JSON numbers are converted to PHP floats before the module sees them.

### Retry with the same key and an equivalent payload

`"quantity": "25.0000"` and `"unit_price": "49.990"` normalize to the same values, so this is a replay. Nothing is enqueued, and the current status is returned.

`200`:

```json
{"update_id": 1, "status": "applied", "replayed": true}
```

A replay returns its receipt even if the supplier has been disabled or the SKU reassigned since. Admission rules apply only to new keys.

### Same key, different payload

`409`:

```json
{
  "message": "Idempotency key %key was already used for a different payload.",
  "parameters": {"error_code": "idempotency_conflict", "key": "acme-2026-10-15-0001"}
}
```

Magento's error format keeps `%placeholders` in `message` and puts the values in `parameters`. In developer mode Magento also adds a `trace` key; production mode never does.

## Check the outcome

```bash
curl -s "$BASE/updates/1" -H "$AUTH"
```

`200`:

```json
{
  "update_id": 1,
  "supplier_code": "acme",
  "idempotency_key": "acme-2026-10-15-0001",
  "sku": "SKU-123",
  "website_code": "base",
  "quantity": "25.0000",
  "unit_price": "49.990000",
  "currency": "USD",
  "status": "applied",
  "attempts": 1,
  "accepted_at": "2026-10-15 12:13:06",
  "processed_at": "2026-10-15 12:13:29"
}
```

| Status | Meaning |
| --- | --- |
| `queued` | Accepted, waiting for the consumer |
| `processing` | Claimed by a consumer (lease 120 s) |
| `retry_pending` | A transient failure; retried at `next_attempt_at` |
| `applied` | The snapshot is now the inventory quantity |
| `skipped_stale` | A newer update (higher `update_id`) was already applied |
| `rejected` | The SKU was deleted or reassigned after acceptance; see `error_code` |
| `failed` | Attempts exhausted or a permanent processing error; an operator can retry it |

Fields without a value (`next_attempt_at`, `processed_at`, `error_code`, `error`) are omitted. Unknown id: `404` `{"message": "Stock update %1 does not exist.", "parameters": [999999999]}`.

## Read inventory

```bash
curl -s "$BASE/inventory?sku=SKU-123&websiteCode=base" -H "$AUTH"
```

`200`. One row per supplier that holds a ledger for the SKU; more than one after a reassignment:

```json
[
  {
    "supplier_code": "acme",
    "is_assigned": true,
    "sku": "SKU-123",
    "website_code": "base",
    "quantity": "25.0000",
    "reserved_qty": "3.0000",
    "fulfilled_pending_qty": "2.0000",
    "available": "20.0000",
    "shortage": "0.0000",
    "unit_cost": "49.990000",
    "currency": "USD",
    "last_update_id": 1,
    "last_applied_at": "2026-10-15 12:13:29"
  }
]
```

An assigned SKU with no applied snapshot returns `[]`, and checkout treats it as unavailable.

## Errors

| HTTP | `error_code` | Example message |
| --- | --- | --- |
| 400 | `invalid_idempotency_key` | The idempotency key must be 1-128 characters of letters, digits, ".", "_", ":" or "-". |
| 400 | `invalid_qty` | The quantity must be a non-negative decimal below 100000000 with at most %scale decimal places. |
| 400 | `invalid_qty` | Product %sku does not allow decimal quantities. |
| 400 | `invalid_price` | The unit price must be a non-negative decimal with at most %scale decimal places. |
| 400 | `invalid_currency` | The currency must be a 3-letter ISO 4217 code. |
| 400 | `invalid_sku` | The SKU must be 1-64 characters. |
| 400 | `currency_mismatch` | Currency %currency does not match the website base currency %base. |
| 400 | `unknown_supplier` | Supplier %supplier does not exist. |
| 400 | `unknown_website` | Website %website does not exist. |
| 400 | `supplier_disabled` | Supplier %supplier is disabled. |
| 400 | `website_disabled` | Supplier %supplier is not enabled for website %website. |
| 400 | `unknown_sku` | Product %sku does not exist. |
| 400 | `unsupported_product_type` | Product %sku is of type %type; only simple products can be supplier-backed. |
| 400 | `product_not_in_website` | Product %sku is not assigned to website %website. |
| 400 | `unassigned_sku` | Product %sku has no supplier assignment on website %website. |
| 409 | `assignment_mismatch` | Product %sku on website %website is assigned to another supplier. |
| 409 | `idempotency_conflict` | Idempotency key %key was already used for a different payload. |
| 500 | `internal_error` | The update could not be processed. Reference: %reference (the reference is in `var/log/supplier_sync.log`) |
| 401 | — | Missing or invalid token: Magento's "The consumer isn't authorized to access %resources." |
| 401 | — | Valid token without the ACL resource: the same message, with the missing resource in `parameters.resources` |

Checks run in this order: syntax (key, SKU, quantity, price, currency), then the idempotency replay check, then admission rules (supplier, website, product, decimal quantity, assignment, currency) for new keys only.
