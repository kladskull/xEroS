<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Blocks extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        $table = $this->table('blocks');
        $table
            ->addColumn('network_id', 'text', ['limit' => 4])
            ->addColumn('block_id', 'text', ['limit' => 64])
            ->addColumn('previous_block_id', 'text', ['limit' => 64])
            ->addColumn('date_created', 'integer', ['signed' => false])
            ->addColumn('height', 'integer', ['signed' => false])
            ->addColumn('nonce', 'text', ['limit' => '128'])
            ->addColumn('difficulty', 'integer', ['signed' => false])
            ->addColumn('merkle_root', 'text', ['limit' => 64])
            ->addColumn('transactions', 'integer', ['signed' => false])
            ->addColumn('previous_hash', 'text', ['limit' => 64])
            ->addColumn('hash', 'text', ['limit' => 64])
            ->addColumn('orphan', 'integer', ['signed' => false, 'default' => '0'])
            ->addIndex(['block_id'], ['unique' => true, 'order' => ['block_id' => 'ASC']])
            ->addIndex(['previous_block_id'], ['unique' => true, 'order' => ['previous_block_id' => 'ASC']])
            ->addIndex(['height'], ['unique' => false, 'order' => ['height' => 'ASC']])
            ->create();
    }
}
