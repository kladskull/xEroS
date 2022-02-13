<?php declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class Accounts extends AbstractMigration
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
        // create the table
        $table = $this->table('accounts');
        $table
            ->addColumn('date_created', 'integer', ['signed' => false])
            ->addColumn('public_key', 'text')
            ->addColumn('private_key', 'text')
            ->addColumn('public_hash', 'text')
            ->addColumn('address', 'text', ['limit' => 64])
            ->addIndex(['address'], ['unique' => true, 'order' => ['address' => 'ASC']])
            ->addIndex(['public_key_raw'], ['unique' => true, 'order' => ['public_key_raw' => 'ASC']])
            ->create();
    }
}
