<?php declare(strict_types=1);

namespace Blockchain;

use PDO;
use RuntimeException;

/**
 * Builds signed transfers from an account's unspent outputs.
 */
class Wallet
{
    public const COIN = '100000000';

    private PDO $db;
    private Address $address;
    private Block $block;
    private Mempool $mempool;
    private OpenSsl $openSsl;
    private Script $script;
    private Transaction $transaction;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->address = new Address();
        $this->block = new Block();
        $this->mempool = new Mempool();
        $this->openSsl = new OpenSsl();
        $this->script = new Script([]);
        $this->transaction = new Transaction();
    }

    /**
     * Turns a coin amount like "1.5" into base units. Returns null if it is not a valid amount.
     *
     * @param string $coins
     * @return string|null
     */
    public static function toUnits(string $coins): ?string
    {
        if (!preg_match('/^\d+(\.\d{1,8})?$/', $coins)) {
            return null;
        }

        return bcmul($coins, self::COIN, 0);
    }

    /**
     * @param string $units
     * @return string
     */
    public static function toCoins(string $units): string
    {
        return bcdiv($units, self::COIN, 8);
    }

    /**
     * The script that locks an output to an address. The owner unlocks it with "push sig;push key;dup;".
     *
     * @param string $address
     * @return string encoded script
     */
    public function lockScript(string $address): string
    {
        return $this->script->encodeScript(
            'mov ' . $address . ',ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;vsig ax,<hash>,bx;'
        );
    }

    /**
     * Outputs on the active chain paid to $address that can be spent in the next block and are not already
     * being spent by a mempool transaction. Oldest first.
     *
     * @param string $address
     * @return array
     */
    public function getSpendableOutputs(string $address): array
    {
        $stmt = $this->db->prepare(
            'SELECT o.transaction_id, o.tx_id, o.value FROM transaction_outputs o ' .
            'JOIN blocks b ON b.block_id=o.block_id ' .
            'WHERE b.orphan=0 AND o.spent=0 AND o.address=:address AND o.lock_height<=:height ' .
            'ORDER BY b.height, o.transaction_id, o.tx_id'
        );
        $stmt->execute([':address' => $address, ':height' => $this->block->getCurrentHeight() + 1]);
        $outputs = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $output) {
            if (!$this->mempool->isOutputSpent($output['transaction_id'], (int)$output['tx_id'])) {
                $outputs[] = $output;
            }
        }

        return $outputs;
    }

    /**
     * @param string $address
     * @return string base units
     */
    public function getBalance(string $address): string
    {
        $balance = '0';

        foreach ($this->getSpendableOutputs($address) as $output) {
            $balance = bcadd($balance, (string)$output['value']);
        }

        return $balance;
    }

    /**
     * Builds and signs a transfer of $amount base units from $account to $toAddress. Any change goes back to the
     * account's address.
     *
     * @param array $account an accounts row
     * @param string $toAddress
     * @param string $amount base units
     * @param string|null $fee base units, the minimum fee if null
     * @return array
     */
    public function createTransfer(array $account, string $toAddress, string $amount, ?string $fee = null): array
    {
        $fee ??= Config::getMinimumTransactionFee();

        if (!$this->address->validateAddress($toAddress)) {
            throw new RuntimeException('invalid address: ' . $toAddress);
        }

        if (!ctype_digit($amount) || bccomp($amount, '0') <= 0) {
            throw new RuntimeException('the amount must be more than zero');
        }

        if (!ctype_digit($fee) || bccomp($fee, Config::getMinimumTransactionFee()) < 0) {
            throw new RuntimeException('the fee must be at least ' . Config::getMinimumTransactionFee());
        }

        $needed = bcadd($amount, $fee);
        $selected = [];
        $total = '0';

        foreach ($this->getSpendableOutputs($account['address']) as $output) {
            if (bccomp($total, $needed) >= 0) {
                break;
            }

            $selected[] = $output;
            $total = bcadd($total, (string)$output['value']);
        }

        if (bccomp($total, $needed) < 0) {
            throw new RuntimeException('not enough funds: have ' . $total . ', need ' . $needed);
        }

        if (count($selected) > Config::getMaxSpentTransactionCount()) {
            throw new RuntimeException('this needs more than ' . Config::getMaxSpentTransactionCount() . ' inputs');
        }

        $inputs = [];

        foreach ($selected as $i => $output) {
            $inputs[] = [
                'tx_id' => (string)$i,
                'previous_transaction_id' => $output['transaction_id'],
                'previous_tx_out_id' => (string)$output['tx_id'],
                'script' => '',
            ];
        }

        $outputs = [[
            'tx_id' => '0',
            'address' => $toAddress,
            'value' => $amount,
            'script' => $this->lockScript($toAddress),
            'lock_height' => '0',
        ]];
        $change = bcsub($total, $needed);

        if (bccomp($change, '0') > 0) {
            $outputs[] = [
                'tx_id' => '1',
                'address' => $account['address'],
                'value' => $change,
                'script' => $this->lockScript($account['address']),
                'lock_height' => '0',
            ];
        }

        $tx = [
            'date_created' => time(),
            'public_key' => $account['public_key_raw'],
            'peer' => 'wallet',
            'version' => TransactionVersion::TRANSFER,
            'height' => $this->block->getCurrentHeight() + 1,
            Transaction::INPUTS => $inputs,
            Transaction::OUTPUTS => $outputs,
        ];

        // every input is unlocked by the same signature over the transaction
        $inputSignature = $this->openSsl->signAndVerifyData(
            $this->transaction->signatureHash($tx),
            $account['public_key'],
            $account['private_key']
        );

        foreach ($tx[Transaction::INPUTS] as $i => $txIn) {
            $tx[Transaction::INPUTS][$i]['script'] = $this->script->encodeScript(
                'push ' . $inputSignature . ';push ' . $account['public_key_raw'] . ';dup;'
            );
        }

        $tx['transaction_id'] = $this->transaction->computeId($tx);
        $tx['signature'] = $this->transaction->signTransaction($tx, $account['public_key'], $account['private_key']);

        return $tx;
    }
}
