<?php
/**
 * Copyright © Company. All rights reserved.
 *
 * Test doubles for the placement scenarios. They are activated only inside a harness process through
 * ObjectManager preferences (Harness::useDoubles); the module's own DI never references them.
 */
declare(strict_types=1);

// phpcs:ignoreFile -- standalone CLI test harness, not module runtime code.

namespace Company\SupplierSync\Test\Scenario;

use Magento\Framework\Exception\LocalizedException;

/** Check/money order that, when armed, records a capture and then throws (a gateway timeout after capture). */
class CaptureThenFailPayment extends \Magento\OfflinePayments\Model\Checkmo
{
    public static bool $fail = false;
    public static int $captures = 0;
    /** Seconds to sleep inside payment (simulates a slow gateway), read from SUPPLIER_SYNC_PAYMENT_SLEEP. */
    public static int $sleep = 0;

    public function validate()
    {
        $sleep = self::$sleep ?: (int)getenv('SUPPLIER_SYNC_PAYMENT_SLEEP');
        if ($sleep > 0) {
            sleep($sleep);
        }
        if (self::$fail) {
            self::$captures++;
            throw new LocalizedException(__('Simulated gateway timeout after capture.'));
        }
        return parent::validate();
    }
}

/** Order relations (items, addresses) that fail when armed: the order save fails after the pre-commit fence. */
class FailingOrderRelation extends \Magento\Sales\Model\ResourceModel\Order\Relation
{
    public static bool $fail = false;

    public function processRelation(\Magento\Framework\Model\AbstractModel $object)
    {
        if (self::$fail) {
            throw new \RuntimeException('Simulated order save failure after the fence.');
        }
        parent::processRelation($object);
    }
}

/** Order repository that throws after the order committed, when armed. */
class ThrowAfterCommitOrderRepository extends \Magento\Sales\Model\OrderRepository
{
    public static bool $fail = false;

    public function save(\Magento\Sales\Api\Data\OrderInterface $entity)
    {
        $result = parent::save($entity);
        if (self::$fail) {
            throw new \RuntimeException('Simulated failure after the order committed.');
        }
        return $result;
    }
}

/** Pre-payment fence that does nothing when armed: a placement path that skips it. */
class SkippablePrePaymentFence extends \Company\SupplierSync\Observer\PrePaymentFence
{
    public static bool $skip = false;

    public function execute(\Magento\Framework\Event\Observer $observer): void
    {
        if (!self::$skip) {
            parent::execute($observer);
        }
    }
}

/** Module config with an adjustable snapshot lag window. */
class AdjustableConfig extends \Company\SupplierSync\Model\Config
{
    public static ?int $maxLag = null;

    public function getSnapshotMaxLagSeconds(): int
    {
        return self::$maxLag ?? parent::getSnapshotMaxLagSeconds();
    }
}
