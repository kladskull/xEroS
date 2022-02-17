<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

final class Peers extends AbstractMigration
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
        $table = $this->table('peers');
        $table
            ->addColumn('address', 'text')
            ->addColumn('reserve', 'integer', ['signed' => false])
            ->addColumn('last_ping', 'integer', ['signed' => false])
            ->addColumn('blacklisted', 'integer', ['signed' => false])
            ->addColumn('fails', 'integer', ['signed' => false])
            ->addColumn('date_created', 'integer', ['signed' => false])
            ->addIndex(['address'], ['unique' => true, 'order' => ['transaction_id' => 'ASC']])
            ->create();
    }
}
