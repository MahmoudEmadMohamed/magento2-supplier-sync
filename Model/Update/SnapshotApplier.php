<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Model\Update;

use Company\SupplierSync\Api\AssignmentManagementInterface;
use Company\SupplierSync\Model\Config;
use Company\SupplierSync\Model\Decimal;
use Company\SupplierSync\Model\Inventory\InventoryLedger;
use Company\SupplierSync\Model\UpdateStatus;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies one claimed update to the inventory ledger. Runs inside the consumer's apply transaction, with the
 * update row already locked (lock order: update → inventory).
 */
class SnapshotApplier
{
    /**
     * @param ResourceConnection $resource
     * @param InventoryLedger $inventoryLedger
     * @param AssignmentManagementInterface $assignments
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly InventoryLedger $inventoryLedger,
        private readonly AssignmentManagementInterface $assignments,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Apply the update and return its terminal outcome.
     *
     * @param AdapterInterface $connection
     * @param array<string,mixed> $update Locked update row
     * @return array{status:string,error_code:?string,error:?string}
     */
    public function apply(AdapterInterface $connection, array $update): array
    {
        $supplierId = (int)$update['supplier_id'];
        $sku = (string)$update['sku'];
        $websiteId = (int)$update['website_id'];

        // Races after acceptance. A supplier disabled since acceptance still finishes (business rule).
        if (!$this->productExists($connection, $sku)) {
            return $this->rejected('unknown_sku', 'The product was deleted after the update was accepted.');
        }
        if ($this->assignments->getAssignedSupplierId($sku, $websiteId) !== $supplierId) {
            return $this->rejected('assignment_mismatch', 'The SKU was reassigned after the update was accepted.');
        }

        $table = $this->resource->getTableName(InventoryLedger::TABLE);
        // Creates the row on first apply. ON DUPLICATE KEY UPDATE takes an exclusive lock on an existing row
        // directly, so two first applies cannot both hold a shared lock and deadlock upgrading it.
        // phpcs:disable Magento2.SQL.RawQuery -- the query builder cannot express this upsert-and-lock
        $connection->query(
            "INSERT INTO {$table} (supplier_id, sku, website_id) VALUES (?, ?, ?)"
            . ' ON DUPLICATE KEY UPDATE inventory_id = inventory_id',
            [$supplierId, $sku, $websiteId]
        );
        // phpcs:enable Magento2.SQL.RawQuery
        // Locking read: a plain read could miss a row another first apply committed while we waited above.
        $row = $this->inventoryLedger->lockByKey($connection, $supplierId, $sku, $websiteId);
        if ($row === null) {
            throw new \LogicException(sprintf('Inventory row for update %d was not created.', $update['update_id']));
        }
        $inventoryId = (int)$row['inventory_id'];

        if ((int)$update['update_id'] <= (int)$row['last_update_id']) {
            return ['status' => UpdateStatus::SKIPPED_STALE, 'error_code' => null, 'error' => null];
        }

        // Shipments this snapshot cannot be assumed to reflect: recorded after acceptance (id above the
        // watermark), or inside the lag window before it. See docs/business-rules.md, section 3.
        // Locking read, so fulfils committed after this transaction's snapshot are counted too. New fulfils
        // for this row need the inventory lock we hold, so the sum cannot change before we commit.
        $pending = (string)$connection->fetchOne(
            sprintf(
                'SELECT COALESCE(SUM(qty), 0) FROM %s WHERE inventory_id = ? AND type = \'fulfil\''
                . ' AND (event_id > ? OR created_at > CAST(? AS DATETIME) - INTERVAL %d SECOND)'
                . ' LOCK IN SHARE MODE',
                $this->resource->getTableName(InventoryLedger::EVENT_TABLE),
                $this->config->getSnapshotMaxLagSeconds()
            ),
            [$inventoryId, (int)$update['fulfil_watermark'], (string)$update['accepted_at']]
        );
        $connection->update($table, [
            'quantity' => (string)$update['quantity'],
            'fulfilled_pending_qty' => Decimal::fromNumber($pending),
            'unit_cost' => (string)$update['unit_cost'],
            'currency' => (string)$update['currency'],
            'last_update_id' => (int)$update['update_id'],
            'last_applied_at' => new \Zend_Db_Expr('NOW()'),
        ], ['inventory_id = ?' => $inventoryId]);

        $row['quantity'] = (string)$update['quantity'];
        $row['fulfilled_pending_qty'] = Decimal::fromNumber($pending);
        $shortage = $this->inventoryLedger->getShortage($row);
        if (Decimal::isPositive($shortage)) {
            $this->logger->warning('Snapshot is below open supplier commitments; new reservations are blocked.', [
                'update_id' => (int)$update['update_id'],
                'inventory_id' => $inventoryId,
                'shortage' => $shortage,
            ]);
        }
        return ['status' => UpdateStatus::APPLIED, 'error_code' => null, 'error' => null];
    }

    /**
     * Whether a product with this SKU still exists.
     *
     * @param AdapterInterface $connection
     * @param string $sku
     * @return bool
     */
    private function productExists(AdapterInterface $connection, string $sku): bool
    {
        return (bool)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
                ->where('sku = ?', $sku)
        );
    }

    /**
     * A permanent post-acceptance rejection.
     *
     * @param string $code
     * @param string $message
     * @return array{status:string,error_code:?string,error:?string}
     */
    private function rejected(string $code, string $message): array
    {
        return ['status' => UpdateStatus::REJECTED, 'error_code' => $code, 'error' => $message];
    }
}
