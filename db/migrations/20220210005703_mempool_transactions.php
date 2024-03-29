<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

final class MempoolTransactions extends AbstractMigration
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
        $table = $this->table('mempool_transactions');
        $table
            ->addColumn('transaction_id', 'text', ['limit' => 64])
            ->addColumn('date_created', 'integer', ['signed' => false])
            ->addColumn('peer', 'text', ['limit' => 64])
            ->addColumn('height', 'integer', ['signed' => false])
            ->addColumn('version', 'text', ['limit' => 2])
            ->addColumn('signature', 'text')
            ->addColumn('public_key', 'text')
            ->addIndex(['transaction_id'], ['unique' => true, 'order' => ['transaction_id' => 'ASC']])
            ->addIndex(['public_key'], ['unique' => true, 'order' => ['public_key' => 'ASC']])
            ->addIndex(['peer'], ['unique' => true, 'order' => ['peer' => 'ASC']])
            ->create();
    }
}
