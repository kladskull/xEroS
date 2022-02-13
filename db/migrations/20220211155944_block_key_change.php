<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BlockKeyChange extends AbstractMigration
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
            ->removeIndex(['block_id'])
            ->removeIndex(['previous_block_id'])
            ->addIndex(['hash'], ['unique' => true])
            ->addIndex(['block_id'], ['unique' => false, 'order' => ['block_id' => 'ASC']])
            ->addIndex(['previous_block_id'], ['unique' => false, 'order' => ['previous_block_id' => 'ASC']])
            ->update();
    }
}
