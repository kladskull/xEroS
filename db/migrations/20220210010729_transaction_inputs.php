<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TransactionInputs extends AbstractMigration
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
        $table = $this->table('transaction_inputs');
        $table
            ->addColumn('block_id', 'text', ['limit' => 64])
            ->addColumn('transaction_id', 'text', ['limit' => 64])
            ->addColumn('tx_id', 'integer', ['signed' => false])
            ->addColumn('previous_transaction_id', 'text', ['limit' => 64])
            ->addColumn('previous_tx_out_id', 'integer', ['signed' => false])
            ->addColumn('script', 'text')
            ->addIndex(['block_id', 'transaction_id', 'tx_id'], ['unique' => true, 'order' => ['block_id' => 'ASC', 'transaction_id' => 'ASC', 'tx_id' => 'ASC']])
            ->addIndex(['block_id', 'previous_transaction_id', 'previous_tx_out_id'], ['unique' => true, 'order' => ['block_id' => 'ASC', 'previous_transaction_id' => 'ASC', 'previous_tx_out_id' => 'ASC']])
            ->create();
    }
}
