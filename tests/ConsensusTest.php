<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;

/**
 * Each test runs inside a database transaction that is rolled back, so the chain only ever holds the genesis block
 * between tests.
 */
class ConsensusTest extends TestCase
{
    use ChainFixtures;

    /*
     * Proof of work
     */

    public function testLeadingZeroBits(): void
    {
        $this->assertEquals(16, $this->pow->leadingZeroBits('0000ffff' . str_repeat('f', 56)));
        $this->assertEquals(19, $this->pow->leadingZeroBits('00001fff' . str_repeat('f', 56)));
        $this->assertEquals(0, $this->pow->leadingZeroBits(str_repeat('f', 64)));
    }

    public function testBlockWithoutWorkIsRejected(): void
    {
        $candidate = $this->block->getCandidateBlock(2, $this->owner['public_key'], $this->owner['private_key']);
        $header = $this->block->generateBlockHeader($candidate);

        // a correct hash for the header that does not meet the difficulty
        for ($nonce = 0; ; $nonce++) {
            $hash = bin2hex($this->pow->calculate($header, dechex($nonce)));

            if (!$this->pow->meetsDifficulty($hash, (int)$candidate['difficulty'])) {
                break;
            }
        }

        $candidate['nonce'] = dechex($nonce);
        $candidate['hash'] = $hash;
        $result = $this->block->validateFullBlock($candidate);

        $this->assertFalse($result['validated']);
        $this->assertStringContainsString('does not meet the difficulty', $result['reason']);
    }

    public function testMinedBlockIsAccepted(): void
    {
        $block = $this->mineNextBlock($this->owner);

        $this->assertEquals(2, $this->block->getCurrentHeight());
        $this->assertTrue($this->block->isAccepted($block['block_id']));
    }

    /*
     * Blocks commit to transaction contents
     */

    public function testChangingAnOutputBreaksTheBlock(): void
    {
        $candidate = $this->block->getCandidateBlock(2, $this->owner['public_key'], $this->owner['private_key']);
        $block = $this->mine($candidate);

        // redirect the reward without changing the transaction id, merkle root or proof of work
        foreach ($block['transactions'] as $i => $tx) {
            $block['transactions'][$i][Transaction::OUTPUTS][0]['address'] =
                $this->address->create($this->thief['public_key']);
        }

        $result = $this->block->validateFullBlock($block);

        $this->assertFalse($result['validated']);
        $this->assertStringContainsString('transaction_id does not match', $result['reason']);
    }

    public function testCoinbaseCannotClaimMoreThanTheReward(): void
    {
        $candidate = $this->block->getCandidateBlock(2, $this->owner['public_key'], $this->owner['private_key']);
        $coinbase = $candidate['transactions'][0];
        $coinbase[Transaction::OUTPUTS][0]['value'] = bcadd($coinbase[Transaction::OUTPUTS][0]['value'], '1');
        $coinbase['transaction_id'] = $this->transaction->computeId($coinbase);
        $coinbase['signature'] = $this->transaction->signTransaction(
            $coinbase,
            $this->owner['public_key'],
            $this->owner['private_key']
        );
        $candidate['transactions'] = [$coinbase];
        $candidate['merkle_root'] = (new Merkle())->computeMerkleHash([$coinbase]);
        $candidate['block_id'] = $this->block->generateId(
            Config::getNetworkIdentifier(),
            $candidate['previous_block_id'],
            $candidate['date_created'],
            2,
            $candidate['merkle_root']
        );

        $result = $this->block->validateFullBlock($this->mine($candidate));

        $this->assertFalse($result['validated']);
        $this->assertStringContainsString('greater than expected', $result['reason']);
    }

    public function testBlockTimeMustPassTheMedian(): void
    {
        $genesis = $this->block->getByHeight(1);
        $candidate = $this->block->getCandidateBlock(2, $this->owner['public_key'], $this->owner['private_key']);

        // rebuild the block id for an old time, everything else stays valid
        $candidate['date_created'] = (int)$genesis['date_created'];
        $candidate['block_id'] = $this->block->generateId(
            Config::getNetworkIdentifier(),
            $candidate['previous_block_id'],
            $candidate['date_created'],
            2,
            $candidate['merkle_root']
        );

        $result = $this->block->validateFullBlock($this->mine($candidate));

        $this->assertFalse($result['validated']);
        $this->assertStringContainsString('median', $result['reason']);
    }

    /*
     * Spending
     */

    public function testOwnerCanSpend(): void
    {
        $coinbase = $this->fundOwner();
        $tx = $this->transfer([[$coinbase['transaction_id'], 0]], $this->thief, $this->spendAmount(), $this->owner);

        $result = $this->transaction->validate($tx, 3);

        $this->assertTrue($result['validated'], $result['reason']);
        $this->assertEquals(Config::getMinimumTransactionFee(), $result['fee']);
    }

    public function testOtherKeyCannotSpend(): void
    {
        $coinbase = $this->fundOwner();
        $tx = $this->transfer([[$coinbase['transaction_id'], 0]], $this->thief, $this->spendAmount(), $this->thief);

        $result = $this->transaction->validate($tx, 3);

        $this->assertFalse($result['validated']);
        $this->assertStringContainsString('cannot unlock', $result['reason']);
    }

    public function testCopiedInputScriptCannotRedirectTheMoney(): void
    {
        $coinbase = $this->fundOwner();
        $honest = $this->transfer([[$coinbase['transaction_id'], 0]], $this->owner, $this->spendAmount(), $this->owner);

        // the thief copies the owner's input script and pays themselves instead
        $stolen = $honest;
        $stolen[Transaction::OUTPUTS][0]['address'] = $this->address->create($this->thief['public_key']);
        $stolen[Transaction::OUTPUTS][0]['script'] = $this->payToKeyScript($this->thief);
        $stolen['public_key'] = $this->thief['public_key_raw'];
        $stolen['transaction_id'] = $this->transaction->computeId($stolen);
        $stolen['signature'] = $this->transaction->signTransaction(
            $stolen,
            $this->thief['public_key'],
            $this->thief['private_key']
        );

        $result = $this->transaction->validate($stolen, 3);

        $this->assertFalse($result['validated']);
        $this->assertStringContainsString('cannot unlock', $result['reason']);
    }

    public function testInputScriptCannotForceSuccess(): void
    {
        $coinbase = $this->fundOwner();
        $tx = $this->transfer([[$coinbase['transaction_id'], 0]], $this->thief, $this->spendAmount(), $this->thief);
        $tx[Transaction::INPUTS][0]['script'] = $this->script->encodeScript('vtru;');
        $tx['transaction_id'] = $this->transaction->computeId($tx);
        $tx['signature'] = $this->transaction->signTransaction($tx, $this->thief['public_key'], $this->thief['private_key']);

        $result = $this->transaction->validate($tx, 3);

        $this->assertFalse($result['validated']);
        $this->assertStringContainsString('cannot unlock', $result['reason']);
    }

    public function testSameOutputTwiceIsRejected(): void
    {
        $coinbase = $this->fundOwner();
        $outpoint = [$coinbase['transaction_id'], 0];
        $double = bcmul($this->spendAmount(), '2');
        $tx = $this->transfer([$outpoint, $outpoint], $this->owner, $double, $this->owner);

        $result = $this->transaction->validate($tx, 3);

        $this->assertFalse($result['validated']);
        $this->assertStringContainsString('spent twice', $result['reason']);
    }

    public function testMissingOutputIsRejected(): void
    {
        $coinbase = $this->fundOwner();
        $tx = $this->transfer([[$coinbase['transaction_id'], 5]], $this->owner, '1', $this->owner);

        $result = $this->transaction->validate($tx, 3);

        $this->assertFalse($result['validated']);
        $this->assertStringContainsString('does not exist', $result['reason']);
    }

    public function testCoinbaseIsLocked(): void
    {
        $coinbase = $this->coinbaseOf($this->mineNextBlock($this->owner));
        $tx = $this->transfer([[$coinbase['transaction_id'], 0]], $this->owner, $this->spendAmount(), $this->owner);

        $result = $this->transaction->validate($tx, 3);

        $this->assertFalse($result['validated']);
        $this->assertStringContainsString('locked until height', $result['reason']);
    }

    public function testSpendGoesThroughTheMempoolIntoABlock(): void
    {
        $coinbase = $this->fundOwner();
        $tx = $this->transfer([[$coinbase['transaction_id'], 0]], $this->thief, $this->spendAmount(), $this->owner);
        $mempool = new Mempool();

        $this->assertTrue($mempool->add($tx));
        // a second spend of the same output is refused
        $again = $this->transfer([[$coinbase['transaction_id'], 0]], $this->owner, $this->spendAmount(), $this->owner);
        $this->assertFalse($mempool->add($again));

        $block = $this->mineNextBlock($this->owner);

        // the block carries the transfer and the miner collected the fee
        $this->assertEquals(2, count($block['transactions']));
        $reward = $this->coinbaseOf($block)[Transaction::OUTPUTS][0]['value'];
        $this->assertEquals(bcadd($this->block->getRewardValue(3), Config::getMinimumTransactionFee()), $reward);

        // the spent output is gone and the new one is live
        $this->assertNull($this->transaction->getUnspentOutput($coinbase['transaction_id'], 0));
        $this->assertNotNull($this->transaction->getUnspentOutput($tx['transaction_id'], 0));
        $this->assertEquals(0, $mempool->getMempoolCount(10));

        // spending it again fails
        $result = $this->transaction->validate($tx, 4);
        $this->assertFalse($result['validated']);
    }

    public function testStoredScriptsSurviveTheDatabase(): void
    {
        $block = $this->mineNextBlock($this->owner);
        $coinbase = $this->coinbaseOf($block);
        $stored = $this->transaction->getUnspentOutput($coinbase['transaction_id'], 0);

        $this->assertEquals($coinbase[Transaction::OUTPUTS][0]['script'], $stored['script']);
    }

    /*
     * Script engine
     */

    public function testFailedVerificationStopsTheScript(): void
    {
        $script = new Script([]);
        $script->loadScript('vfal;vtru;');

        $this->assertFalse($script->run(false));
    }

    public function testVsigUsesTheSignatureResult(): void
    {
        $hash = str_repeat('ab', 32);
        $signature = $this->openSsl->signAndVerifyData($hash, $this->owner['public_key'], $this->owner['private_key']);

        $good = new Script(['<hash>' => $hash]);
        $good->loadScript('push ' . $signature . ';push ' . $this->owner['public_key_raw'] . ';pop ax;pop bx;vsig ax,<hash>,bx;');
        $this->assertTrue($good->run(false));

        $bad = new Script(['<hash>' => $hash]);
        $bad->loadScript('push ' . $signature . ';push ' . $this->thief['public_key_raw'] . ';pop ax;pop bx;vsig ax,<hash>,bx;');
        $this->assertFalse($bad->run(false));
    }

    /*
     * Fork choice
     */

    public function testForkChoiceFollowsWork(): void
    {
        $genesis = $this->block->getByHeight(1);
        $weak = [
            'block_id' => 'aa', 'previous_block_id' => $genesis['block_id'], 'height' => 2,
            'difficulty' => 16, 'hash' => '0000' . str_repeat('0', 60),
        ];
        $strong = [
            'block_id' => 'bb', 'previous_block_id' => $genesis['block_id'], 'height' => 2,
            'difficulty' => 17, 'hash' => '0000' . str_repeat('f', 60),
        ];

        $this->assertEquals('bb', $this->block->blockSelector($weak, $strong)['block_id']);
        $this->assertEquals('bb', $this->block->blockSelector($strong, $weak)['block_id']);
    }

    public function testForkTieGoesToTheLowerHash(): void
    {
        $genesis = $this->block->getByHeight(1);
        $low = [
            'block_id' => 'aa', 'previous_block_id' => $genesis['block_id'], 'height' => 2,
            'difficulty' => 16, 'hash' => '00001' . str_repeat('0', 59),
        ];
        $high = [
            'block_id' => 'bb', 'previous_block_id' => $genesis['block_id'], 'height' => 2,
            'difficulty' => 16, 'hash' => '00002' . str_repeat('0', 59),
        ];

        $this->assertEquals('aa', $this->block->blockSelector($high, $low)['block_id']);
        $this->assertEquals('aa', $this->block->blockSelector($low, $high)['block_id']);
    }

    public function testAcceptingACompetingBlockReversesTheOther(): void
    {
        $first = $this->mineNextBlock($this->owner);

        // a competing block at the same height from another miner
        $candidate = $this->block->getCandidateBlock(2, $this->thief['public_key'], $this->thief['private_key']);
        $competitor = $this->mine($candidate);
        $this->assertTrue($this->block->add($competitor, false, true));

        // a sibling can't be accepted directly, only through a reorganize
        $this->assertFalse($this->block->acceptBlock($competitor['block_id'], 2));
        $this->assertTrue($this->block->reorganize($this->block->getByBlockId($competitor['block_id'])));

        $this->assertFalse($this->block->isAccepted($first['block_id']));
        $this->assertNull($this->transaction->getUnspentOutput($this->coinbaseOf($first)['transaction_id'], 0));
        $this->assertNotNull(
            $this->transaction->getUnspentOutput($this->coinbaseOf($competitor)['transaction_id'], 0)
        );
    }

    /**
     * Rebuilds a candidate on top of a given parent, so tests can grow a side branch.
     */
    public function testReorganizeSwitchesToTheHeavierBranch(): void
    {
        $genesis = $this->block->getByHeight(1);
        $main = $this->mineNextBlock($this->owner);

        // a two block branch from genesis beats the one block chain
        $side2 = $this->candidateOn($genesis, $this->thief);
        $this->assertTrue($this->block->validateFullBlock($side2, false)['validated']);
        $this->assertTrue($this->block->add($side2, false, true));
        $side3 = $this->candidateOn($this->block->getByBlockId($side2['block_id']), $this->thief);
        $this->assertTrue($this->block->validateFullBlock($side3, false)['validated']);
        $this->assertTrue($this->block->add($side3, false, true));

        $this->assertTrue($this->block->reorganize($this->block->getByBlockId($side3['block_id'])));

        $this->assertEquals(3, $this->block->getCurrentHeight());
        $this->assertEquals($side3['block_id'], $this->block->getCurrent()['block_id']);
        $this->assertFalse($this->block->isAccepted($main['block_id']));
        $this->assertNull($this->transaction->getUnspentOutput($this->coinbaseOf($main)['transaction_id'], 0));
        $this->assertNotNull($this->transaction->getUnspentOutput($this->coinbaseOf($side2)['transaction_id'], 0));
    }

    public function testFailedReorganizeKeepsTheOldChain(): void
    {
        $genesis = $this->block->getByHeight(1);
        $coinbase = $this->fundOwner();
        $main = $this->block->getCurrent();

        // the branch spends an output that only exists on the main chain
        $spend = $this->transfer([[$coinbase['transaction_id'], 0]], $this->owner, $this->spendAmount(), $this->owner);
        $side2 = $this->candidateOn($genesis, $this->thief);
        $this->assertTrue($this->block->add($side2, false, true));
        $side3 = $this->candidateOn($this->block->getByBlockId($side2['block_id']), $this->thief, [$spend]);
        $this->assertTrue($this->block->validateFullBlock($side3, false)['validated']);
        $this->assertTrue($this->block->add($side3, false, true));

        $this->assertFalse($this->block->reorganize($this->block->getByBlockId($side3['block_id'])));
        $this->assertTrue($this->block->validateFullBlock($this->block->assembleFullBlock($side2['block_id']), false)['validated']);

        $this->assertEquals($main['block_id'], $this->block->getCurrent()['block_id']);
        $this->assertTrue($this->block->isAccepted($main['block_id']));
        $this->assertNotNull($this->transaction->getUnspentOutput($coinbase['transaction_id'], 0));
    }

    public function testStoredBlockStillValidates(): void
    {
        $block = $this->mineNextBlock($this->owner);
        $reloaded = $this->block->assembleFullBlock($block['block_id']);

        $this->assertEquals($block['nonce'], $reloaded['nonce']);
        $result = $this->block->validateFullBlock($reloaded, false);
        $this->assertTrue($result['validated'], $result['reason']);
    }
}
