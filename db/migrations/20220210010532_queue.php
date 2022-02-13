<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

final class Queue extends AbstractMigration
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
        $table = $this->table('queue');
        $table
            ->addColumn('date_created', 'integer', ['signed' => false])
            ->addColumn('command', 'text', ['limit' => 32])
            ->addColumn('data', 'text')
            ->addColumn('trys', 'integer', ['signed' => false])
            ->addIndex(['date_created'], ['unique' => false, 'order' => ['date_created' => 'ASC']])
            ->create();
    }
}
