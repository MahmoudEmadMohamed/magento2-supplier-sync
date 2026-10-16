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
        self::requireRuntimeDi($root);
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
        $this->db = $this->om->get(ResourceConnection::class)->getConnection();
    }

    /**
     * Stop unless Magento builds its object graph at runtime.
     *
     * After setup:di:compile, constructor arguments come from generated/metadata and the harness can no longer swap
     * in its test doubles; scenarios would then run against the real classes and report misleading results.
     */
    public static function requireRuntimeDi(string $root): void
    {
        if (file_exists($root . '/generated/metadata/global.php')) {
            fwrite(STDERR, "Compiled DI detected ($root/generated/metadata). The scenario harnesses need runtime DI to\n"
                . "inject their test doubles. In developer mode run `bin/magento setup:upgrade` (or remove\n"
                . "generated/metadata), run the scenarios, then `bin/magento setup:di:compile` again if you need it.\n");
            exit(2);
        }
    }

    /** Read a (possibly inherited, private) property of an object. */
    public static function property(object $object, string $name): mixed
    {
        for ($class = new \ReflectionClass($object); $class; $class = $class->getParentClass()) {
            if ($class->hasProperty($name)) {
                return $class->getProperty($name)->getValue($object);
            }
        }
        throw new \RuntimeException(get_class($object) . " has no property $name");
    }

    /** Route the module and core classes to the harness test doubles (before they are first instantiated). */
    public function useDoubles(): void
    {
        require_once __DIR__ . '/Doubles.php';
        $this->om->configure(['preferences' => [
            \Magento\OfflinePayments\Model\Checkmo::class => CaptureThenFailPayment::class,
            \Magento\Sales\Model\ResourceModel\Order\Relation::class => FailingOrderRelation::class,
            \Magento\Sales\Api\OrderRepositoryInterface::class => ThrowAfterCommitOrderRepository::class,
            \Company\SupplierSync\Observer\PrePaymentFence::class => SkippablePrePaymentFence::class,
            \Company\SupplierSync\Model\Config::class => AdjustableConfig::class,
        ]]);
        $this->assertDoublesActive();
    }

    /** Verify the doubles reached the objects the scenarios depend on, not just fresh instances. */
    private function assertDoublesActive(): void
    {
        $om = $this->om;
        $relations = self::property(
            self::property($om->get(\Magento\Sales\Model\ResourceModel\Order::class), 'entityRelationComposite'),
            'relationProcessors'
        );
        $checks = [
            'snapshot applier config' => self::property(
                $om->get(\Company\SupplierSync\Model\Update\SnapshotApplier::class),
                'config'
            ) instanceof AdjustableConfig,
            'order service repository' => self::property(
                $om->get(\Magento\Sales\Api\OrderManagementInterface::class),
                'orderRepository'
            ) instanceof ThrowAfterCommitOrderRepository,
            'order relation processor' => (bool)array_filter(
                $relations,
                fn ($r) => $r instanceof FailingOrderRelation
            ),
            'payment method factory' => $om->get(\Magento\Payment\Model\Method\Factory::class)
                ->create(\Magento\OfflinePayments\Model\Checkmo::class) instanceof CaptureThenFailPayment,
            'pre-payment fence observer' => $om->get(\Company\SupplierSync\Observer\PrePaymentFence::class)
                instanceof SkippablePrePaymentFence,
        ];
        $missing = array_keys(array_filter($checks, fn ($ok) => !$ok));
        if ($missing) {
            throw new \RuntimeException('Test doubles are not active for: ' . implode(', ', $missing));
        }
    }

    /** Rebuild the indexes a new product needs before it can be added to a cart. */
    public function reindex(): void
    {
        foreach (['cataloginventory_stock', 'inventory', 'catalog_product_price'] as $id) {
            $this->om->create(\Magento\Indexer\Model\Indexer::class)->load($id)->reindexAll();
        }
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
        $this->reindex();
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

    /** Create (or reuse) a configurable product with simple children, one per option label. Returns option ids. */
    public function configurable(string $sku, array $labels): array
    {
        $eavSetup = $this->om->get(\Magento\Eav\Setup\EavSetupFactory::class)->create();
        $code = 'supplier_sync_size';
        if (!$eavSetup->getAttributeId('catalog_product', $code)) {
            $eavSetup->addAttribute('catalog_product', $code, [
                'type' => 'int', 'label' => 'Size', 'input' => 'select', 'global' => 1, 'user_defined' => true,
                'required' => false, 'visible' => true, 'option' => ['values' => $labels],
                'apply_to' => 'simple,configurable',
            ]);
            $eavSetup->addAttributeToGroup('catalog_product', 4, 'General', $code);
        }
        $attr = $this->om->create(\Magento\Eav\Model\Config::class)->getAttribute('catalog_product', $code);
        $options = [];
        foreach ($attr->getSource()->getAllOptions(false) as $o) {
            $options[$o['label']] = (int)$o['value'];
        }
        $repo = $this->om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $children = [];
        foreach ($labels as $label) {
            $childSku = "$sku-$label";
            try {
                $children[] = (int)$repo->get($childSku)->getId();
            } catch (\Magento\Framework\Exception\NoSuchEntityException) {
                $p = $this->om->create(\Magento\Catalog\Model\Product::class);
                $p->setSku($childSku)->setName($childSku)->setTypeId('simple')->setAttributeSetId(4)->setPrice(10)
                    ->setWebsiteIds([1])->setStatus(1)->setVisibility(1)->setData($code, $options[$label])
                    ->setStockData(['use_config_manage_stock' => 0, 'manage_stock' => 0, 'is_in_stock' => 1]);
                $children[] = (int)$repo->save($p)->getId();
            }
        }
        try {
            $repo->get($sku);
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            $created = true;
            $conf = $this->om->create(\Magento\Catalog\Model\Product::class);
            $conf->setSku($sku)->setName($sku)->setTypeId('configurable')->setAttributeSetId(4)->setWebsiteIds([1])
                ->setStatus(1)->setVisibility(4)->setStockData(['use_config_manage_stock' => 0, 'manage_stock' => 0, 'is_in_stock' => 1]);
            $factory = $this->om->get(\Magento\ConfigurableProduct\Helper\Product\Options\Factory::class);
            $ext = $conf->getExtensionAttributes();
            $ext->setConfigurableProductOptions($factory->create([[
                'attribute_id' => $attr->getId(), 'code' => $code, 'label' => 'Size', 'position' => 0,
                'values' => array_map(fn ($v) => ['label' => 'x', 'attribute_id' => $attr->getId(), 'value_index' => $v], array_values($options)),
            ]]));
            $ext->setConfigurableProductLinks($children);
            $conf->setExtensionAttributes($ext);
            $repo->save($conf);
        }
        if (!empty($created)) {
            $this->reindex();
        }
        return ['attribute_id' => (int)$attr->getId(), 'options' => $options];
    }

    /**
     * Build and save a guest quote. Lines: ['sku' => .., 'qty' => ..] or ['sku' => configurable, 'qty' => ..,
     * 'super' => [attribute_id => option_id]]. Returns the quote id.
     */
    public function quote(array $lines): int
    {
        $store = $this->om->get(\Magento\Store\Model\StoreManagerInterface::class);
        $store->setCurrentStore(1);
        $repo = $this->om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $quotes = $this->om->get(\Magento\Quote\Api\CartRepositoryInterface::class);
        $quote = $this->om->create(\Magento\Quote\Model\Quote::class);
        $quote->setStore($store->getStore(1))->setCurrency()->setCustomerIsGuest(true)
            ->setCustomerEmail('scenario@example.com')->setCheckoutMethod('guest');
        foreach ($lines as $line) {
            $request = ['qty' => $line['qty']] + (isset($line['super']) ? ['super_attribute' => $line['super']] : []);
            $result = $quote->addProduct($repo->get($line['sku'], false, 1, true), new \Magento\Framework\DataObject($request));
            if (is_string($result)) {
                throw new \RuntimeException($result);
            }
        }
        $address = ['firstname' => 'Sam', 'lastname' => 'Scenario', 'street' => 'Main 1', 'city' => 'Austin',
            'country_id' => 'US', 'region_id' => 57, 'postcode' => '78701', 'telephone' => '5555555',
            'email' => 'scenario@example.com'];
        $quote->getBillingAddress()->addData($address);
        $quote->getShippingAddress()->addData($address)->setCollectShippingRates(true)->collectShippingRates()
            ->setShippingMethod('flatrate_flatrate');
        $quote->collectTotals();
        $quotes->save($quote);
        $quote->getPayment()->setQuote($quote)->importData(['method' => 'checkmo']);
        $quotes->save($quote);
        return (int)$quote->getId();
    }

    /** Place a saved quote the way admin order create does (QuoteManagement::submit). Returns the order id. */
    public function submitQuote(int $quoteId): int
    {
        $quote = $this->om->get(\Magento\Quote\Api\CartRepositoryInterface::class)->get($quoteId);
        return (int)$this->om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($quote)->getEntityId();
    }

    /** Place a saved quote the way checkout and REST do (CartManagementInterface::placeOrder). */
    public function placeQuote(int $quoteId): int
    {
        return (int)$this->om->get(\Magento\Quote\Api\CartManagementInterface::class)->placeOrder($quoteId);
    }

    /** Quote item id of the simple product line (the child for configurables) for a SKU. */
    public function quoteItemId(int $quoteId, string $sku): int
    {
        return (int)$this->db->fetchOne(
            "SELECT item_id FROM quote_item WHERE quote_id = ? AND sku = ? AND product_type = 'simple'",
            [$quoteId, $sku]
        );
    }

    public function reservations(array $where): array
    {
        $select = $this->db->select()->from('company_supplier_reservation');
        foreach ($where as $cond => $value) {
            $select->where($cond, $value);
        }
        return $this->db->fetchAll($select->order('reservation_id'));
    }

    public function attempt(int $attemptId): array
    {
        return $this->db->fetchRow('SELECT * FROM company_supplier_placement_attempt WHERE attempt_id = ?', [$attemptId]);
    }

    /** Latest attempt for a quote. */
    public function attemptForQuote(int $quoteId): ?array
    {
        return $this->db->fetchRow(
            'SELECT * FROM company_supplier_placement_attempt WHERE quote_id = ? ORDER BY attempt_id DESC LIMIT 1',
            [$quoteId]
        ) ?: null;
    }

    public function orderCountForQuote(int $quoteId): int
    {
        return (int)$this->db->fetchOne('SELECT COUNT(*) FROM sales_order WHERE quote_id = ?', [$quoteId]);
    }

    /** Order item id by SKU and product type. */
    public function orderItemId(int $orderId, string $sku, string $type = 'simple'): int
    {
        return (int)$this->db->fetchOne(
            'SELECT item_id FROM sales_order_item WHERE order_id = ? AND sku = ? AND product_type = ?',
            [$orderId, $sku, $type]
        );
    }

    public function events(int $inventoryId, string $type): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM company_supplier_reservation_event WHERE inventory_id = ? AND type = ? ORDER BY event_id',
            [$inventoryId, $type]
        );
    }

    /** Snapshot through the real path: accept, then consume. */
    public function snapshot(string $sku, string $qty): int
    {
        $id = $this->accept($sku, $qty);
        $this->om->get(\Company\SupplierSync\Model\Queue\UpdateConsumer::class)->process($id);
        return $id;
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

    /** Create (or reuse) a second supplier, enabled on base, for reassignment scenarios. Returns its code. */
    public function alternateSupplier(): string
    {
        $code = $this->supplierCode . '-alt';
        $repo = $this->om->get(\Company\SupplierSync\Api\SupplierRepositoryInterface::class);
        try {
            $repo->getByCode($code);
        } catch (\Magento\Framework\Exception\NoSuchEntityException) {
            $repo->save($this->om->create(\Company\SupplierSync\Api\Data\SupplierInterface::class)
                ->setCode($code)->setName('Scenario fixture (alternate)')->setIsActive(true));
        }
        $this->om->get(\Company\SupplierSync\Api\SupplierWebsiteManagementInterface::class)->setActive($code, 'base', true);
        return $code;
    }

    /**
     * Delete every module row created by this harness: ledger, updates, assignments, placement attempts (including
     * synthetic quote ids >= 900000 used by direct PlacementService calls) and the fixture suppliers themselves.
     * Orders and quotes stay, like any test order.
     */
    public function cleanup(): void
    {
        $db = $this->db;
        $supplierIds = array_map('intval', $db->fetchCol(
            'SELECT supplier_id FROM company_supplier WHERE code IN (?)',
            [[$this->supplierCode, $this->supplierCode . '-alt']]
        ));
        if ($supplierIds) {
            $inv = $db->fetchCol('SELECT inventory_id FROM company_supplier_inventory WHERE supplier_id IN (?)', [$supplierIds]);
            if ($inv) {
                $attempts = $db->fetchCol('SELECT DISTINCT attempt_id FROM company_supplier_reservation WHERE inventory_id IN (?)', [$inv]);
                $db->delete('company_supplier_reservation_event', ['inventory_id IN (?)' => $inv]);
                $db->delete('company_supplier_reservation', ['inventory_id IN (?)' => $inv]);
                if ($attempts) {
                    $db->delete('company_supplier_placement_attempt', ['attempt_id IN (?)' => $attempts]);
                }
                $db->delete('company_supplier_inventory', ['inventory_id IN (?)' => $inv]);
            }
            $db->delete('company_supplier_update', ['supplier_id IN (?)' => $supplierIds]);
            $db->delete('company_supplier', ['supplier_id IN (?)' => $supplierIds]); // cascades websites, assignments
        }
        // Attempts that never held stock: synthetic quotes and scenario quotes refused before reserving.
        $db->query(
            'DELETE a FROM company_supplier_placement_attempt a'
            . ' LEFT JOIN company_supplier_reservation r ON r.attempt_id = a.attempt_id'
            . ' WHERE r.reservation_id IS NULL AND (a.quote_id BETWEEN 900000 AND 999999'
            . ' OR a.quote_id IN (SELECT entity_id FROM quote WHERE customer_email = ?))',
            ['scenario@example.com']
        );
        $this->supplierId = 0;
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
