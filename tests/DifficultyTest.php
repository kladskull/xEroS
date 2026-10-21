<?php declare(strict_types=1);

namespace Blockchain;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Each test runs inside a database transaction that is rolled back.
 */
class DifficultyTest extends TestCase
{
    private const PERIOD = 144;

    private PDO $db;
    private Block $block;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->beginTransaction();
        $this->block = new Block();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /**
     * Builds bare block rows on top of genesis up to $toHeight, $spacing seconds apart, and returns the tip.
     */
    private function buildChain(int $toHeight, int $spacing, int $difficulty): array
    {
        $parent = $this->block->getByHeight(1);
        $start = (int)$parent['date_created'];
        $stmt = $this->db->prepare(
            'INSERT INTO blocks (`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,' .
            '`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan`,`accepted`) VALUES ' .
            "(:n,:b,:p,:d,:h,'0',:diff,'',1,'','',0,1)"
        );

        for ($height = 2; $height <= $toHeight; $height++) {
            $stmt->execute([
                ':n' => Config::getNetworkIdentifier(), ':b' => 'd' . $height, ':p' => $parent['block_id'],
                ':d' => $start + $spacing * ($height - 1), ':h' => $height, ':diff' => $difficulty,
            ]);
            $parent = $this->block->getByBlockId('d' . $height);
        }

        return $parent;
    }

    public function testFirstPeriodUsesTheGenesisDifficulty(): void
    {
        $genesis = $this->block->getByHeight(1);
        $tip = $this->buildChain(100, 1, 40);

        $this->assertEquals((int)$genesis['difficulty'], $this->block->getNextDifficulty($genesis));
        $this->assertEquals((int)$genesis['difficulty'], $this->block->getNextDifficulty($tip));
    }

    public function testBetweenAdjustmentsTheParentDifficultyCarriesOver(): void
    {
        // 150 is not on a period boundary, so it takes the parent's difficulty even though blocks were fast
        $tip = $this->buildChain(149, 1, 20);

        $this->assertEquals(20, $this->block->getNextDifficulty($tip));
    }

    public function testOnTargetPeriodKeepsTheDifficulty(): void
    {
        $tip = $this->buildChain(self::PERIOD - 1, Config::getDesiredBlockTime(), 20);

        $this->assertEquals(20, $this->block->getNextDifficulty($tip));
    }

    public function testFastPeriodRaisesTheDifficulty(): void
    {
        $tip = $this->buildChain(self::PERIOD - 1, 60, 20);

        $this->assertEquals(21, $this->block->getNextDifficulty($tip));
    }

    public function testSlowPeriodLowersTheDifficulty(): void
    {
        $tip = $this->buildChain(self::PERIOD - 1, Config::getDesiredBlockTime() * 2, 20);

        $this->assertEquals(19, $this->block->getNextDifficulty($tip));
    }

    public function testDifficultyNeverDropsBelowTheDefault(): void
    {
        $tip = $this->buildChain(self::PERIOD - 1, Config::getDesiredBlockTime() * 2, Config::getDefaultDifficulty());

        $this->assertEquals(Config::getDefaultDifficulty(), $this->block->getNextDifficulty($tip));
    }

    public function testDifficultyIsCappedAt255(): void
    {
        $tip = $this->buildChain(self::PERIOD - 1, 1, 255);

        $this->assertEquals(255, $this->block->getNextDifficulty($tip));
    }

    public function testSecondPeriodOnlyLooksAtItsOwnBlocks(): void
    {
        // a slow first period then a fast second one, the second adjustment must only see the fast blocks
        $tip = $this->buildChain(self::PERIOD - 1, Config::getDesiredBlockTime() * 2, 20);
        $start = (int)$tip['date_created'];
        $stmt = $this->db->prepare(
            'INSERT INTO blocks (`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,' .
            '`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan`,`accepted`) VALUES ' .
            "(:n,:b,:p,:d,:h,'0',20,'',1,'','',0,1)"
        );

        for ($height = self::PERIOD; $height < self::PERIOD * 2; $height++) {
            $stmt->execute([
                ':n' => Config::getNetworkIdentifier(), ':b' => 'e' . $height, ':p' => $tip['block_id'],
                ':d' => $start + 60 * ($height - self::PERIOD + 1), ':h' => $height,
            ]);
            $tip = $this->block->getByBlockId('e' . $height);
        }

        $this->assertEquals(21, $this->block->getNextDifficulty($tip));
    }

    public function testMissingAncestorKeepsTheParentDifficulty(): void
    {
        $tip = $this->buildChain(self::PERIOD - 1, 60, 20);
        $this->db->exec("DELETE FROM blocks WHERE block_id='d50'");

        $this->assertEquals(20, $this->block->getNextDifficulty($tip));
    }
}
