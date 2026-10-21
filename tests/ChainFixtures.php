<?php declare(strict_types=1);

namespace Blockchain;

use PDO;

/**
 * Builds and mines real blocks for tests. Each test runs inside a database transaction that is rolled back, so the
 * chain only ever holds the genesis block between tests.
 */
trait ChainFixtures
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

    private function candidateOn(array $parent, array $keys, array $transactions = []): array
    {
        $candidate = $this->block->getCandidateBlock(
            (int)$parent['height'] + 1,
            $keys['public_key'],
            $keys['private_key']
        );
        $coinbase = $this->coinbaseOf($candidate);
        $all = Transaction::sort(array_merge([$coinbase], $transactions));

        $candidate['previous_block_id'] = $parent['block_id'];
        $candidate['previous_hash'] = $parent['hash'];
        $candidate['date_created'] = max(time(), (int)$parent['date_created'] + 1);
        $candidate['transactions'] = $all;
        $candidate['transaction_count'] = count($all);
        $candidate['merkle_root'] = (new Merkle())->computeMerkleHash($all);
        $candidate['block_id'] = $this->block->generateId(
            Config::getNetworkIdentifier(),
            $parent['block_id'],
            $candidate['date_created'],
            (int)$parent['height'] + 1,
            $candidate['merkle_root']
        );

        return $this->mine($candidate);
    }
}
