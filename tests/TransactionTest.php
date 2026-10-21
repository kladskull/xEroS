<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;

/**
 * Each case breaks one rule of an otherwise valid transaction, signs it again and checks it is refused for that
 * reason.
 */
class TransactionTest extends TestCase
{
    use ChainFixtures;

    private function validTransfer(): array
    {
        $coinbase = $this->fundOwner();

        return $this->transfer([[$coinbase['transaction_id'], 0]], $this->thief, $this->spendAmount(), $this->owner);
    }

    private function validCoinbase(): array
    {
        $height = $this->block->getCurrentHeight() + 1;
        $candidate = $this->block->getCandidateBlock($height, $this->owner['public_key'], $this->owner['private_key']);

        return $this->coinbaseOf($candidate);
    }

    private function resign(array $tx): array
    {
        return $this->sign($tx, $this->owner, $this->owner);
    }

    private function assertRefused(array $tx, string $reason, ?int $height = null): void
    {
        $result = $this->transaction->validate($tx, $height);

        $this->assertFalse($result['validated'], 'expected: ' . $reason);
        $this->assertStringContainsString($reason, $result['reason']);
    }

    public function testValidTransferAndCoinbasePass(): void
    {
        $transfer = $this->validTransfer();
        $result = $this->transaction->validate($transfer);
        $this->assertTrue($result['validated'], $result['reason']);

        $coinbase = $this->validCoinbase();
        $result = $this->transaction->validate($coinbase, $this->block->getCurrentHeight() + 1);
        $this->assertTrue($result['validated'], $result['reason']);
    }

    public function testRequiredFields(): void
    {
        $tx = $this->validTransfer();

        foreach (['transaction_id', 'date_created', 'public_key', 'signature', 'version', 'height', 'peer'] as $field) {
            $broken = $tx;
            unset($broken[$field]);
            $this->assertRefused($broken, '"' . $field . '" is missing');

            $broken[$field] = ['not', 'scalar'];
            $this->assertRefused($broken, '"' . $field . '" is missing');
        }

        $broken = $tx;
        $broken[Transaction::INPUTS] = 'x';
        $this->assertRefused($broken, Transaction::INPUTS . ' is missing');

        $broken = $tx;
        $broken[Transaction::OUTPUTS] = [];
        $this->assertRefused($broken, 'at least one output is required');
    }

    public function testUnknownVersion(): void
    {
        $tx = $this->validTransfer();
        $tx['version'] = '99';

        $this->assertRefused($this->resign($tx), 'unknown version');
    }

    public function testTooManyInputsOrOutputs(): void
    {
        $tx = $this->validTransfer();

        $inputs = $tx;
        $inputs[Transaction::INPUTS] = array_fill(0, Config::getMaxSpentTransactionCount() + 1, $tx[Transaction::INPUTS][0]);
        $this->assertRefused($inputs, 'max input');

        $outputs = $tx;
        $outputs[Transaction::OUTPUTS] = array_fill(0, Config::getMaxUnspentTransactionCount() + 1, $tx[Transaction::OUTPUTS][0]);
        $this->assertRefused($outputs, 'max output');
    }

    public function testPeerMustBeShortAndAlphanumeric(): void
    {
        $tx = $this->validTransfer();

        $tx['peer'] = 'has space';
        $this->assertRefused($this->resign($tx), '"peer"');

        $tx['peer'] = str_repeat('a', 41);
        $this->assertRefused($this->resign($tx), '"peer"');
    }

    public function testDateBeforeGenesis(): void
    {
        $tx = $this->validTransfer();
        $tx['date_created'] = Config::getGenesisDate() - 1;

        $this->assertRefused($this->resign($tx), 'before genesis');
    }

    public function testTxIdsMustRunFromZero(): void
    {
        $tx = $this->validTransfer();

        $gap = $tx;
        $gap[Transaction::OUTPUTS][0]['tx_id'] = '1';
        $this->assertRefused($this->resign($gap), 'tx_id values must run from 0');

        $duplicate = $tx;
        $duplicate[Transaction::OUTPUTS][1] = $duplicate[Transaction::OUTPUTS][0];
        $this->assertRefused($this->resign($duplicate), 'tx_id values must run from 0');
    }

    public function testIdMustMatchTheContents(): void
    {
        $tx = $this->validTransfer();
        $tx[Transaction::OUTPUTS][0]['value'] = '1';

        $this->assertRefused($tx, 'transaction_id does not match');
    }

    public function testSignatureFromAnotherKey(): void
    {
        $tx = $this->validTransfer();
        $tx['signature'] = $this->transaction->signTransaction($tx, $this->thief['public_key'], $this->thief['private_key']);

        $this->assertRefused($tx, 'Invalid signature');
    }

    public function testOutputRules(): void
    {
        $cases = [
            'invalid address in output' => ['address', 'Xanope'],
            'output script is invalid' => ['script', str_repeat('A', Config::getMaxScriptLength() + 1)],
            'Invalid lock_height' => ['lock_height', '-1'],
        ];

        foreach ($cases as $reason => [$field, $value]) {
            $tx = $this->validTransfer();
            $tx[Transaction::OUTPUTS][0][$field] = $value;
            $this->assertRefused($this->resign($tx), $reason);
            $this->tearDown();
            $this->setUp();
        }
    }

    public function testOutputValueMustBePositiveWholeUnits(): void
    {
        foreach (['0', '-5', '1.5', 'abc', ''] as $value) {
            $tx = $this->validTransfer();
            $tx[Transaction::OUTPUTS][0]['value'] = $value;
            $result = $this->transaction->validate($this->resign($tx));

            $this->assertFalse($result['validated'], 'value ' . var_export($value, true));
            $this->tearDown();
            $this->setUp();
        }
    }

    public function testFeeBelowTheMinimum(): void
    {
        $tx = $this->validTransfer();
        $tx[Transaction::OUTPUTS][0]['value'] = bcadd($this->spendAmount(), Config::getMinimumTransactionFee());

        $this->assertRefused($this->resign($tx), 'less than minimum');
    }

    public function testCoinbaseRules(): void
    {
        $height = $this->block->getCurrentHeight() + 1;
        $coinbase = $this->validCoinbase();

        $withInput = $coinbase;
        $withInput[Transaction::INPUTS] = [[
            'tx_id' => '0', 'previous_transaction_id' => str_repeat('a', 64), 'previous_tx_out_id' => '0', 'script' => '',
        ]];
        $result = $this->transaction->validate($this->resign($withInput), $height);
        $this->assertFalse($result['validated']);

        $wrongHeight = $coinbase;
        $wrongHeight['height'] = $height + 1;
        $this->assertRefused($this->resign($wrongHeight), 'coinbase height must match', $height);

        $shortLock = $coinbase;
        $shortLock[Transaction::OUTPUTS][0]['lock_height'] = (string)($height + Config::getLockHeight() - 1);
        $this->assertRefused($this->resign($shortLock), 'lock', $height);
    }

    public function testTransferNeedsInputs(): void
    {
        $tx = $this->validTransfer();
        $tx[Transaction::INPUTS] = [];

        $result = $this->transaction->validate($this->resign($tx));
        $this->assertFalse($result['validated']);
    }

    public function testInputRules(): void
    {
        $tx = $this->validTransfer();

        $noPrevious = $tx;
        $noPrevious[Transaction::INPUTS][0]['previous_transaction_id'] = '';
        $this->assertFalse($this->transaction->validate($this->resign($noPrevious))['validated']);

        $badIndex = $tx;
        $badIndex[Transaction::INPUTS][0]['previous_tx_out_id'] = 'x';
        $this->assertFalse($this->transaction->validate($this->resign($badIndex))['validated']);

        $bigScript = $tx;
        $bigScript[Transaction::INPUTS][0]['script'] = str_repeat('A', Config::getMaxScriptLength() + 1);
        $bigScript['transaction_id'] = $this->transaction->computeId($bigScript);
        $bigScript['signature'] = $this->transaction->signTransaction(
            $bigScript,
            $this->owner['public_key'],
            $this->owner['private_key']
        );
        $this->assertFalse($this->transaction->validate($bigScript)['validated']);
    }

    public function testUnlockFailsOnScriptsThatDoNotDecode(): void
    {
        $this->assertFalse($this->transaction->unlockTransaction(['script' => '!!!'], ['script' => '!!!'], 'hash'));
        $this->assertFalse($this->transaction->unlockTransaction(['script' => ''], ['script' => ''], 'hash'));
    }

    public function testStoredTransactionCanBeLookedUp(): void
    {
        $block = $this->mineNextBlock($this->owner);
        $coinbase = $this->coinbaseOf($block);

        $this->assertTrue($this->transaction->exists($coinbase['transaction_id']));
        $this->assertFalse($this->transaction->exists(str_repeat('0', 64)));
        $this->assertTrue($this->transaction->existStrict($block['block_id'], $coinbase['transaction_id']));
        $this->assertFalse($this->transaction->existStrict(str_repeat('0', 64), $coinbase['transaction_id']));

        $stored = $this->transaction->getByTransactionId($block['block_id'], $coinbase['transaction_id']);
        $this->assertEquals($coinbase['signature'], $stored['signature']);
        $this->assertCount(1, $stored[Transaction::OUTPUTS]);
        $this->assertEquals($stored, $this->transaction->get((int)$stored['id']) + [
            Transaction::INPUTS => $stored[Transaction::INPUTS],
            Transaction::OUTPUTS => $stored[Transaction::OUTPUTS],
        ]);
        $this->assertNull($this->transaction->getByTransactionId($block['block_id'], str_repeat('0', 64)));
    }

    public function testRecompressedScriptKeepsTheSameId(): void
    {
        $tx = $this->validTransfer();
        $encoding = new TransferEncoding();

        // the same input script compressed another way, as a relaying node could do
        $plain = (new Script([]))->decodeScript($tx[Transaction::INPUTS][0]['script']);
        $other = $tx;
        $other[Transaction::INPUTS][0]['script'] = $encoding->binToBase58(gzcompress($plain, 1));

        $this->assertNotEquals($tx[Transaction::INPUTS][0]['script'], $other[Transaction::INPUTS][0]['script']);
        $this->assertEquals($tx['transaction_id'], $this->transaction->computeId($other));
        $this->assertTrue($this->transaction->validate($other)['validated']);
    }

    public function testChangedScriptChangesTheId(): void
    {
        $tx = $this->validTransfer();
        $other = $tx;
        $other[Transaction::OUTPUTS][0]['script'] = (new Script([]))->encodeScript('nop;');

        $this->assertNotEquals($tx['transaction_id'], $this->transaction->computeId($other));
    }

    public function testScriptThatExpandsTooFarDoesNotBlowUpTheId(): void
    {
        $tx = $this->validTransfer();
        $bomb = (new Script([]))->encodeScript(str_repeat('nop;', 100000));
        $tx[Transaction::OUTPUTS][0]['script'] = $bomb;

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->transaction->computeId($tx));
        $this->assertFalse($this->transaction->validate($this->resign($tx))['validated']);
    }

    public function testSizeIgnoresHowFieldsWereTyped(): void
    {
        $tx = $this->validTransfer();
        $typed = $tx;
        $typed['height'] = (int)$tx['height'];
        $typed[Transaction::OUTPUTS][0]['tx_id'] = 0;

        $this->assertEquals(Transaction::size($tx), Transaction::size($typed));
        $this->assertGreaterThan(1000, Transaction::size($tx));
    }

    public function testOversizedTransactionIsRefused(): void
    {
        $tx = $this->validTransfer();
        $tx['peer'] = str_repeat('a', Config::getMaxTransactionBytes());

        $this->assertRefused($tx, 'transaction is larger than');
    }

    public function testOversizedBlockIsRefused(): void
    {
        $height = $this->block->getCurrentHeight() + 1;
        $block = $this->block->getCandidateBlock($height, $this->owner['public_key'], $this->owner['private_key']);
        $filler = $block['transactions'][0];
        $filler['public_key'] = str_repeat('a', Config::getMaxTransactionBytes() - 1000);

        $transactions = [$block['transactions'][0], $filler, $filler, $filler];
        $result = $this->block->validate($block, $transactions, count($transactions));

        $this->assertFalse($result['validated']);
        $this->assertStringContainsString('block is larger than', $result['reason']);
    }

    public function testSpendLookupsUseAnIndex(): void
    {
        $plans = [
            'SELECT 1 FROM transaction_outputs o JOIN blocks b ON b.block_id=o.block_id ' .
            "WHERE o.transaction_id='x' AND o.tx_id=0 AND o.spent=0 AND b.orphan=0",
            "SELECT 1 FROM transaction_inputs WHERE previous_transaction_id='x' AND previous_tx_out_id=0",
            "SELECT 1 FROM transactions WHERE transaction_id='x'",
        ];

        foreach ($plans as $query) {
            $plan = implode(' ', $this->db->query('EXPLAIN QUERY PLAN ' . $query)->fetchAll(\PDO::FETCH_COLUMN, 3));
            $this->assertStringNotContainsString('SCAN', $plan, $query);
        }
    }
}
