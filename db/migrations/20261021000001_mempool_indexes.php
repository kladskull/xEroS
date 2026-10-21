<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class MempoolIndexes extends AbstractMigration
{
    /**
     * The mempool held one transaction per sender key and one per peer, so a second payment was refused until a
     * block took the first. Double spends are still caught by the unique outpoint index on mempool_inputs.
     */
    public function up(): void
    {
        $this->table('mempool_transactions')
            ->removeIndex(['public_key'])
            ->removeIndex(['peer'])
            ->addIndex(['public_key'], ['unique' => false])
            ->addIndex(['peer'], ['unique' => false])
            ->addIndex(['date_created'], ['unique' => false])
            ->update();
    }

    public function down(): void
    {
        $this->table('mempool_transactions')
            ->removeIndex(['public_key'])
            ->removeIndex(['peer'])
            ->removeIndex(['date_created'])
            ->addIndex(['public_key'], ['unique' => true])
            ->addIndex(['peer'], ['unique' => true])
            ->update();
    }
}
