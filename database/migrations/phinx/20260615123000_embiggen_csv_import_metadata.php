<?php

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

class EmbiggenCsvImportMetadata extends AbstractMigration
{
    public function up()
    {
        $this->table('csv')
            ->changeColumn('columns', 'text', [
                'null' => false,
                'limit' => MysqlAdapter::TEXT_LONG,
            ])
            ->changeColumn('maps_to', 'text', [
                'null' => true,
                'default' => null,
                'limit' => MysqlAdapter::TEXT_LONG,
            ])
            ->update();
    }

    public function down()
    {
        // Imported CSV metadata may exceed TEXT capacity after this migration.
    }
}
