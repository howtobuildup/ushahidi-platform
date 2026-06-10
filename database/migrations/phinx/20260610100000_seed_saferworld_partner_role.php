<?php

use Phinx\Migration\AbstractMigration;

class SeedSaferworldPartnerRole extends AbstractMigration
{
    private $roleName = 'saferworld_partner';

    public function up()
    {
        $description = 'Partner and consortium members can review only submissions and dashboard data assigned to their account.';
        $role = $this->fetchRow("SELECT COUNT(*) FROM roles WHERE name = '{$this->roleName}'", 0);

        if (!$role[0]) {
            $this->execute("INSERT INTO roles (name, display_name, description, protected)
                VALUES (
                    '{$this->roleName}',
                    'Saferworld Partner',
                    '$description',
                    1
                )
            ");
        }

        $this->execute("UPDATE roles
            SET
                display_name = 'Saferworld Partner',
                description = '$description',
                protected = 1
            WHERE name = '{$this->roleName}'
        ");

        // Partner access is enforced by ownership-aware authorizers and queries.
        // Broad permissions such as Manage Posts would expose other partners' data.
        $this->execute("DELETE FROM roles_permissions WHERE role = '{$this->roleName}'");
    }

    public function down()
    {
        $this->execute("DELETE FROM roles_permissions WHERE role = '{$this->roleName}'");
        $this->execute("DELETE FROM roles WHERE name = '{$this->roleName}'");
    }
}
