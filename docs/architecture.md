# Architecture

This document explains how `Company_SupplierSync` keeps supplier inventory correct under concurrency, retries and failures. The rules it enforces are in [business-rules.md](business-rules.md).

## Overview

Three flows share one ledger, `company_supplier_inventory`:

1. **Intake (synchronous REST).** Validate, normalize and hash the payload. Insert the update and an outbox row in one transaction. Return a receipt.
2. **Apply (asynchronous queue).** A consumer claims the update exclusively and applies the snapshot under the inventory row lock.
3. **Order placement and lifecycle.** A plugin on `OrderManagementInterface::place` reserves stock before payment; two fences stop a released placement from committing an order. One observer reconciles cancellations, shipments and order closure.

## Spike findings (P2)

_To be filled in by the P2 hook spike._

## Transaction boundaries and lock order

_To be filled in during P4._

## Hooks

_To be filled in during P7._

## Test map

_To be filled in during P8._
