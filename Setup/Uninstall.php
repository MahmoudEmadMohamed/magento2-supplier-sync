<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Setup;

use Company\SupplierSync\Model\Placement\Reconciliation;
use Magento\Framework\FlagManager;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Runs on `module:uninstall --remove-data`: removes the module's configuration and cursor flag.
 *
 * The ten module tables are declared in db_schema.xml and dropped by declarative schema itself.
 */
class Uninstall implements UninstallInterface
{
    /**
     * @param FlagManager $flagManager
     */
    public function __construct(private readonly FlagManager $flagManager)
    {
    }

    /**
     * Remove supplier_sync/* configuration and the reconciliation cursor.
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->getConnection()->delete(
            $setup->getTable('core_config_data'),
            ['path LIKE ?' => 'supplier_sync/%']
        );
        $this->flagManager->deleteFlag(Reconciliation::CURSOR_FLAG);
    }
}
