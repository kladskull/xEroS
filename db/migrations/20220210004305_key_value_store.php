<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Util\Literal;

final class KeyValueStore extends AbstractMigration
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
        $table = $this->table('key_value_store');
        $table
            ->addColumn('key', 'text', ['limit' => 128])
            ->addColumn('expires', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('data', 'text')
            ->addIndex(['key'], ['unique' => true, 'order' => ['key' => 'ASC']])
            ->create();
    }
}
