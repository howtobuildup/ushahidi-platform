<?php

use Phinx\Migration\AbstractMigration;

class CreateUserFormAssignments extends AbstractMigration
{
    public function up()
    {
        // Which surveys a user may work with. Only consulted for the roles that
        // are restricted, so a row here is a grant rather than a limit: the
        // roles that see everything have no rows at all.
        //
        // Kept beside partner_field_monitors rather than folded into it. That
        // table answers "whose submissions may this partner see", which is a
        // different question from "which surveys may this account open", and a
        // field monitor needs the second without having the first.
        $this->table('user_forms')
            ->addColumn('user_id', 'integer')
            ->addColumn('form_id', 'integer')
            ->addColumn('created', 'integer', ['default' => 0])
            ->addIndex(['user_id', 'form_id'], ['unique' => true, 'name' => 'idx_user_forms_user_form'])
            ->addIndex(['user_id'], ['name' => 'idx_user_forms_user'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('form_id', 'forms', 'id', ['delete' => 'CASCADE'])
            ->create();
    }

    public function down()
    {
        $this->table('user_forms')->drop()->save();
    }
}
