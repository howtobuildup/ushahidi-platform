<?php

use Phinx\Migration\AbstractMigration;

class SeedFieldMonitorRole extends AbstractMigration
{
    public function up()
    {
        $description = 'EWER Champions / Field Monitors can register incidents and submit data entry forms only.';
        $role = $this->fetchRow("SELECT COUNT(*) FROM roles WHERE name = 'field_monitor'", 0);
        $permissionDescription = 'Create and submit incident data entry forms only.';

        if (! $role[0]) {
            $this->execute("INSERT INTO roles (name, display_name, description, protected)
                VALUES (
                    'field_monitor',
                    'Field Monitor',
                    '$description',
                    1
                )
            ");
        }

        $this->execute("UPDATE roles
            SET
                display_name = 'Field Monitor',
                description = '$description',
                protected = 1
            WHERE name = 'field_monitor'
        ");

        $this->execute("INSERT INTO permissions (name, description)
            SELECT 'Submit Posts', '$permissionDescription'
            WHERE NOT EXISTS (
                SELECT 1 FROM permissions WHERE name = 'Submit Posts'
            )
        ");

        $this->execute("INSERT INTO roles_permissions (role, permission)
            SELECT 'field_monitor', 'Submit Posts'
            WHERE NOT EXISTS (
                SELECT 1
                FROM roles_permissions
                WHERE role = 'field_monitor'
                AND permission = 'Submit Posts'
            )
        ");

        $this->execute("INSERT INTO form_roles (form_id, role_id)
            SELECT forms.id, roles.id
            FROM forms
            JOIN roles ON roles.name = 'field_monitor'
            WHERE (
                LOWER(forms.name) LIKE '%incident%'
                OR LOWER(forms.description) LIKE '%incident%'
            )
            AND NOT EXISTS (
                SELECT 1
                FROM form_roles
                WHERE form_roles.form_id = forms.id
                AND form_roles.role_id = roles.id
            )
        ");
    }

    public function down()
    {
        $this->execute("DELETE form_roles
            FROM form_roles
            JOIN roles ON roles.id = form_roles.role_id
            WHERE roles.name = 'field_monitor'
        ");
        $this->execute("DELETE FROM roles_permissions WHERE role = 'field_monitor'");
        $this->execute("DELETE FROM roles_permissions WHERE permission = 'Submit Posts'");
        $this->execute("DELETE FROM permissions WHERE name = 'Submit Posts'");
        $this->execute("DELETE FROM roles WHERE name = 'field_monitor'");
    }
}
