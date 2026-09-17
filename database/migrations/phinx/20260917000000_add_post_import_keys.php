<?php

use Phinx\Migration\AbstractMigration;

class AddPostImportKeys extends AbstractMigration
{
    public function up()
    {
        // Records which source row a post came from, so re-running an import
        // skips rows already present instead of appending a second copy. Kept
        // in its own table rather than a column on posts: the key only exists
        // for imported posts, and this avoids altering a large hot table.
        //
        // The unique index is what actually enforces idempotency. The lookup in
        // the importer is an optimisation on top of it, not the guarantee.
        $this->table('post_import_keys')
            ->addColumn('post_id', 'integer')
            ->addColumn('form_id', 'integer')
            ->addColumn('source_uuid', 'string', ['limit' => 255])
            ->addColumn('created', 'integer', ['default' => 0])
            ->addIndex(
                ['form_id', 'source_uuid'],
                ['unique' => true, 'name' => 'idx_post_import_keys_form_uuid']
            )
            ->addIndex(['post_id'], ['name' => 'idx_post_import_keys_post'])
            ->addForeignKey('post_id', 'posts', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('form_id', 'forms', 'id', ['delete' => 'CASCADE'])
            ->create();

        // Rows the importer recognised and left alone. Distinct from errors:
        // a skip is the expected outcome of re-importing, not a failure.
        $this->table('csv')
            ->addColumn('skipped', 'integer', ['null' => true, 'default' => 0])
            ->update();
    }

    public function down()
    {
        $this->table('csv')->removeColumn('skipped')->update();
        $this->table('post_import_keys')->drop()->save();
    }
}
