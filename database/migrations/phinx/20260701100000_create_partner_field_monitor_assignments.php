<?php

use Phinx\Migration\AbstractMigration;

class CreatePartnerFieldMonitorAssignments extends AbstractMigration
{
    public function up()
    {
        $this->table('post_varchar')
            ->addIndex(
                ['post_id', 'form_attribute_id'],
                ['name' => 'idx_post_varchar_post_attribute']
            )
            ->update();

        $this->table('field_monitors')
            ->addColumn('code', 'string', ['limit' => 50])
            ->addColumn('district', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('active', 'boolean', ['default' => true])
            ->addColumn('created', 'integer', ['default' => 0])
            ->addColumn('updated', 'integer', ['null' => true])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        $this->table('partner_field_monitors')
            ->addColumn('partner_user_id', 'integer')
            ->addColumn('field_monitor_id', 'integer')
            ->addColumn('created', 'integer', ['default' => 0])
            ->addIndex(['partner_user_id', 'field_monitor_id'], ['unique' => true])
            ->addForeignKey('partner_user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('field_monitor_id', 'field_monitors', 'id', ['delete' => 'CASCADE'])
            ->create();

        $now = time();
        $this->table('field_monitors')->insert([
            ['code' => 'Laas_01', 'district' => "laas'anod", 'active' => 1, 'created' => $now],
            ['code' => 'Laas_02', 'district' => "laas'anod", 'active' => 1, 'created' => $now],
            ['code' => 'Laas_03', 'district' => "laas'anod", 'active' => 1, 'created' => $now],
            ['code' => 'Laas_04', 'district' => "laas'anod", 'active' => 1, 'created' => $now],
            ['code' => 'Laas_05', 'district' => "laas'anod", 'active' => 1, 'created' => $now],
            ['code' => 'Ceri_01', 'district' => 'erigavo', 'active' => 1, 'created' => $now],
            ['code' => 'Ceri_02', 'district' => 'erigavo', 'active' => 1, 'created' => $now],
            ['code' => 'Ceri_03', 'district' => 'erigavo', 'active' => 1, 'created' => $now],
            ['code' => 'Ceri_04', 'district' => 'erigavo', 'active' => 1, 'created' => $now],
            ['code' => 'Ceri_05', 'district' => 'erigavo', 'active' => 1, 'created' => $now],
        ])->saveData();

        $this->execute("UPDATE users
            SET role = 'saferworld_partner'
            WHERE email = 'waapoorganization@gmail.com'");
        $this->execute("INSERT INTO partner_field_monitors
                (partner_user_id, field_monitor_id, created)
            SELECT users.id, field_monitors.id, $now
            FROM users
            CROSS JOIN field_monitors
            WHERE users.email = 'waapoorganization@gmail.com'");
    }

    public function down()
    {
        $this->table('partner_field_monitors')->drop()->save();
        $this->table('field_monitors')->drop()->save();
        $this->table('post_varchar')
            ->removeIndexByName('idx_post_varchar_post_attribute')
            ->update();
    }
}
