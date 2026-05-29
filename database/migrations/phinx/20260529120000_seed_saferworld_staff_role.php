<?php

use Phinx\Migration\AbstractMigration;

class SeedSaferworldStaffRole extends AbstractMigration
{
    private $roleName = 'saferworld_staff';

    private $permissions = [
        'Manage Posts',
        'Manage Collections and Saved Searches',
        'Bulk Data Import and Export',
    ];

    public function up()
    {
        $description = 'Saferworld monitoring and review staff can review submissions, dashboards, and reports.';
        $role = $this->fetchRow("SELECT COUNT(*) FROM roles WHERE name = '{$this->roleName}'", 0);

        if (!$role[0]) {
            $this->execute("INSERT INTO roles (name, display_name, description, protected)
                VALUES (
                    '{$this->roleName}',
                    'Saferworld Staff',
                    '$description',
                    1
                )
            ");
        }

        $this->execute("UPDATE roles
            SET
                display_name = 'Saferworld Staff',
                description = '$description',
                protected = 1
            WHERE name = '{$this->roleName}'
        ");

        foreach ($this->permissions as $permission) {
            $this->execute("INSERT INTO roles_permissions (role, permission)
                SELECT '{$this->roleName}', '$permission'
                WHERE EXISTS (
                    SELECT 1 FROM permissions WHERE name = '$permission'
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM roles_permissions
                    WHERE role = '{$this->roleName}'
                    AND permission = '$permission'
                )
            ");
        }
    }

    public function down()
    {
        $this->execute("DELETE FROM roles_permissions WHERE role = '{$this->roleName}'");
        $this->execute("DELETE FROM roles WHERE name = '{$this->roleName}'");
    }
}
