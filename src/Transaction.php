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

    public const Inputs = 'txIn';
    public const Outputs = 'txOut';

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
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transactionId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt->execute();

        $id = $stmt->fetchColumn() ?: null;
        return ($id !== null && $id > 0);
    }

    public function existStrict(string $blockId, string $transactionId): bool
    {
        $query = 'SELECT `id` FROM transactions WHERE `block_id` =:block_id AND `transaction_id` = :transaction_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $blockId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transactionId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt->execute();

        $id = $stmt->fetchColumn() ?: null;
        return ($id !== null && $id > 0);
    }

    public function get(int $id): array|null
    {
        $query = 'SELECT `id`,`block_id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`,`public_key` FROM transactions WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT, 0);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getByTransactionId(string $blockId, string $transactionId): array|null
    {
        $query = 'SELECT `id`,`block_id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`,`public_key` FROM transactions WHERE `block_id` =:block_id AND `transaction_id` = :transaction_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'block_id', $blockId, DatabaseHelpers::ALPHA_NUMERIC, 64);
        $stmt = DatabaseHelpers::filterBind($stmt, 'transaction_id', $transactionId, DatabaseHelpers::ALPHA_NUMERIC, 64);
        $stmt->execute();
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($transaction !== null) {
            $txIns = $this->getTransactionInputs($transactionId);
            $txOuts = $this->getTransactionOutputs($transactionId);

            // attach the details
            $transaction[self::Inputs] = $txIns;
            $transaction[self::Outputs] = $txOuts;
        }

        return $transaction;
    }

    public function getTransactionInputs(string $blockId, string $transactionId): array
    {
        $query = 'SELECT `id`,`block_id`,`transaction_id`,`tx_id`,`previous_transaction_id`,`previous_tx_out_id`,`script` FROM transaction_inputs WHERE `block_id`=:block_id AND `transaction_id`=:transaction_id;';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $blockId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transactionId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt->execute();

        return self::sortTx($stmt->fetchAll(PDO::FETCH_ASSOC)) ?: [];
    }

    public function getTransactionOutputs(string $blockId, string $transactionId): array
    {
        $query = 'SELECT `id`,`transaction_id`,`tx_id`,`address`,`value`,`script`,`lock_height`,`spent`,`hash` FROM transaction_outputs WHERE `block_id`=:block_id AND transaction_id=:transaction_id;';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $blockId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transactionId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt->execute();

        return self::sortTx($stmt->fetchAll(PDO::FETCH_ASSOC)) ?: [];
    }

    public function getTransactionsByBlockId(string $blockId): array
    {
        $returnTransactions = [];
        $query = 'SELECT `id`,`block_id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`,`public_key` FROM transactions WHERE `block_id` = :block_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'block_id', $blockId, DatabaseHelpers::ALPHA_NUMERIC, 64);
        $stmt->execute();

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: null;
        foreach ($transactions as $transaction) {
            // attach the details
            $transaction[self::Inputs] = $this->getTransactionInputs($blockId, $transaction['transaction_id']) ?: [];
            $transaction[self::Outputs] = $this->getTransactionOutputs($blockId, $transaction['transaction_id']) ?: [];
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
        foreach ($transaction[self::Inputs] as $in) {
            unset($in['id'], $in['block_id'], $in['transaction_id']);
            $inputs[] = $in;
        }
        $transaction[self::Inputs] = $inputs;

        $outputs = [];
        foreach ($transaction[self::Outputs] as $out) {
            unset($out['id'], $out['block_id'], $out['transaction_id'], $out['spent']);
            $outputs[] = $out;
        }
        $transaction[self::Outputs] = $outputs;

        return $transaction;
    }

    #[ArrayShape(['validated' => "", 'reason' => ""])]
    private function returnValidateError(string $reason): array
    {
        return [
            'validated' => false,
            'reason' => $reason
        ];
    }

    public function calculateMinerFee(array $transaction): string
    {
        $totalInputs = "0";
        if (isset($transaction[self::Inputs])) {
            foreach ($transaction[self::Inputs] as $txIn) {
                $previousTransaction = $this->getByTransactionId($txIn['previous_transaction_id']);
                if ($previousTransaction !== null) {
                    // add if the value is unspent
                    $unspent = $previousTransaction[self::Outputs][$txIn['tx_id']];
                    if ((int)$unspent['spent'] === 0) {
                        $totalInputs = bcadd($totalInputs, $unspent['value']);
                    }
                }
            }
        }

        $totalOutputs = "0";
        if (isset($transaction[self::Outputs])) {
            foreach ($transaction[self::Outputs] as $txOut) {
                // add values
                $totalOutputs = bcadd($totalOutputs, $txOut['value']);
            }
        }

        // report the fee
        return BcmathExtensions::bcabs(bcsub($totalInputs, $totalOutputs));
    }

    /**
     * @throws Exception
     */
    #[ArrayShape(['validated' => "false", 'reason' => "string"])]
    public function validate(array $transaction): array
    {
        $address = new Address();
        $openSsl = new OpenSsl();

        if (!isset($transaction['date_created'])) {
            return $this->returnValidateError('"date_created" is a missing');
        }

        if (!isset($transaction['public_key'])) {
            return $this->returnValidateError('"public_key_raw" is a missing.');
        }

        if (!isset($transaction['signature'])) {
            return $this->returnValidateError('signature is missing.');
        }

        if (!isset($transaction['transaction_id'])) {
            return $this->returnValidateError('transaction_id is a missing.');
        }

        // must have at least one unspent output
        if (!isset($transaction[self::Outputs])) {
            return $this->returnValidateError('unspent output transactions are a missing.');
        }

        if (count($transaction[self::Inputs]) > Config::getMaxSpentTransactionCount()) {
            return $this->returnValidateError(self::Inputs . ' > max input transaction unspent size.');
        }

        if (count($transaction[self::Outputs]) > Config::getMaxUnspentTransactionCount()) {
            return $this->returnValidateError(self::Outputs . ' > max output transaction spent size.');
        }

        if (!isset($transaction['version'])) {
            return $this->returnValidateError('missing version in the transaction');
        }

        if (!isset($transaction['height'])) {
            return $this->returnValidateError('missing height in the transaction');
        }

        if (strlen($transaction['peer']) > 40) {
            return $this->returnValidateError('"peer" is too long, must be 40 or less');
        }

        // no transactions before the genesis
        if ($transaction['date_created'] < Config::getGenesisDate()) {
            return $this->returnValidateError('date created is before genesis block ' . $transaction['date_created'] . ' < ' . Config::getGenesisDate());
        }

        // validate signature
        $signatureText = $this->generateSignatureText($transaction);
        if (!$openSsl->verifySignature($signatureText, $transaction['signature'], $openSsl->formatPem($transaction['public_key'], false))) {
            return $this->returnValidateError('Invalid signature');
        }

        $totalInputs = "0";
        if (isset($transaction[self::Inputs])) {
            foreach ($transaction[self::Inputs] as $txIn) {
                if (!isset($txIn['previous_transaction_id'])) {
                    return $this->returnValidateError(
                        'transaction_id: (' . $transaction['transaction_id'] . ') is a missing an unspent transaction'
                    );
                }

                if (!isset($txIn['previous_tx_out_id'])) {
                    return $this->returnValidateError(
                        'transaction_id: (' . $transaction['transaction_id'] . ') is a missing an unspent transaction index'
                    );
                }

                $previousTransaction = $this->getByTransactionId($txIn['previous_transaction_id']);
                if ($previousTransaction !== null) {
                    if (!isset($previousTransaction[self::Outputs][(int)$txIn['previous_tx_out_id']])) {
                        return $this->returnValidateError(
                            'previous_tx_out_id: (' . $txIn['previous_tx_out_id'] . ') does not exist'
                        );
                    }

                    // add if the value is unspent
                    $unspent = $previousTransaction[self::Outputs][$txIn['tx_id']];
                    if ((int)$unspent['spent'] === 0) {
                        $totalInputs = bcadd($totalInputs, $unspent['value']);
                    }

                    if (strlen($txIn['script']) > Config::getMaxScriptLength()) {
                        return $this->returnValidateError('uncompressed script is larger than the max allowed');
                    }
                } else {
                    return $this->returnValidateError('previous transaction_id: (' . $txIn['transaction_id'] . ') is a missing on a spent transaction');
                }
            }
        }

        $totalOutputs = "0";
        foreach ($transaction[self::Outputs] as $txOut) {
            if (!$address->validateAddress($txOut['address'])) {
                return $this->returnValidateError('invalid address in unspent: ' . $txOut['address']);
            }

            if (!isset($txOut['tx_id'])) {
                return $this->returnValidateError('missing tx_id in unspent');
            }

            if (strlen($txOut['script']) > Config::getMaxScriptLength()) {
                return $this->returnValidateError('uncompressed script is larger than the max allowed');
            }

            if ((int)$txOut['lock_height'] < 0) {
                return $this->returnValidateError('Invalid lock_height value');
            }

            if ((int)$txOut['lock_height'] === 0 && ((int)$txOut['lock_height'] > (int)$transaction['height'])) {
                return $this->returnValidateError('Height is greater than the lock height value');
            }

            if (bccomp($txOut['value'], "0") <= 0) {
                return $this->returnValidateError('value of unspent is less than or equal to 0');
            }

            // add values
            $totalOutputs = bcadd($totalOutputs, $txOut['value']);
        }

        // non-coinbase - the inputs must be greater or equal to the outputs
        if (($transaction['version'] !== TransactionVersion::Coinbase) && bccomp($totalInputs, $totalOutputs) < 0) {
            return $this->returnValidateError('the inputs are less than the outputs).');
        }

        // coinbase - the inputs must be zero, and the outputs must be > 0
        if (($transaction['version'] === TransactionVersion::Coinbase) && bccomp($totalInputs, "0") === 0) {
            return $this->returnValidateError('the coinbase inputs must be zero.');
        }

        // check for an appropriate fee
        $fee = BcmathExtensions::bcabs(bcsub($totalInputs, $totalOutputs));
        if (bccomp($fee, Config::getMinimumTransactionFee()) < 0) {
            return $this->returnValidateError('fee (' . $fee . ') is less than minimum (' . Config::getMinimumTransactionFee() . ').');
        }

        return [
            'validated' => true,
            'reason' => '',
        ];
    }

    public static function sort(array $transactions): array
    {
        // we must sort the same way every time
        $sorted = [];
        foreach ($transactions as $transaction) {
            $sorted[$transaction['version'] . $transaction['date_created'] . $transaction['transaction_id']] = $transaction;
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

    public static function sortTx(?array $txs): array
    {
        $sorted = [];
        $sortedTx = [];
        if ($txs !== null) {
            foreach ($txs as $tx) {
                $sorted[str_pad((string)(int)$tx['tx_id'], 6, '0', STR_PAD_LEFT)] = $tx;
            }

            // sort by the new key
            ksort($sorted);

            // reassemble the array from the sorted data
            foreach ($sorted as $txIn) {
                $sortedTx[] = $txIn;
            }
        }

        return $sortedTx;
    }

    public function generateSignatureText(array $transaction): string
    {
        $txInData = '';
        $transaction[self::Inputs] = self::sortTx($transaction[self::Inputs]);
        foreach ($transaction[self::Inputs] as $txIn) {
            $txInData .= $txIn['tx_id'] . $txIn['previous_transaction_id'] . $txIn['previous_tx_out_id'] . $txIn['script'];
        }

        $txOutData = '';
        $transaction[self::Outputs] = self::sortTx($transaction[self::Outputs]);
        foreach ($transaction[self::Outputs] as $txOut) {
            $txInData .= $txOut['tx_id'] . $txOut['address'] . $txOut['value'] . $txOut['script'] . $txOut['lock_height'];
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