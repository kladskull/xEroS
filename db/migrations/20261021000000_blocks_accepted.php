<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BlocksAccepted extends AbstractMigration
{
    /**
     * Tracks whether a block's spends have been applied to the active chain, so acceptBlock() never applies
     * the same block twice.
     */
    public function change(): void
    {
        $table = $this->table('blocks');
        $table
            ->addColumn('accepted', 'integer', ['signed' => false, 'default' => '0'])
            ->update();
    }
}
