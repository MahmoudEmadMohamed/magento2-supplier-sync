<?php
/**
 * Copyright © Company. All rights reserved.
 */
declare(strict_types=1);

namespace Company\SupplierSync\Test\Unit\Setup;

use Company\SupplierSync\Model\Placement\Reconciliation;
use Company\SupplierSync\Setup\Uninstall;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use PHPUnit\Framework\TestCase;

class UninstallTest extends TestCase
{
    /**
     * Uninstall removes the module's configuration rows and its cursor flag.
     *
     * @return void
     */
    public function testRemovesConfigurationAndCursor(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('delete')
            ->with('core_config_data', ['path LIKE ?' => 'supplier_sync/%']);
        $setup = $this->createStub(SchemaSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);
        $flags = $this->createMock(FlagManager::class);
        $flags->expects($this->once())->method('deleteFlag')->with(Reconciliation::CURSOR_FLAG);

        (new Uninstall($flags))->uninstall($setup, $this->createStub(ModuleContextInterface::class));
    }
}
