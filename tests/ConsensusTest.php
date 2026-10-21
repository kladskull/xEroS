<?php declare(strict_types=1);

namespace Blockchain;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Each test runs inside a database transaction that is rolled back, so the chain only ever holds the genesis block
 * between tests.
 */
class ConsensusTest extends TestCase
{
    private PDO $db;
    private Block $block;
    private Transaction $transaction;
    private Pow $pow;
    private Script $script;
    private OpenSsl $openSsl;
    private Address $address;
    private array $owner;
    private array $thief;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->beginTransaction();

        $this->block = new Block();
        $this->transaction = new Transaction();
        $this->pow = new Pow();
        $this->script = new Script([]);
        $this->openSsl = new OpenSsl();
        $this->address = new Address();
        $this->owner = $this->openSsl->createRsaKeyPair();
        $this->thief = $this->openSsl->createRsaKeyPair();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /**
     * Finds a nonce that meets the block's difficulty.
     */
    private function mine(array $block): array
    {
        $header = $this->block->generateBlockHeader($block);

        for ($nonce = 0; ; $nonce++) {
            $hash = bin2hex($this->pow->calculate($header, dechex($nonce)));

            if ($this->pow->meetsDifficulty($hash, (int)$block['difficulty'])) {
                $block['nonce'] = dechex($nonce);
                $block['hash'] = $hash;

                return $block;
            }
        }
    }

    /**
     * Mines the next block paying the coinbase to $keys, stores it and makes it active.
     */
    private function mineNextBlock(array $keys): array
    {
        $height = $this->block->getCurrentHeight() + 1;

        // a block must be newer than the median of the blocks before it, so wait for the clock to move on
        while (time() <= (int)$this->block->getCurrent()['date_created']) {
            usleep(100000);
        }

        $candidate = $this->block->getCandidateBlock($height, $keys['public_key'], $keys['private_key']);
        $this->assertNotEmpty($candidate);
        $block = $this->mine($candidate);

        $result = $this->block->validateFullBlock($block);
        $this->assertTrue($result['validated'], $result['reason']);
        $this->assertTrue($this->block->add($block, false, true));
        $this->assertTrue($this->block->acceptBlock($block['block_id'], $height));

        return $block;
    }

    private function coinbaseOf(array $block): array
    {
        foreach ($block['transactions'] as $tx) {
            if ((string)$tx['version'] === TransactionVersion::COINBASE) {
                return $tx;
            }
        }

        $this->fail('block has no coinbase');
    }

    /**
     * Test fixture only: lets a coinbase output be spent before its lock height.
     */
    private function unlock(array $coinbase): void
    {
        $stmt = $this->db->prepare('UPDATE transaction_outputs SET lock_height=0 WHERE transaction_id=:id');
        $stmt->execute([':id' => $coinbase['transaction_id']]);
    }

    private function payToKeyScript(array $keys): string
    {
        $partial = bin2hex($this->address->createPartial($keys['public_key']));

        return $this->script->encodeScript(
            'mov ' . $partial . ',ax;adha ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;vsig ax,<hash>,bx;'
        );
    }

    /**
     * Builds a transfer spending the given outputs, signed by $signer.
     */
    private function transfer(array $outpoints, array $to, string $value, array $signer, ?array $inputSigner = null): array
    {
        $inputSigner ??= $signer;
        $inputs = [];

        foreach ($outpoints as $i => [$transactionId, $txId]) {
            $inputs[] = [
                'tx_id' => (string)$i,
                'previous_transaction_id' => $transactionId,
                'previous_tx_out_id' => (string)$txId,
                'script' => '',
            ];
        }

        $tx = [
            'date_created' => time(),
            'public_key' => $signer['public_key_raw'],
            'peer' => 'test',
            'version' => TransactionVersion::TRANSFER,
            'height' => $this->block->getCurrentHeight() + 1,
            Transaction::INPUTS => $inputs,
            Transaction::OUTPUTS => [
                [
                    'tx_id' => '0',
                    'address' => $this->address->create($to['public_key']),
                    'value' => $value,
                    'script' => $this->payToKeyScript($to),
                    'lock_height' => '0',
                ],
            ],
        ];

        return $this->sign($tx, $signer, $inputSigner);
    }

    /**
     * Signs the inputs with $inputSigner and the transaction with $signer, and sets the id.
     */
    private function sign(array $tx, array $signer, array $inputSigner): array
    {
        $tx['public_key'] = $signer['public_key_raw'];
        $hash = $this->transaction->signatureHash($tx);
        $inputSignature = $this->openSsl->signAndVerifyData(
            $hash,
            $inputSigner['public_key'],
            $inputSigner['private_key']
        );

        foreach ($tx[Transaction::INPUTS] as $i => $txIn) {
            $tx[Transaction::INPUTS][$i]['script'] = $this->script->encodeScript(
                'push ' . $inputSignature . ';push ' . $inputSigner['public_key_raw'] . ';dup;'
            );
        }

        $tx['transaction_id'] = $this->transaction->computeId($tx);
        $tx['signature'] = $this->transaction->signTransaction($tx, $signer['public_key'], $signer['private_key']);

        return $tx;
    }

    /**
     * A funded owner: one active coinbase output of the full block reward, spendable now.
     */
    private function fundOwner(): array
    {
        $coinbase = $this->coinbaseOf($this->mineNextBlock($this->owner));
        $this->unlock($coinbase);

        return $coinbase;
    }

    private function spendAmount(): string
    {
        return bcsub($this->block->getRewardValue(2), Config::getMinimumTransactionFee());
    }

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
        $this->assertTrue($this->block->acceptBlock($competitor['block_id'], 2));

        $this->assertFalse($this->block->isAccepted($first['block_id']));
        $this->assertNull($this->transaction->getUnspentOutput($this->coinbaseOf($first)['transaction_id'], 0));
        $this->assertNotNull(
            $this->transaction->getUnspentOutput($this->coinbaseOf($competitor)['transaction_id'], 0)
        );
    }
}
