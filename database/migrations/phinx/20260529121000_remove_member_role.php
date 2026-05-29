<?php

use Phinx\Migration\AbstractMigration;

class RemoveMemberRole extends AbstractMigration
{
    public function up()
    {
        $this->execute("DELETE FROM roles_permissions WHERE role = 'user'");

        $this->execute("DELETE form_roles
            FROM form_roles
            JOIN roles ON roles.id = form_roles.role_id
            WHERE roles.name = 'user'
        ");

        $this->execute("DELETE FROM roles WHERE name = 'user'");

        $this->table('users')
            ->changeColumn('role', 'string', [
                'limit' => 50,
                'null' => true,
                'default' => 'field_monitor'
            ])
            ->update();
    }

    public function down()
    {
        $this->execute("INSERT INTO roles (name, display_name, description, protected)
            SELECT 'user', 'Member', 'Registered member', 1
            WHERE NOT EXISTS (
                SELECT 1 FROM roles WHERE name = 'user'
            )
        ");

        $this->table('users')
            ->changeColumn('role', 'string', [
                'limit' => 50,
                'null' => true,
                'default' => 'user'
            ])
            ->update();
    }
}
