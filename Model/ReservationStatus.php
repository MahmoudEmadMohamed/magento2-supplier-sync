<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model;

/**
 * Reservation statuses. The counters are authoritative; the status is derived from them and stored for queries.
 */
class ReservationStatus
{
    /** Held for a placement attempt; not yet linked to an order item. */
    public const PENDING_PLACEMENT = 'pending_placement';
    /** Linked to an order item with an unshipped, unreleased remainder. */
    public const OPEN = 'open';
    /** qty_fulfilled + qty_released = qty_reserved. */
    public const CLOSED = 'closed';

    /**
     * Derive the status from the counters.
     *
     * @param string $qtyReserved
     * @param string $qtyFulfilled
     * @param string $qtyReleased
     * @param bool $isLinked
     * @return string
     */
    public static function derive(
        string $qtyReserved,
        string $qtyFulfilled,
        string $qtyReleased,
        bool $isLinked
    ): string {
        if (Decimal::cmp(Decimal::add($qtyFulfilled, $qtyReleased), $qtyReserved) >= 0) {
            return self::CLOSED;
        }
        return $isLinked ? self::OPEN : self::PENDING_PLACEMENT;
    }
}
