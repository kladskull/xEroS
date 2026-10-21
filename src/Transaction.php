<?php declare(strict_types=1);

namespace Blockchain;

use Exception;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use PDO;
use Throwable;
use function bcadd;
use function bccomp;
use function bin2hex;
use function count;
use function ksort;
use function str_pad;
use function strlen;

/**
 * Class Transaction
 * @package Blockchain
 */
class Transaction
{
    private PDO $db;
    private Pow $pow;

    public const INPUTS = 'txIn';
    public const OUTPUTS = 'txOut';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->pow = new Pow();
    }

    /**
     * Builds the canonical text of a transaction. Every consensus field is included, json_encode keeps the
     * fields separated so two different transactions can never produce the same text.
     *
     * @param array $transaction
     * @param bool $withInputScripts false when building the text that input scripts sign
     * @return string
     */
    public function canonicalText(array $transaction, bool $withInputScripts): string
    {
        $inputs = [];

        foreach (self::sortTx($transaction[self::INPUTS] ?? []) as $txIn) {
            $inputs[] = [
                (string)($txIn['tx_id'] ?? ''),
                (string)($txIn['previous_transaction_id'] ?? ''),
                (string)($txIn['previous_tx_out_id'] ?? ''),
                $withInputScripts ? $this->scriptDigest((string)($txIn['script'] ?? '')) : '',
            ];
        }

        $outputs = [];

        foreach (self::sortTx($transaction[self::OUTPUTS] ?? []) as $txOut) {
            $outputs[] = [
                (string)($txOut['tx_id'] ?? ''),
                (string)($txOut['address'] ?? ''),
                (string)($txOut['value'] ?? ''),
                $this->scriptDigest((string)($txOut['script'] ?? '')),
                (string)($txOut['lock_height'] ?? ''),
            ];
        }

        return json_encode([
            Config::getNetworkIdentifier(),
            (string)($transaction['version'] ?? ''),
            (string)($transaction['date_created'] ?? ''),
            (string)($transaction['height'] ?? ''),
            (string)($transaction['peer'] ?? ''),
            (string)($transaction['public_key'] ?? ''),
            $inputs,
            $outputs,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The size of a transaction in bytes, counted the same way on every node whatever JSON it arrived in.
     *
     * @param array $transaction
     * @return int
     */
    public static function size(array $transaction): int
    {
        $text = static fn ($value): string => is_scalar($value) ? (string)$value : '';
        $parts = [];

        foreach (['version', 'date_created', 'height', 'peer', 'public_key', 'signature'] as $field) {
            $parts[] = $text($transaction[$field] ?? '');
        }

        foreach ([self::INPUTS, self::OUTPUTS] as $section) {
            foreach (is_array($transaction[$section] ?? null) ? $transaction[$section] : [] as $tx) {
                foreach (['tx_id', 'previous_transaction_id', 'previous_tx_out_id', 'address', 'value', 'script',
                             'lock_height'] as $field) {
                    $parts[] = is_array($tx) ? $text($tx[$field] ?? '') : '';
                }
            }
        }

        return strlen(implode(',', $parts));
    }

    /**
     * The smallest fee for a transaction of $size bytes: a fee for every started 1000 bytes, and never less than
     * the flat minimum. Large transactions pay more, so filling blocks is not cheap.
     *
     * @param int $size
     * @return string
     */
    public static function minimumFee(int $size): string
    {
        $bySize = bcmul((string)intdiv($size + 999, 1000), Config::getFeePerKb());

        return bccomp($bySize, Config::getMinimumTransactionFee()) > 0 ? $bySize : Config::getMinimumTransactionFee();
    }

    /**
     * Scripts are compressed and the same script can be compressed in more than one way, so ids and signatures
     * cover the script itself. Otherwise anyone relaying a transaction could change its id.
     *
     * @param string $encoded
     * @return string
     */
    private function scriptDigest(string $encoded): string
    {
        $decoded = strlen($encoded) > Config::getMaxScriptLength()
            ? false
            : (new Script([]))->decodeScript($encoded, Config::getMaxDecodedScriptLength());

        // a script that does not decode is refused by validate(), it still needs a stable digest
        return $decoded === false ? 'x' . hash('sha256', $encoded) : hash('sha256', $decoded);
    }

    /**
     * The transaction id is the hash of the whole transaction, so the merkle root commits to its contents.
     *
     * @param array $transaction
     * @return string
     */
    public function computeId(array $transaction): string
    {
        return bin2hex($this->pow->doubleSha256($this->canonicalText($transaction, true)));
    }

    /**
     * The hash that input scripts and the transaction signature sign. Input scripts are left out because they
     * carry the signatures.
     *
     * @param array $transaction
     * @return string
     */
    public function signatureHash(array $transaction): string
    {
        return bin2hex($this->pow->doubleSha256($this->canonicalText($transaction, false)));
    }

    /**
     * Finds an unspent output on the active chain.
     *
     * @param string $transactionId
     * @param int $txId
     * @return array|null
     */
    public function getUnspentOutput(string $transactionId, int $txId): array|null
    {
        $query = 'SELECT o.`block_id`,o.`transaction_id`,o.`tx_id`,o.`address`,o.`value`,o.`script`,' .
            'o.`lock_height`,o.`hash` FROM transaction_outputs o INNER JOIN blocks b ON b.`block_id`=o.`block_id` ' .
            'WHERE b.`orphan`=0 AND o.`spent`=0 AND o.`transaction_id`=:transaction_id AND o.`tx_id`=:tx_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'transaction_id',
            value: $transactionId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64
        );
        $stmt = DatabaseHelpers::filterBind($stmt, 'tx_id', $txId, DatabaseHelpers::INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @param string $transactionId
     * @return bool
     */
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

    /**
     * @param string $blockId
     * @param string $transactionId
     * @return bool
     */
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

    /**
     * @param int $id
     * @return array|null
     */
    public function get(int $id): array|null
    {
        $query = 'SELECT `id`,`block_id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`,' .
            '`public_key` FROM transactions WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT, 0);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @param string $blockId
     * @param string $transactionId
     * @return array|null
     */
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

    /**
     * @param string $blockId
     * @param string $transactionId
     * @return array
     */
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

    /**
     * @param string $blockId
     * @param string $transactionId
     * @return array
     */
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

    /**
     * @param string $blockId
     * @return array
     */
    public function getTransactionsByBlockId(string $blockId): array
    {
        $returnTransactions = [];
        $query = 'SELECT `id`,`block_id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`' .
            ',`public_key` FROM transactions WHERE `block_id` = :block_id';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            $stmt,
            'block_id',
            $blockId,
            DatabaseHelpers::ALPHA_NUMERIC,
            64
        );
        $stmt->execute();

        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($transactions as $transaction) {
            // attach the details
            $transaction[self::INPUTS] = $this->getTransactionInputs($blockId, $transaction['transaction_id']) ?: [];
            $transaction[self::OUTPUTS] = $this->getTransactionOutputs($blockId, $transaction['transaction_id']) ?: [];
            $returnTransactions[] = $transaction;
        }

        return $returnTransactions;
    }

    /**
     * @param array $transaction
     * @return array
     */
    public function stripInternalFields(array $transaction): array
    {
        $inputs = [];
        // remove internal columns
        unset($transaction['id'], $transaction['block_id']);

        // remove internal columns
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

    /**
     * Validates a transaction against the active chain.
     *
     * @param array $transaction
     * @param int|null $height the height of the block the transaction is going into, defaults to the next block
     * @param bool $checkInputs false to skip the checks that depend on the chain state, used for blocks on a
     *                          side branch, which get the full check if they ever join the chain
     * @return array
     * @throws Exception
     */
    #[ArrayShape(['validated' => "bool", 'reason' => "string", 'fee' => "string"])]
    public function validate(array $transaction, ?int $height = null, bool $checkInputs = true): array
    {
        $address = new Address();
        $openSsl = new OpenSsl();

        $fail = static function (string $reason): array {
            return ['validated' => false, 'reason' => $reason, 'fee' => '0'];
        };

        foreach (['transaction_id', 'date_created', 'public_key', 'signature', 'version', 'height', 'peer'] as $field) {
            if (!isset($transaction[$field]) || !is_scalar($transaction[$field])) {
                return $fail('"' . $field . '" is missing,');
            }
        }

        if (!isset($transaction[self::INPUTS]) || !is_array($transaction[self::INPUTS])) {
            return $fail(self::INPUTS . ' is missing,');
        }

        if (!isset($transaction[self::OUTPUTS]) || !is_array($transaction[self::OUTPUTS])
            || count($transaction[self::OUTPUTS]) === 0) {
            return $fail('at least one output is required,');
        }

        if (self::size($transaction) > Config::getMaxTransactionBytes()) {
            return $fail('transaction is larger than ' . Config::getMaxTransactionBytes() . ' bytes,');
        }

        $height ??= (new Block())->getCurrentHeight() + 1;
        $version = (string)$transaction['version'];
        $isCoinbase = $version === TransactionVersion::COINBASE;
        $result = true;
        $reason = '';

        if ($version !== TransactionVersion::COINBASE && $version !== TransactionVersion::TRANSFER) {
            $reason .= 'unknown version,';
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

        // the database keeps letters and digits only, anything else would change the id when a block is reloaded
        if (strlen((string)$transaction['peer']) > 40
            || ((string)$transaction['peer'] !== '' && !ctype_alnum((string)$transaction['peer']))) {
            $reason .= '"peer" must be 40 letters or digits or less,';
            $result = false;
        }

        // no transactions before the genesis
        if ((int)$transaction['date_created'] < Config::getGenesisDate()) {
            $reason .= 'date created is before genesis block ' .
                $transaction['date_created'] . ' < ' . Config::getGenesisDate() . ',';
            $result = false;
        }

        // tx_ids must be 0..n-1 with no duplicates, otherwise sorting would silently drop entries
        foreach ([self::INPUTS, self::OUTPUTS] as $section) {
            $ids = [];

            foreach ($transaction[$section] as $tx) {
                $ids[] = (string)($tx['tx_id'] ?? '');
            }

            if ($ids !== [] && array_diff(array_map('strval', range(0, count($ids) - 1)), $ids) !== []) {
                $reason .= $section . ' tx_id values must run from 0 with no gaps or duplicates,';
                $result = false;
            }
        }

        // the id must be the hash of the contents
        if ($transaction['transaction_id'] !== $this->computeId($transaction)) {
            $reason .= 'transaction_id does not match the transaction contents,';
            $result = false;
        }

        $signatureHash = $this->signatureHash($transaction);

        if (!$openSsl->verifySignature(
            $signatureHash,
            (string)$transaction['signature'],
            $openSsl->formatPem((string)$transaction['public_key'], false)
        )) {
            $reason .= 'Invalid signature,';
            $result = false;
        }

        $totalOutputs = '0';

        foreach ($transaction[self::OUTPUTS] as $txOut) {
            $value = (string)($txOut['value'] ?? '');
            $lockHeight = (string)($txOut['lock_height'] ?? '');

            if (!$address->validateAddress((string)($txOut['address'] ?? ''))) {
                $reason .= 'invalid address in output,';
                $result = false;
            }

            if (!$this->isScriptSizeValid((string)($txOut['script'] ?? ''))) {
                $reason .= 'output script is invalid or larger than the max allowed,';
                $result = false;
            }

            if (!ctype_digit($lockHeight)) {
                $reason .= 'Invalid lock_height value,';
                $result = false;
            } elseif ($isCoinbase && (int)$lockHeight < (int)$transaction['height'] + Config::getLockHeight()) {
                $reason .= 'coinbase outputs must be locked for ' . Config::getLockHeight() . ' blocks,';
                $result = false;
            }

            if (!ctype_digit($value) || bccomp($value, '0') <= 0) {
                $reason .= 'output value must be a whole number greater than 0,';
                $result = false;
                continue;
            }

            $totalOutputs = bcadd($totalOutputs, $value);
        }

        if ($isCoinbase) {
            if (count($transaction[self::INPUTS]) !== 0) {
                $reason .= 'the coinbase cannot have inputs,';
                $result = false;
            }

            if ((int)$transaction['height'] !== $height) {
                $reason .= 'the coinbase height must match the block height,';
                $result = false;
            }

            // the reward and fees are checked against the whole block in Block::validate()
            return ['validated' => $result, 'reason' => $reason, 'fee' => '0'];
        }

        if (count($transaction[self::INPUTS]) === 0) {
            $reason .= 'a transfer needs at least one input,';
            $result = false;
        }

        $totalInputs = '0';
        $seen = [];

        foreach ($transaction[self::INPUTS] as $txIn) {
            $previousId = (string)($txIn['previous_transaction_id'] ?? '');
            $previousOut = (string)($txIn['previous_tx_out_id'] ?? '');

            if ($previousId === '' || !ctype_digit($previousOut)) {
                $reason .= 'input is missing its previous output,';
                $result = false;
                continue;
            }

            // the same output may only be spent once
            $outpoint = $previousId . ':' . $previousOut;

            if (isset($seen[$outpoint])) {
                $reason .= 'output ' . $outpoint . ' is spent twice,';
                $result = false;
                continue;
            }

            $seen[$outpoint] = true;

            if (!$this->isScriptSizeValid((string)($txIn['script'] ?? ''))) {
                $reason .= 'input script is invalid or larger than the max allowed,';
                $result = false;
                continue;
            }

            if (!$checkInputs) {
                continue;
            }

            $unspent = $this->getUnspentOutput($previousId, (int)$previousOut);

            if ($unspent === null) {
                $reason .= 'output ' . $outpoint . ' does not exist or is already spent,';
                $result = false;
                continue;
            }

            if ($height < (int)$unspent['lock_height']) {
                $reason .= 'output ' . $outpoint . ' is locked until height ' . $unspent['lock_height'] . ',';
                $result = false;
                continue;
            }

            // the spender must satisfy the output's script
            if (!$this->unlockTransaction($txIn, $unspent, $signatureHash)) {
                $reason .= 'cannot unlock output ' . $outpoint . ',';
                $result = false;
                continue;
            }

            $totalInputs = bcadd($totalInputs, (string)$unspent['value']);
        }

        if (!$checkInputs) {
            return ['validated' => $result, 'reason' => $reason, 'fee' => '0'];
        }

        $fee = bcsub($totalInputs, $totalOutputs);

        $minimumFee = self::minimumFee(self::size($transaction));

        if (bccomp($fee, $minimumFee) < 0) {
            $reason .= 'fee (' . $fee . ') is less than minimum (' . $minimumFee . '),';
            $result = false;
        }

        return ['validated' => $result, 'reason' => $reason, 'fee' => $result ? $fee : '0'];
    }

    /**
     * @param array $transactions
     * @return array
     */
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

    /**
     * @param array $txs
     * @return array
     */
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

    /**
     * @param array $transaction
     * @return string
     */
    public function generateSignatureText(array $transaction): string
    {
        return $this->signatureHash($transaction);
    }

    /**
     * @param $signatureText
     * @param $publicKey
     * @param $privateKey
     * @return string
     */
    public function generateSignature($signatureText, $publicKey, $privateKey): string
    {
        return (new OpenSsl())->signAndVerifyData($signatureText, $publicKey, $privateKey);
    }

    /**
     * @param array $transaction
     * @param $publicKey
     * @param $privateKey
     * @return string
     */
    public function signTransaction(array $transaction, $publicKey, $privateKey): string
    {
        return $this->generateSignature($this->generateSignatureText($transaction), $publicKey, $privateKey);
    }

    /**
     * Runs the spender's input script followed by the output's locking script. The input script may only push
     * data, so it cannot change the outcome of the locking script.
     *
     * @param array $input
     * @param array $output
     * @param string $signatureHash the signature hash of the spending transaction
     * @return bool
     */
    public function unlockTransaction(array $input, array $output, string $signatureHash): bool
    {
        $script = new Script(['<hash>' => $signatureHash]);

        try {
            $inputScript = $script->decodeScript((string)($input['script'] ?? ''));
            $outputScript = $script->decodeScript((string)($output['script'] ?? ''));
        } catch (Throwable) {
            return false;
        }

        if ($inputScript === false || $outputScript === false || !$script->isPushOnly($inputScript)) {
            return false;
        }

        // a few compressed bytes can expand into a script that takes seconds to run
        if (strlen($inputScript) + strlen($outputScript) > Config::getMaxDecodedScriptLength()) {
            return false;
        }

        // any error inside the script, such as bad math input, means the script fails
        try {
            if (!$script->loadScript($inputScript . $outputScript)) {
                return false;
            }

            return $script->run(false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * True if the encoded script decodes and stays within the decoded size limit.
     *
     * @param string $encoded
     * @return bool
     */
    public function isScriptSizeValid(string $encoded): bool
    {
        if (strlen($encoded) > Config::getMaxScriptLength()) {
            return false;
        }

        try {
            $decoded = (new Script([]))->decodeScript($encoded);
        } catch (Throwable) {
            return false;
        }

        return $decoded !== false && strlen($decoded) <= Config::getMaxDecodedScriptLength();
    }
}
