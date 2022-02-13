<?php declare(strict_types=1);

namespace Xeros;

class Mempool extends Database
{
    protected Peer $peers;
    protected TransferEncoding $transferEncoding;
    protected Address $address;
    protected OpenSsl $openSsl;
    protected Transaction $transaction;

    public const Inputs = 'txIn';
    public const Outputs = 'txOut';

    public function __construct()
    {
        parent::__construct();
        $this->peers = new Peer();
        $this->transferEncoding = new TransferEncoding();
        $this->address = new Address();
        $this->openSsl = new OpenSsl();
        $this->transaction = new Transaction();
    }

    public function exists(string $transactionId): bool
    {
        $result = false;
        $id = (int)$this->db->queryFirstField("SELECT count(1) FROM mempool_transactions WHERE transaction_id=%s", $transactionId);
        if ($id > 0) {
            $result = true;
        }
        return $result;
    }

    /**
     * @throws MeekroDBException
     * @throws Exception
     */
    public function add(array $transaction): int
    {
        // validate transaction
        $validationResult = $this->transaction->validate($transaction);
        if ($validationResult['validated'] === false) {
            var_dump($validationResult);
            return 0;
        }

        // store and clip off the spent
        $txIns = $transaction[self::Inputs];
        unset($transaction[self::Inputs]);

        // store and clip off the unspent
        $txOuts = $transaction[self::Outputs];
        unset($transaction[self::Outputs]);

        try {
            $this->db->startTransaction();
            $this->db->insert('mempool_transactions', $transaction);
            $id = $this->db->insertId();

            // add txIn
            foreach ($txIns as $txIn) {
                $txIn['transaction_id'] = $transaction['transaction_id'];
                $txOut = $this->db->queryFirstRow(
                    'SELECT transaction_id,tx_id,address,value,script,lock_height,hash FROM transaction_outputs WHERE spent=0 AND transaction_id=%s AND tx_id=%i',
                    $txIn['previous_transaction_id'], $txIn['previous_tx_out_id']
                );

                if ($txOut === null) {
                    throw new MeekroDBException("There is no unspent matching transaction");
                }

                // run script
                $result = $this->transaction->unlockTransaction($txIn, $txOut);
                if (!$result) {
                    throw new MeekroDBException("Cannot unlock script");
                }
                $this->db->insert('mempool_inputs', $txIn);
            }

            // add txOut
            foreach ($txOuts as $txOut) {
                $txOut['transaction_id'] = $transaction['transaction_id'];
                $this->db->insert('mempool_outputs', $txOut);
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
        $transaction_id = $this->db->queryFirstField('SELECT transaction_id FROM mempool_transactions WHERE id=%i', $id);
        if ($transaction_id !== null) {
            $transaction = $this->getByTransactionId($transaction_id);
        }
        return $transaction;
    }

    public function getByTransactionId(string $transactionId): array|null
    {
        $transaction = $this->db->queryFirstRow('SELECT transaction_id,date_created,peer,height,version,signature,public_key FROM mempool_transactions WHERE transaction_id=%s', $transactionId);
        if ($transaction !== null) {
            $txIns = $this->db->query("SELECT tx_id,previous_transaction_id,previous_tx_out_id,script FROM mempool_inputs WHERE transaction_id=%s", $transactionId);
            $txOuts = $this->db->query("SELECT tx_id,address,value,script,lock_height,hash FROM mempool_outputs WHERE transaction_id=%s", $transactionId);

            // attach the details
            $transaction[self::Inputs] = $txIns;
            $transaction[self::Outputs] = $txOuts;
        }

        return $transaction;
    }

    public function delete(int $id): bool
    {
        $result = false;
        try {
            $this->db->startTransaction();

            // grab the record for the tx id
            $transaction = $this->get($id);

            $this->db->delete('mempool_transactions', "id=%s", $id);
            $this->db->delete('mempool_inputs', "transaction_id=%s", $transaction['transaction_id']);
            $this->db->delete('mempool_outputs', "transaction_id=%s", $transaction['transaction_id']);
            $this->db->commit();
            $result = true;
        } catch (MeekroDBException|Exception) {
            $this->db->rollback();
        }
        return $result;
    }

    public function getAllTransactions(): array
    {
        // get the current mempool transactions
        $mempoolRecords = [];
        $transactions = $this->db->query('SELECT transaction_id from mempool_transactions;');
        foreach ($transactions as $transaction) {
            $mempoolRecords[] = $this->getByTransactionId($transaction['transaction_id']);
        }
        return $mempoolRecords;
    }
}