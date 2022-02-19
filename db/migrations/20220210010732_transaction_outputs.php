<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TransactionOutputs extends AbstractMigration
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
        $table = $this->table('transaction_outputs');
        $table
            ->addColumn('block_id', 'text', ['limit' => 64])
            ->addColumn('transaction_id', 'text', ['limit' => 64])
            ->addColumn('tx_id', 'integer', ['signed' => false])
            ->addColumn('address', 'text', ['limit' => 40])
            ->addColumn('value', 'text')
            ->addColumn('script', 'text')
            ->addColumn('lock_height', 'integer', ['signed' => false])
            ->addColumn('spent', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('hash', 'text')
            ->addIndex(['block_id', 'transaction_id', 'tx_id'], ['unique' => true, 'order' => ['block_id', 'transaction_id' => 'ASC', 'tx_id' => 'ASC']])
            ->addIndex(['address', 'spent'], ['unique' => false, 'order' => ['address' => 'ASC', 'spent' => 'ASC']])
            ->create();
    }
}
