<?php
/**
 * Copyright © Company. All rights reserved.
 *
 * Shared helpers for the scenario harnesses: committed fixtures, checks, parallel child processes, cleanup.
 * Scenario harnesses run against a real Magento database in developer mode, because separate processes cannot
 * see uncommitted test data. Every fixture uses a dedicated supplier code and is deleted afterwards.
 */
declare(strict_types=1);

// phpcs:ignoreFile -- standalone CLI test harness, not module runtime code.

namespace Company\SupplierSync\Test\Scenario;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\ObjectManagerInterface;

class Harness
{
    public ObjectManagerInterface $om;
    public AdapterInterface $db;
    public int $supplierId = 0;
    private int $passed = 0;
    private array $failed = [];

    public function __construct(public readonly string $root, public readonly string $supplierCode)
    {
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        $this->db = $this->om->get(ResourceConnection::class)->getConnection();
    }

    /** Create (or reuse) a simple product enabled on website 1. */
    public function product(string $sku, bool $decimal = false): void
    {
        $repo = $this->om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        try {
            $repo->get($sku);
            return;
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
        }
        $p = $this->om->create(\Magento\Catalog\Model\Product::class);
        $p->setSku($sku)->setName($sku)->setTypeId('simple')->setAttributeSetId(4)->setPrice(10)
            ->setWebsiteIds([1])->setStatus(1)->setVisibility(4)
            ->setStockData(['use_config_manage_stock' => 0, 'manage_stock' => 0, 'is_qty_decimal' => (int)$decimal, 'is_in_stock' => 1]);
        $repo->save($p);
    }

    /** Create the harness supplier (enabled on base) and assign the given SKUs to it. */
    public function supplier(string ...$skus): int
    {
        $repo = $this->om->get(\Company\SupplierSync\Api\SupplierRepositoryInterface::class);
        try {
            $s = $repo->getByCode($this->supplierCode);
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            $s = $repo->save($this->om->create(\Company\SupplierSync\Api\Data\SupplierInterface::class)
                ->setCode($this->supplierCode)->setName('Scenario fixture')->setIsActive(true));
        }
        $s->setIsActive(true);
        $repo->save($s);
        $this->om->get(\Company\SupplierSync\Api\SupplierWebsiteManagementInterface::class)
            ->setActive($this->supplierCode, 'base', true);
        foreach ($skus as $sku) {
            $this->om->get(\Company\SupplierSync\Api\AssignmentManagementInterface::class)->assign($sku, 'base', $this->supplierCode);
        }
        return $this->supplierId = (int)$s->getSupplierId();
    }

    /** Insert an accepted update directly (no publish), like intake's transaction does. */
    public function accept(string $sku, string $qty, ?string $key = null): int
    {
        $payload = $this->om->get(\Company\SupplierSync\Model\Update\PayloadNormalizer::class)
            ->normalize($this->supplierCode, $key ?? uniqid('k', true), $sku, $qty, '1.50', 'USD', 'base');
        $store = $this->om->get(\Company\SupplierSync\Model\Update\UpdateStore::class);
        return $this->om->get(\Company\SupplierSync\Model\Db\TransactionRunner::class)
            ->run(fn ($c) => $store->insertAccepted($c, $this->supplierId, 1, $payload));
    }

    public function update(int $updateId): array
    {
        return $this->db->fetchRow('SELECT * FROM company_supplier_update WHERE update_id = ?', [$updateId]);
    }

    public function outbox(int $updateId): array
    {
        return $this->db->fetchRow('SELECT * FROM company_supplier_outbox WHERE update_id = ?', [$updateId]);
    }

    public function inventory(string $sku): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM company_supplier_inventory WHERE supplier_id = ? AND sku = ? AND website_id = 1',
            [$this->supplierId, $sku]
        ) ?: null;
    }

    public function check(string $label, bool $ok, string $detail = ''): void
    {
        printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $ok || $detail === '' ? '' : " ($detail)");
        $ok ? $this->passed++ : $this->failed[] = $label;
    }

    public function section(string $title): void
    {
        echo "\n$title\n";
    }

    /**
     * Run worker.php commands in parallel, all released at the same moment. Returns decoded output lines.
     *
     * @param array<int, array<int, string>> $commands worker.php arguments after <start_at>
     */
    public function parallel(array $commands): array
    {
        $startAt = (string)(microtime(true) + 4.0);
        $running = [];
        foreach ($commands as $args) {
            $cmd = array_merge([PHP_BINARY, __DIR__ . '/worker.php', $this->root, $startAt], $args);
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $running[] = [$proc, $pipes];
        }
        $results = [];
        foreach ($running as [$proc, $pipes]) {
            $out = trim(stream_get_contents($pipes[1]));
            $err = trim(stream_get_contents($pipes[2]));
            proc_close($proc);
            $lines = array_filter(explode("\n", $out));
            $results[] = json_decode((string)end($lines), true) ?? ['ok' => false, 'error' => $out . $err];
        }
        return $results;
    }

    /** Delete every module row that belongs to the harness supplier. */
    public function cleanup(): void
    {
        if (!$this->supplierId) {
            return;
        }
        $db = $this->db;
        $inv = $db->fetchCol('SELECT inventory_id FROM company_supplier_inventory WHERE supplier_id = ?', [$this->supplierId]);
        if ($inv) {
            $attempts = $db->fetchCol('SELECT DISTINCT attempt_id FROM company_supplier_reservation WHERE inventory_id IN (?)', [$inv]);
            $db->delete('company_supplier_reservation_event', ['inventory_id IN (?)' => $inv]);
            $db->delete('company_supplier_reservation', ['inventory_id IN (?)' => $inv]);
            if ($attempts) {
                $db->delete('company_supplier_placement_attempt', ['attempt_id IN (?)' => $attempts]);
            }
            $db->delete('company_supplier_inventory', ['inventory_id IN (?)' => $inv]);
        }
        $db->delete('company_supplier_update', ['supplier_id = ?' => $this->supplierId]);
        $db->delete('company_supplier_assignment', ['supplier_id = ?' => $this->supplierId]);
    }

    public function finish(): int
    {
        printf("\n%d passed, %d failed\n", $this->passed, count($this->failed));
        foreach ($this->failed as $label) {
            echo "  FAILED: $label\n";
        }
        return $this->failed ? 1 : 0;
    }
}
