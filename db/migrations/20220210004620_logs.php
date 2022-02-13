<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

final class Logs extends AbstractMigration
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
        $table = $this->table('logs');
        $table
            ->addColumn('data_type', 'text', ['limit' => 32])
            ->addColumn('key', 'text', ['limit' => 256])
            ->addColumn('log_datetime', 'integer', ['signed' => false])
            ->addColumn('data', 'text')
            ->addColumn('message', 'text', ['limit' => 80])
            ->addIndex(['data_type', 'key'], ['unique' => true, 'order' => ['data_type' => 'ASC', 'key' => 'ASC']])
            ->create();
    }
}
