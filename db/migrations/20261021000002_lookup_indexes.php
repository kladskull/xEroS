<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class LookupIndexes extends AbstractMigration
{
    /**
     * Spending an output looks it up by transaction id and output number. The existing indexes start with
     * block_id, so every lookup scanned the whole table.
     */
    public function change(): void
    {
        $this->table('transaction_outputs')
            ->addIndex(['transaction_id', 'tx_id'], ['unique' => false])
            ->update();

        $this->table('transaction_inputs')
            ->addIndex(['previous_transaction_id', 'previous_tx_out_id'], ['unique' => false])
            ->update();

        $this->table('transactions')
            ->addIndex(['transaction_id'], ['unique' => false])
            ->update();
    }
}
