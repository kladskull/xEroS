<?php declare(strict_types=1);

namespace Blockchain;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Each test runs inside a database transaction that is rolled back.
 */
class HardeningTest extends TestCase
{
    private PDO $db;
    private Block $block;
    private Transaction $transaction;
    private Script $script;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->beginTransaction();
        $this->block = new Block();
        $this->transaction = new Transaction();
        $this->script = new Script([]);
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function testScriptThatExpandsTooFarIsRejected(): void
    {
        // a couple of hundred compressed bytes that expand to a 100k digit multiplication
        $digits = str_repeat('9', 100000);
        $bomb = $this->script->encodeScript('mov ' . $digits . ',ax;mul ax,' . $digits . ';vtru;');

        $this->assertLessThan(Config::getMaxScriptLength(), strlen($bomb));
        $this->assertFalse($this->transaction->isScriptSizeValid($bomb));
        $this->assertFalse($this->transaction->unlockTransaction(['script' => ''], ['script' => $bomb], 'hash'));
    }

    public function testScriptErrorFailsInsteadOfThrowing(): void
    {
        // bcmul throws a ValueError on a register name
        $script = $this->script->encodeScript('mov 5,ax;mul ax,ax;vtru;');

        $this->assertFalse($this->transaction->unlockTransaction(['script' => ''], ['script' => $script], 'hash'));
    }

    public function testNormalScriptsFitTheLimit(): void
    {
        $keys = (new OpenSsl())->createRsaKeyPair();
        $signature = str_repeat('ab', 256);
        $input = 'push ' . $signature . ';push ' . $keys['public_key_raw'] . ';dup;';
        $output = 'mov ' . str_repeat('cd', 32) . ',ax;adha ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;vsig ax,<hash>,bx;';

        $this->assertLessThanOrEqual(Config::getMaxDecodedScriptLength(), strlen($input . $output));
    }

    /**
     * Inserts a bare block row for difficulty tests.
     */
    private function insertBlock(string $blockId, string $previousId, int $height, int $date, int $difficulty): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO blocks (`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,' .
            '`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan`,`accepted`) VALUES ' .
            "(:n,:b,:p,:d,:h,'0',:diff,'',1,'','',0,1)"
        );
        $stmt->execute([
            ':n' => Config::getNetworkIdentifier(), ':b' => $blockId, ':p' => $previousId,
            ':d' => $date, ':h' => $height, ':diff' => $difficulty,
        ]);

        return $this->block->getByBlockId($blockId);
    }

    public function testDifficultyComesFromTheParentNotTheSibling(): void
    {
        $genesis = $this->block->getByHeight(1);
        $difficulty = (int)$genesis['difficulty'];
        $start = (int)$genesis['date_created'];
        $parent = $genesis;

        // 142 more blocks, one a minute, so the period ran ten times too fast
        for ($height = 2; $height <= 143; $height++) {
            $parent = $this->insertBlock(
                'b' . $height,
                $parent['block_id'],
                $height,
                $start + 60 * ($height - 1),
                $difficulty
            );
        }

        $expected = $difficulty + 1;
        $this->assertEquals($expected, $this->block->getNextDifficulty($parent));

        // an active sibling at height 144 must not change what the next block at 144 needs
        $this->insertBlock('sibling', $parent['block_id'], 144, $start + 999999, $expected);
        $this->assertEquals($expected, $this->block->getNextDifficulty($parent));
    }

    public function testPeerListCannotClearTheBlacklist(): void
    {
        $peer = new Peer();
        $peer->addBlackList('10.1.2.3:7747');
        $peer->add('10.1.2.3:7747');

        $this->assertEquals(1, (int)$peer->getByHostAddress('10.1.2.3:7747')['blacklisted']);
    }
}
