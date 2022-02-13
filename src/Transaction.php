<?php declare(strict_types=1);

namespace Xeros;

// todo: reduce the size of the signature (dehex, and base58?)
class Transaction extends Database
{
    protected Peer $peers;
    protected TransferEncoding $transferEncoding;
    protected Address $address;
    protected OpenSsl $openSsl;

    public const Inputs = 'txIn';
    public const Outputs = 'txOut';

    public function __construct()
    {
        parent::__construct();
        $this->peers = new Peer();
        $this->address = new Address();
        $this->openSsl = new OpenSsl();
        $this->transferEncoding = new TransferEncoding();
    }

    public function generateId($date, $blockId, $publicKeyRaw): string
    {
        return Hash::doubleSha256ToBase58(
            $date . $blockId . $publicKeyRaw
        );
    }

    public function exists(string $transactionId): bool
    {
        $result = false;
        $id = (int)$this->db->queryFirstField("SELECT count(1) FROM transactions WHERE transaction_id=%s", $transactionId);
        if ($id > 0) {
            $result = true;
        }
        return $result;
    }

    /**
     * @throws MeekroDBException
     * @throws Exception
     */
    public function add(array $transaction, bool $validate = true): int
    {
        // validate transaction
        if ($validate) {
            $validationResult = $this->validate($transaction);
            if ($validationResult['validated'] === false) {
                var_dump($validationResult);
                return 0;
            }
        }

        // store and clip off the spent
        $txIns = $transaction[self::Inputs];
        unset($transaction[self::Inputs]);

        // store and clip off the unspent
        $txOuts = $transaction[self::Outputs];
        unset($transaction[self::Outputs]);

        try {
            $this->db->startTransaction();
            $this->db->insertIgnore('transactions', $transaction);
            $id = $this->db->insertId();
            $this->db->query(
                'DELETE from mempool_transactions WHERE transaction_id=%s',
                $transaction['transaction_id']
            );

            // add txIn
            foreach ($txIns as $txIn) {
                $txIn['transaction_id'] = $transaction['transaction_id'];
                $txOut = $this->db->queryFirstRow(
                    'SELECT transaction_id,tx_id,address,value,script,lock_height,hash FROM transaction_outputs WHERE spent=0 AND transaction_id=%s AND tx_id=%i',
                    $txIn['previous_transaction_id'],
                    $txIn['previous_tx_out_id']
                );
                if ($txOut === null) {
                    throw new MeekroDBException("There is no unspent matching  transaction");
                }

                // mark the transaction as spent
                $this->db->query(
                    'UPDATE transaction_outputs SET spent=1 WHERE transaction_id=%s AND tx_id=%i',
                    $txIn['previous_transaction_id'],
                    $txIn['previous_tx_out_id']
                );

                // run script
                $result = $this->unlockTransaction($txIn, $txOut);
                if (!$result) {
                    throw new MeekroDBException("Cannot unlock script");
                }
                $this->db->insertIgnore('transaction_inputs', $txIn);
                $this->db->query(
                    'DELETE from mempool_inputs WHERE transaction_id=%s AND tx_id=%i',
                    $txIn['transaction_id'],
                    $txIn['tx_id']
                );
            }

            // add txOut
            foreach ($txOuts as $txOut) {
                $txOut['transaction_id'] = $transaction['transaction_id'];
                $this->db->insertIgnore('transaction_outputs', $txOut);
                $this->db->query(
                    'DELETE from mempool_outputs WHERE transaction_id=%s AND tx_id=%i',
                    $txOut['transaction_id'],
                    $txOut['tx_id']
                );
            }

            $this->db->commit();
        } catch (MeekroDBException|Exception $e) {
            var_dump("Transaction105: " . $e->getMessage());
            $this->db->rollback();
            return 0;
        }

        return $id;
    }

    /**
     * @throws Exception
     */
    public function get(int $id): array|null
    {
        $transaction = null;
        $transaction_id = $this->db->queryFirstField('SELECT transaction_id FROM transactions WHERE id=%i', $id);
        if ($transaction_id !== null) {
            $transaction = $this->getByTransactionId($transaction_id);
        }
        return $transaction;
    }

    /**
     * @throws Exception
     */
    public function getByTransactionId(string $transactionId): array|null
    {
        $transaction = $this->db->queryFirstRow('SELECT * FROM transactions WHERE transaction_id=%s', $transactionId);
        if ($transaction !== null) {
            $txIns = $this->db->query("SELECT * FROM transaction_inputs WHERE transaction_id=%s", $transactionId);
            $txOuts = $this->db->query("SELECT * FROM transaction_outputs WHERE transaction_id=%s", $transactionId);

            // attach the details
            $transaction[self::Inputs] = $txIns;
            $transaction[self::Outputs] = $txOuts;
        }

        return $transaction;
    }

    public function getTransactionsByBlockId(string $blockId): array|null
    {
        $returnTransactions = [];
        $transactions = $this->db->query('SELECT block_id,transaction_id,date_created,signature,public_key,peer,height,version FROM transactions WHERE block_id=%s', $blockId);
        foreach ($transactions as $transaction) {

            $txIns = $this->db->query("SELECT tx_id,previous_transaction_id,previous_tx_out_id,script FROM transaction_inputs WHERE transaction_id=%s", $transaction['transaction_id']);
            $txOuts = $this->db->query("SELECT tx_id,address,script,value,lock_height,hash FROM transaction_outputs WHERE transaction_id=%s", $transaction['transaction_id']);

            // attach the details
            $transaction[self::Inputs] = $txIns;
            $transaction[self::Outputs] = $txOuts;

            $returnTransactions[] = $transaction;
        }

        return $returnTransactions;
    }

    public function stripInternals(array $transaction): array
    {
        unset($transaction['id'], $transaction['block_id']);
        return $transaction;
    }

    private function attachTxs(array $transaction): array
    {
        $transaction[self::Inputs] = $this->db->query("SELECT * FROM transaction_inputs WHERE transaction_id=%s", $transaction['transaction_id']);
        $transaction[self::Outputs] = $this->db->query("SELECT * FROM transaction_outputs WHERE transaction_id=%s", $transaction['transaction_id']);

        return $transaction;
    }

    private function assembleFullTransaction(array $transaction): array
    {
        // attach tx's
        $transaction = $this->attachTxs($transaction);

        // remove internals
        $inputs = [];
        foreach ($transaction[self::Inputs] as $in) {
            unset($in['id'], $in['transaction_id']);
            $inputs[] = $in;
        }
        $transaction[self::Inputs] = $inputs;

        $outputs = [];
        foreach ($transaction[self::Outputs] as $out) {
            unset($out['id'], $out['transaction_id'], $out['spent']);
            $outputs[] = $out;
        }
        $transaction[self::Outputs] = $outputs;

        return $this->stripInternals($transaction);
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
        return bcabs(bcsub($totalInputs, $totalOutputs));
    }

    /**
     * @throws Exception
     */
    #[ArrayShape(['validated' => "false", 'reason' => "string"])]
    public function validate(array $transaction): array
    {
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
        if (!$this->openSsl->verifySignature($signatureText, $transaction['signature'], $this->openSsl->formatPem($transaction['public_key'], false))) {
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
            if (!$this->address->validateAddress($txOut['address'])) {
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
        if (($transaction['version'] !== Version::Coinbase) && bccomp($totalInputs, $totalOutputs) < 0) {
            return $this->returnValidateError('the inputs are less than the outputs).');
        }

        // coinbase - the inputs must be zero, and the outputs must be > 0
        if (($transaction['version'] === Version::Coinbase) && bccomp($totalInputs, "0") === 0 && bccomp($totalInputs, "0") > 0) {
            return $this->returnValidateError('the coinbase inputs must be zero.');
        }

        // check for an appropriate fee
        $fee = bcabs(bcsub($totalInputs, $totalOutputs));
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

    public static function sortTx(array $txIns): array
    {
        $sorted = [];
        foreach ($txIns as $txIn) {
            $sorted[str_pad((string)(int)$txIn['tx_id'], 6, '0', STR_PAD_LEFT)] = $txIn;
        }

        // sort by the new key
        ksort($sorted);

        // reassemble the array from the sorted data
        $sortedTxIns = [];
        foreach ($sorted as $txIn) {
            $sortedTxIns[] = $txIn;
        }

        return $sortedTxIns;
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
        return $this->openSsl->signAndVerifyData($signatureText, $publicKey, $privateKey);
    }

    /**
     * @throws Exception
     */
    public function signTransaction(array $transaction, $publicKey, $privateKey): string
    {
        return $this->generateSignature($this->generateSignatureText($transaction), $publicKey, $privateKey);
    }

    public function delete(int $id): bool
    {
        $result = false;
        try {
            $this->db->startTransaction();

            // grab the record for the tx id
            $transaction = $this->get($id);

            $this->db->delete('transactions', "id=%s", $id);
            $this->db->delete('transaction_inputs', "transaction_id=%s", $transaction['transaction_id']);
            $this->db->delete('transaction_outputs', "transaction_id=%s", $transaction['transaction_id']);
            $this->db->commit();
            $result = true;
        } catch (MeekroDBException|Exception $ex) {
            $this->db->rollback();
        }
        return $result;
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