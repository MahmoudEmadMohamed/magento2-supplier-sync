<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model;

use LogicException;

/**
 * The single definition of allowed status transitions for updates, placement attempts and reservations.
 */
class StateTransitionValidator
{
    public const UPDATE = 'update';
    public const ATTEMPT = 'attempt';
    public const RESERVATION = 'reservation';

    private const MATRIX = [
        self::UPDATE => [
            UpdateStatus::QUEUED => [UpdateStatus::PROCESSING],
            UpdateStatus::RETRY_PENDING => [UpdateStatus::PROCESSING],
            UpdateStatus::PROCESSING => [
                UpdateStatus::APPLIED,
                UpdateStatus::SKIPPED_STALE,
                UpdateStatus::REJECTED,
                UpdateStatus::RETRY_PENDING,
                UpdateStatus::FAILED,
            ],
            UpdateStatus::FAILED => [UpdateStatus::QUEUED],
            UpdateStatus::REJECTED => [UpdateStatus::QUEUED],
            UpdateStatus::APPLIED => [],
            UpdateStatus::SKIPPED_STALE => [],
        ],
        self::ATTEMPT => [
            AttemptStatus::STARTED => [AttemptStatus::PAYING, AttemptStatus::FAILED],
            AttemptStatus::PAYING => [AttemptStatus::ORDER_SAVED, AttemptStatus::FAILED],
            AttemptStatus::ORDER_SAVED => [AttemptStatus::COMPLETED],
            AttemptStatus::COMPLETED => [],
            AttemptStatus::FAILED => [],
        ],
        self::RESERVATION => [
            ReservationStatus::PENDING_PLACEMENT => [ReservationStatus::OPEN, ReservationStatus::CLOSED],
            ReservationStatus::OPEN => [ReservationStatus::CLOSED],
            ReservationStatus::CLOSED => [],
        ],
    ];

    /**
     * Whether $from → $to is allowed. Staying in the same status is not a transition and is always allowed.
     *
     * @param string $machine
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function isAllowed(string $machine, string $from, string $to): bool
    {
        if (!isset(self::MATRIX[$machine][$from])) {
            return false;
        }
        return $from === $to || in_array($to, self::MATRIX[$machine][$from], true);
    }

    /**
     * Throw unless $from → $to is allowed.
     *
     * @param string $machine
     * @param string $from
     * @param string $to
     * @return void
     * @throws LogicException
     */
    public function assert(string $machine, string $from, string $to): void
    {
        if (!$this->isAllowed($machine, $from, $to)) {
            throw new LogicException(sprintf('Illegal %s transition %s -> %s.', $machine, $from, $to));
        }
    }

    /**
     * All statuses of a machine.
     *
     * @param string $machine
     * @return string[]
     */
    public function getStatuses(string $machine): array
    {
        return array_keys(self::MATRIX[$machine] ?? []);
    }
}
