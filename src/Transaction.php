<?php declare(strict_types=1);

namespace Xeros;

use Exception;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use PDO;

class Transaction
{
    private PDO $db;
    protected Pow $pow;

    public const INPUTS = 'txIn';
    public const OUTPUTS = 'txOut';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->pow = new Pow();
    }

    #[Pure]
    public function generateId($date, $blockId, $publicKeyRaw): string
    {
        return bin2hex(
            $this->pow->doubleSha256(
                $date . $blockId . $publicKeyRaw
            )
        );
    }

    public function exists(string $transactionId): bool
    {
        $query = 'SELECT `id` FROM transactions WHERE `transaction_id` = :transaction_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'transaction_id',
            value: $transactionId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64
        );
        $stmt->execute();

        $id = $stmt->fetchColumn() ?: null;
        return ($id !== null && $id > 0);
    }

    public function existStrict(string $blockId, string $transactionId): bool
    {
        $query = 'SELECT `id` FROM transactions WHERE `block_id` =:block_id AND `transaction_id` = ' .
            ':transaction_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'block_id',
            value: $blockId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64
        );
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'transaction_id',
            value: $transactionId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64
        );
        $stmt->execute();

        $id = $stmt->fetchColumn() ?: null;
        return ($id !== null && $id > 0);
    }

    public function get(int $id): array|null
    {
        $query = 'SELECT `id`,`block_id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`,' .
            '`public_key` FROM transactions WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT, 0);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getByTransactionId(string $blockId, string $transactionId): array|null
    {
        $query = 'SELECT `id`,`block_id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`' .
            ',`public_key` FROM transactions WHERE `block_id` =:block_id AND `transaction_id` = :transaction_id ' .
            'LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            $stmt,
            'block_id',
            $blockId,
            DatabaseHelpers::ALPHA_NUMERIC,
            64
        );
        $stmt = DatabaseHelpers::filterBind(
            $stmt,
            'transaction_id',
            $transactionId,
            DatabaseHelpers::ALPHA_NUMERIC,
            64
        );
        $stmt->execute();
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($transaction !== null) {
            $txIns = $this->getTransactionInputs($blockId, $transactionId);
            $txOuts = $this->getTransactionOutputs($blockId, $transactionId);

            // attach the details
            $transaction[self::INPUTS] = $txIns;
            $transaction[self::OUTPUTS] = $txOuts;
        }

        return $transaction;
    }

    public function getTransactionInputs(string $blockId, string $transactionId): array
    {
        $query = 'SELECT `id`,`block_id`,`transaction_id`,`tx_id`,`previous_transaction_id`,`previous_tx_out_id`' .
            ',`script` FROM transaction_inputs WHERE `block_id`=:block_id AND `transaction_id`=:transaction_id;';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'block_id',
            value: $blockId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64
        );
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'transaction_id',
            value: $transactionId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64
        );
        $stmt->execute();

        return self::sortTx($stmt->fetchAll(PDO::FETCH_ASSOC)) ?: [];
    }

    public function getTransactionOutputs(string $blockId, string $transactionId): array
    {
        $query = 'SELECT `id`,`transaction_id`,`tx_id`,`address`,`value`,`script`,`lock_height`,`spent`,`hash` ' .
            'FROM transaction_outputs WHERE `block_id`=:block_id AND transaction_id=:transaction_id;';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'block_id',
            value: $blockId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64
        );
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'transaction_id',
            value: $transactionId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64
        );
        $stmt->execute();

        return self::sortTx($stmt->fetchAll(PDO::FETCH_ASSOC)) ?: [];
    }

    public function getTransactionsByBlockId(string $blockId): array
    {
        $returnTransactions = [];
        $query = 'SELECT `id`,`block_id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`' .
            ',`public_key` FROM transactions WHERE `block_id` = :block_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            $stmt,
            'block_id',
            $blockId,
            DatabaseHelpers::ALPHA_NUMERIC,
            64
        );
        $stmt->execute();

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: null;
        foreach ($transactions as $transaction) {
            // attach the details
            $transaction[self::INPUTS] = $this->getTransactionInputs($blockId, $transaction['transaction_id']) ?: [];
            $transaction[self::OUTPUTS] = $this->getTransactionOutputs($blockId, $transaction['transaction_id']) ?: [];
            $returnTransactions[] = $transaction;
        }

        return $returnTransactions;
    }

    public function stripInternalFields(array $transaction): array
    {
        // remove internal columns
        unset($transaction['id'], $transaction['block_id']);

        // remove internal columns
        $inputs = [];
        foreach ($transaction[self::INPUTS] as $in) {
            unset($in['id'], $in['block_id'], $in['transaction_id']);
            $inputs[] = $in;
        }
        $transaction[self::INPUTS] = $inputs;

        $outputs = [];
        foreach ($transaction[self::OUTPUTS] as $out) {
            unset($out['id'], $out['block_id'], $out['transaction_id'], $out['spent']);
            $outputs[] = $out;
        }
        $transaction[self::OUTPUTS] = $outputs;

        return $transaction;
    }

    public function calculateMinerFee(array $transaction): string
    {
        $totalInputs = "0";
        if (isset($transaction[self::INPUTS])) {
            foreach ($transaction[self::INPUTS] as $txIn) {
                $previousTransaction = $this->getByTransactionId(
                    $transaction['block_id'],
                    $txIn['previous_transaction_id']
                );
                if ($previousTransaction !== null) {
                    // add if the value is unspent
                    $unspent = $previousTransaction[self::OUTPUTS][$txIn['tx_id']];
                    if ((int)$unspent['spent'] === 0) {
                        $totalInputs = bcadd($totalInputs, $unspent['value']);
                    }
                }
            }
        }

        $totalOutputs = "0";
        if (isset($transaction[self::OUTPUTS])) {
            foreach ($transaction[self::OUTPUTS] as $txOut) {
                // add values
                $totalOutputs = bcadd($totalOutputs, $txOut['value']);
            }
        }

        // report the fee
        return BcmathExtensions::bcabs(bcsub($totalInputs, $totalOutputs));
    }

    #[ArrayShape(['validated' => "false", 'reason' => "string"])]
    public function validate(array $transaction): array
    {
        $result = true;
        $reason = '';

        $address = new Address();
        $openSsl = new OpenSsl();

        if (!isset($transaction['date_created'])) {
            $reason .= '"date_created" is a missing,';
            $result = false;
        }

        if (!isset($transaction['public_key'])) {
            $reason .= '"public_key_raw" is a missing,';
            $result = false;
        }

        if (!isset($transaction['signature'])) {
            $reason .= 'signature is missing,';
            $result = false;
        }

        if (!isset($transaction['transaction_id'])) {
            $reason .= 'transaction_id is a missing,';
            $result = false;
        }

        // must have at least one unspent output
        if (!isset($transaction[self::OUTPUTS])) {
            $reason .= 'unspent output transactions are a missing,';
            $result = false;
        }

        if (count($transaction[self::INPUTS]) > Config::getMaxSpentTransactionCount()) {
            $reason .= self::INPUTS . ' > max input transaction unspent size,';
            $result = false;
        }

        if (count($transaction[self::OUTPUTS]) > Config::getMaxUnspentTransactionCount()) {
            $reason .= self::OUTPUTS . ' > max output transaction spent size,';
            $result = false;
        }

        if (!isset($transaction['version'])) {
            $reason .= 'missing version in the transaction,';
            $result = false;
        }

        if (!isset($transaction['height'])) {
            $reason .= 'missing height in the transaction,';
            $result = false;
        }

        if (strlen($transaction['peer']) > 40) {
            $reason .= '"peer" is too long, must be 40 or less,';
            $result = false;
        }

        // no transactions before the genesis
        if ($transaction['date_created'] < Config::getGenesisDate()) {
            $reason .= 'date created is before genesis block ' .
                $transaction['date_created'] . ' < ' . Config::getGenesisDate() . ',';
            $result = false;
        }

        // validate signature
        $signatureText = $this->generateSignatureText($transaction);
        if (!$openSsl->verifySignature(
            $signatureText,
            $transaction['signature'],
            $openSsl->formatPem($transaction['public_key'], false)
        )) {
            $reason .= 'Invalid signature,';
            $result = false;
        }

        $totalInputs = "0";
        if (isset($transaction[self::INPUTS])) {
            foreach ($transaction[self::INPUTS] as $txIn) {
                if (!isset($txIn['previous_transaction_id'])) {
                    $reason .= 'transaction_id: (' . $transaction['transaction_id'] .
                        ') is a missing an unspent transaction,';
                    $result = false;
                }

                if (!isset($txIn['previous_tx_out_id'])) {
                    $reason .= 'transaction_id: (' . $transaction['transaction_id'] .
                        ') is a missing an unspent transaction index,';
                    $result = false;
                }

                $previousTransaction = $this->getByTransactionId(
                    $transaction['block_id'],
                    $txIn['previous_transaction_id']
                );
                if ($previousTransaction !== null) {
                    if (!isset($previousTransaction[self::OUTPUTS][(int)$txIn['previous_tx_out_id']])) {
                        $reason .= 'previous_tx_out_id: (' . $txIn['previous_tx_out_id'] . ') does not exist,';
                        $result = false;
                    }

                    // add if the value is unspent
                    $unspent = $previousTransaction[self::OUTPUTS][$txIn['tx_id']];
                    if ((int)$unspent['spent'] === 0) {
                        $totalInputs = bcadd($totalInputs, $unspent['value']);
                    }

                    if (strlen($txIn['script']) > Config::getMaxScriptLength()) {
                        $reason .= 'uncompressed script is larger than the max allowed,';
                        $result = false;
                    }
                } else {
                    $reason .= 'previous transaction_id: (' . $txIn['transaction_id'] .
                        ') is a missing on a spent transaction,';
                    $result = false;
                }
            }
        }

        $totalOutputs = "0";
        foreach ($transaction[self::OUTPUTS] as $txOut) {
            if (!$address->validateAddress($txOut['address'])) {
                $reason .= 'invalid address in unspent: ' . $txOut['address'] . ',';
                $result = false;
            }

            if (!isset($txOut['tx_id'])) {
                $reason .= 'missing tx_id in unspent,';
                $result = false;
            }

            if (strlen($txOut['script']) > Config::getMaxScriptLength()) {
                $reason .= 'uncompressed script is larger than the max allowed,';
                $result = false;
            }

            if ((int)$txOut['lock_height'] < 0) {
                $reason .= 'Invalid lock_height value,';
                $result = false;
            }

            if ((int)$txOut['lock_height'] === 0 && ((int)$txOut['lock_height'] > (int)$transaction['height'])) {
                $reason .= 'Height is greater than the lock height value,';
                $result = false;
            }

            if (bccomp($txOut['value'], "0") <= 0) {
                $reason .= 'value of unspent is less than or equal to 0,';
                $result = false;
            }

            // add values
            $totalOutputs = bcadd($totalOutputs, $txOut['value']);
        }

        // non-coinbase - the inputs must be greater or equal to the outputs
        if (($transaction['version'] !== TransactionVersion::COINBASE) && bccomp($totalInputs, $totalOutputs) < 0) {
            $reason .= 'the inputs are less than the outputs),';
            $result = false;
        }

        // coinbase - the inputs must be zero, and the outputs must be > 0
        if (($transaction['version'] === TransactionVersion::COINBASE) && bccomp($totalInputs, "0") !== 0) {
            $reason .= 'the coinbase inputs must be zero,';
            $result = false;
        }

        // check for an appropriate fee
        $fee = BcmathExtensions::bcabs(bcsub($totalInputs, $totalOutputs));
        if (bccomp($fee, Config::getMinimumTransactionFee(), 0) < 0) {
            $reason .= 'fee (' . $fee . ') is less than minimum (' . Config::getMinimumTransactionFee() . '),';
            $result = false;
        }

        // check reward - can be less, but not more
        if ($transaction['version'] === TransactionVersion::COINBASE) {
            $block = new Block();
            $reward = $block->getRewardValue($transaction['height']);
            if (bccomp($totalOutputs, $block->getRewardValue($transaction['height']), 0) > 0) {
                $reason .= 'Reward ' . $totalOutputs . ' is greater than expected ' . $reward . ',';
                $result = false;
            }
        }

        return [
            'validated' => $reason,
            'reason' => $result,
        ];
    }

    public static function sort(array $transactions): array
    {
        // we must sort the same way every time
        $sorted = [];
        foreach ($transactions as $transaction) {
            $sorted[$transaction['version'] . $transaction['date_created'] .
            $transaction['transaction_id']] = $transaction;
        }

        // sort by the new key
        ksort($sorted);

        // reassemble the array from the sorted data
        $sortedTransactions = [];
        foreach ($sorted as $transaction) {
            $sortedTransactions[] = $transaction;
        }

        return $sortedTransactions;
    }

    public static function sortTx(array $txs): array
    {
        $sorted = [];
        $sortedTx = [];
        foreach ($txs as $tx) {
            $sorted[str_pad((string)(int)$tx['tx_id'], 6, '0', STR_PAD_LEFT)] = $tx;
        }

        // sort by the new key
        ksort($sorted);

        // reassemble the array from the sorted data
        foreach ($sorted as $txIn) {
            $sortedTx[] = $txIn;
        }

        return $sortedTx;
    }

    public function generateSignatureText(array $transaction): string
    {
        $txInData = '';
        $transaction[self::INPUTS] = self::sortTx($transaction[self::INPUTS]);
        foreach ($transaction[self::INPUTS] as $txIn) {
            $txInData .= $txIn['tx_id'] . $txIn['previous_transaction_id'] . $txIn['previous_tx_out_id'] .
                $txIn['script'];
        }

        $txOutData = '';
        $transaction[self::OUTPUTS] = self::sortTx($transaction[self::OUTPUTS]);
        foreach ($transaction[self::OUTPUTS] as $txOut) {
            $txInData .= $txOut['tx_id'] . $txOut['address'] . $txOut['value'] . $txOut['script'] .
                $txOut['lock_height'];
        }

        return $transaction['transaction_id'] . $transaction['date_created'] . $transaction['public_key'] .
            $transaction['peer'] . $transaction['height'] . $transaction['version'] . $txInData . $txOutData;
    }

    /**
     * @throws Exception
     */
    public function generateSignature($signatureText, $publicKey, $privateKey): string
    {
        return (new OpenSsl())->signAndVerifyData($signatureText, $publicKey, $privateKey);
    }

    /**
     * @throws Exception
     */
    public function signTransaction(array $transaction, $publicKey, $privateKey): string
    {
        return $this->generateSignature($this->generateSignatureText($transaction), $publicKey, $privateKey);
    }

    public function unlockTransaction(array $input, array $output): bool
    {
        $container = [
            '<hash>' => $output['hash'], // outputs transaction hash
        ];

        $script = new Script($container);
        $script->loadScript($script->decodeScript($input['script']) . $script->decodeScript($output['script']));
        return $script->run(false);
    }
}
