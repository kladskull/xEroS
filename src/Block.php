<?php declare(strict_types=1);

namespace Blockchain;

use Exception;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Class Block
 */
class Block
{
    public const MAX_LIFETIME_BLOCKS = 9999999999999;

    private Address $address;
    private Mempool $mempool;
    private Merkle $merkle;
    private array $reversedTransactions = [];
    private OpenSsl $openSsl;
    private PDO $db;
    private Peer $peer;
    private DataStore $store;
    private Pow $pow;
    private Script $script;
    public Transaction $transaction;
    private TransferEncoding $transferEncoding;

    /**
     * The first block of each network. Make a new one with genesis.php and paste its output here.
     */
    private const GENESIS = [
        'live' => [ // stale, made before the block format changed, re-mine before launch
            'date' => 1644364863,
            'public_key' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA6iS30IjUFtg5UdqGn7bMWtO2yf3nAhG85xJK9Ro0DByJhr6WB94TDhrA' .
                'MjWK4bwcYO08M6yHvsO6wVUxB6KuONNYrEHOmHqA3lYS+Kz9dfwWN+ymwLbOgDevZ33PKahV6JpXTu5wHKFdPJctLcaWmy0gI2Yj' .
                'srrZw8mXiiArNOT3662UvZdFgyMM1SSuPoE/ZXvDjRIXz/4RMnKCE1GH5cbtBheC1yy8XvuZkK9IN+he1osz29hEFlYVtIF6g+wh' .
                '59ZGxzrZoA7bcO5mGY491mZFCzHMu0nVXnokAsiKx4YK0KxyV5ETk4P8qili7MizbKVhS9mUinVkU9l16dhreQIDAQAB',
            'signature' => '0aa6338178f42f3fc6a688c7a284c6d9f3bd4f973c067e3c71d6f49d6a4ccab40753f799e6ea3ad61a678727dbd7329067af' .
                '7c6626775083bbe323ee8baa61fb586808cb012fa3592018e0f1397ff636ad85c05553c1079f38b071e9d52b8da5c39fa85a' .
                '12ebbdd6224e008addaaaa149932ceed3b8bb307937530f91a54edaf7095877bc13116c79ef1bb480e35812ad77e4c049ec6' .
                '858f461017fd3c0ce27956971c13c73aca745b1d61f8a0700b0d5a17470c4d983622c525d6691a80d027e79018ae618ea8ab' .
                '597c19e7d030b497fdffdeef308bc51d69e2ccc068223a713e67b2114fc609b70263a9562a72ab1ce182686447c2369ddc6c' .
                '69295c476414',
            'nonce' => '5bd5d65',
            'hash' => '0000000f8fd419d81b0eb5ed9512a1cc42910c94a5034bd543653edd83fcb4f3',
        ],
        'test' => [ // stale, made before the block format changed, re-mine before launch
            'date' => 1644364863,
            'public_key' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA6iS30IjUFtg5UdqGn7bMWtO2yf3nAhG85xJK9Ro0DByJhr6WB94TDhrA' .
                'MjWK4bwcYO08M6yHvsO6wVUxB6KuONNYrEHOmHqA3lYS+Kz9dfwWN+ymwLbOgDevZ33PKahV6JpXTu5wHKFdPJctLcaWmy0gI2Yj' .
                'srrZw8mXiiArNOT3662UvZdFgyMM1SSuPoE/ZXvDjRIXz/4RMnKCE1GH5cbtBheC1yy8XvuZkK9IN+he1osz29hEFlYVtIF6g+wh' .
                '59ZGxzrZoA7bcO5mGY491mZFCzHMu0nVXnokAsiKx4YK0KxyV5ETk4P8qili7MizbKVhS9mUinVkU9l16dhreQIDAQAB',
            'signature' => '0aa6338178f42f3fc6a688c7a284c6d9f3bd4f973c067e3c71d6f49d6a4ccab40753f799e6ea3ad61a678727dbd7329067af' .
                '7c6626775083bbe323ee8baa61fb586808cb012fa3592018e0f1397ff636ad85c05553c1079f38b071e9d52b8da5c39fa85a' .
                '12ebbdd6224e008addaaaa149932ceed3b8bb307937530f91a54edaf7095877bc13116c79ef1bb480e35812ad77e4c049ec6' .
                '858f461017fd3c0ce27956971c13c73aca745b1d61f8a0700b0d5a17470c4d983622c525d6691a80d027e79018ae618ea8ab' .
                '597c19e7d030b497fdffdeef308bc51d69e2ccc068223a713e67b2114fc609b70263a9562a72ab1ce182686447c2369ddc6c' .
                '69295c476414',
            'nonce' => '1192faa7',
            'hash' => '0000000c7a00d1ea28b8986d5a1b3226e4493d502f68b302d074c97489c06c67',
        ],
        'dev' => [
            'date' => 1792594643,
            'public_key' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAveRPU9KVhQruDQDrsO8ZFg/a3mg2HxskENSM1PONewtOkIjsCQ5iQAiA' .
                'EmjNYiy48PRAx/xhfZucPLPIKRyWX2SimMvs1kyptVWvvx9cvCtdMuaXiDxQdiJU8DbH15OCTUKF7NyUpyJh3qBvFdh2qnBDH3jP' .
                'N9zQFUJPsAfF0E7ZS0GHdoPHUmoS/U7f263oLwABvgohMCPSi/C/ZxBsrmSpbT59+C8YKT1A1FRAi8/Xqb93epvH+3wY9dt7oX4B' .
                'rpx6HHPdPZ+wIvz2ErSjdNBpYmz/PXE6JtGDGJRbBfORUuMx2e8iDyYk+wJwncvuqFcefLAjJEWqdUankbleYQIDAQAB',
            'signature' => '91a23865574151b7832787a9ac415c8057f834bc5478b6b979b4388d168302bd127f067f9221235f79428e8adda5737b0502' .
                '357f947b268bea161944911f365923dc3e6dd9d1c15d0cee6be99c91a7231f65a79b52471d979cfc559731630cfc178e8655' .
                '5821715e8a85b5f4979e52e98077ba569379d4e773a7b439337ae611bd11ef4ee6124a0da9117752a0229619dfc8dabb19c2' .
                'ca739560d4e9e800d8462f13fec53e6dac77680ee2000746619b947c4f108e42a95bf2749632c9981c86e6ad88f15ee03163' .
                '362f59962c3710561ca795557bdf0277f8970b485e81329b79645fb8c3f941fec58e23bd1181c738595c68d2ec0fc2fd6b41' .
                '2127d1c9d573',
            'nonce' => '7609',
            'hash' => '0000041d0e27e05bdd4c6e48857edbb7f67d8f31ec85661868b5973309ca5bba',
        ],
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->address = new Address();
        $this->openSsl = new OpenSsl();
        $this->peer = new Peer();
        $this->script = new Script([]);
        $this->merkle = new Merkle();
        $this->transaction = new Transaction();
        $this->transferEncoding = new TransferEncoding();
        $this->store = new DataStore();
        $this->mempool = new Mempool();
        $this->pow = new Pow();
    }

    /**
     * @param int $height
     * @return int
     */
    public static function filterBlockHeight(int $height): int
    {
        if ($height <= 0 || $height > self::MAX_LIFETIME_BLOCKS) {
            $height = 1;
        }

        return $height;
    }

    /**
     * The genesis block for an environment, built the same way as any other block from the fixed values above.
     *
     * @param string $environment
     * @return array empty if the environment is unknown
     */
    public function genesis(string $environment): array
    {
        $environment = Config::normalizeEnvironment($environment);
        $fixed = self::GENESIS[$environment] ?? null;

        if ($fixed === null) {
            return [];
        }

        $block = $this->assembleGenesis($environment, $fixed['date'], $fixed['public_key'], $fixed['signature']);
        $block['nonce'] = $fixed['nonce'];
        $block['hash'] = $fixed['hash'];

        return $block;
    }

    /**
     * A new, signed but unmined genesis block. Used by genesis.php.
     *
     * @param string $environment
     * @param int $date
     * @param string $publicKey PEM
     * @param string $privateKey PEM
     * @return array
     */
    public function createGenesis(string $environment, int $date, string $publicKey, string $privateKey): array
    {
        $publicKeyRaw = $this->openSsl->stripPem($publicKey);
        $transaction = $this->genesisTransaction($date, $publicKeyRaw);
        $signature = $this->transaction->signTransaction($transaction, $publicKey, $privateKey);

        return $this->assembleGenesis(Config::normalizeEnvironment($environment), $date, $publicKeyRaw, $signature);
    }

    /**
     * @param int $height
     * @param string $publicKey
     * @param string $privateKey
     * @return array
     * @throws Exception
     */
    public function getCandidateBlock(int $height, string $publicKey, string $privateKey): array
    {
        $prevBlock = $this->getByHeight($height - 1);
        $difficulty = $this->getNextDifficulty($prevBlock);
        $transactions = $this->mempool->getAllTransactions($height);

        // must be after the median time of the blocks before it, which can run ahead of the clock briefly
        $date = max(time(), $this->getMedianTimePast($prevBlock) + 1);
        $publicKeyRaw = $this->openSsl->stripPem($publicKey);

        // keep the valid mempool transactions, never spending the same output twice in one block
        $validTransactions = [];
        $spent = [];
        $fees = '0';

        foreach ($transactions as $tx) {
            $result = $this->transaction->validate($tx, $height);

            if ($result['validated'] !== true) {
                Console::log('Skipping invalid mempool transaction: ' . ($tx['transaction_id'] ?? ''));
                continue;
            }

            $outpoints = [];

            foreach ($tx[Transaction::INPUTS] as $txIn) {
                $outpoints[] = $txIn['previous_transaction_id'] . ':' . $txIn['previous_tx_out_id'];
            }

            if (array_intersect_key($spent, array_flip($outpoints)) !== []) {
                continue;
            }

            foreach ($outpoints as $outpoint) {
                $spent[$outpoint] = true;
            }

            $fees = bcadd($fees, $result['fee']);
            $validTransactions[] = $tx;
        }

        // pay to public Key hash
        $partialAddress = $this->transferEncoding->binToHex($this->address->createPartial($this->openSsl->formatPem(
            $publicKeyRaw,
            false
        )));
        $scriptText = 'mov ' . $partialAddress .
            ',ax;adha ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;vsig ax,<hash>,bx;';
        $reward = bcadd($this->getRewardValue($height), $fees, 0);
        $lockHeight = $height + Config::getLockHeight();

        $coinbase = [
            'date_created' => $date,
            'public_key' => $publicKeyRaw,
            'peer' => $this->peer->getUniquePeerId(),
            'version' => TransactionVersion::COINBASE,
            'height' => $height,
            Transaction::INPUTS => [],
            Transaction::OUTPUTS => [
                [
                    'tx_id' => '0',
                    'address' => $this->address->create($publicKey),
                    'value' => $reward,
                    'script' => $this->script->encodeScript($scriptText),
                    'lock_height' => $lockHeight,
                ]
            ],
        ];
        $coinbase['transaction_id'] = $this->transaction->computeId($coinbase);
        $coinbase[Transaction::OUTPUTS][0]['hash'] = bin2hex($this->pow->doubleSha256($coinbase['transaction_id'] . '0'));

        try {
            $coinbase['signature'] = $this->transaction->signTransaction($coinbase, $publicKey, $privateKey);
        } catch (Exception $e) {
            Console::log($e->getMessage());
            return [];
        }

        // put the coinbase as the first transaction
        array_unshift($validTransactions, $coinbase);

        // calculate the merkle root
        $validTransactions = Transaction::sort($validTransactions); // sort the array
        $merkleRoot = $this->merkle->computeMerkleHash($validTransactions);
        $blockId = $this->generateId(
            Config::getNetworkIdentifier(),
            $prevBlock['block_id'],
            $date,
            $height,
            $merkleRoot
        );

        return [
            'network_id' => Config::getNetworkIdentifier(),
            'block_id' => $blockId,
            'previous_block_id' => $prevBlock['block_id'],
            'date_created' => $date,
            'height' => $height,
            'difficulty' => $difficulty, // use the current block to decide
            'merkle_root' => $merkleRoot,
            'transaction_count' => count($validTransactions),
            'transactions' => $validTransactions,
            'nonce' => '',
            'hash' => '',
            'previous_hash' => $prevBlock['hash'],
        ];
    }

    /**
     * The merkle root is part of the id, so two miners finding a block at the same height in the same second
     * still get different ids.
     *
     * @param string $networkId
     * @param string $previousBlockId
     * @param int $date
     * @param int $height
     * @param string $merkleRoot
     * @return string
     */
    #[Pure]
    public function generateId(
        string $networkId,
        string $previousBlockId,
        int $date,
        int $height,
        string $merkleRoot = ''
    ): string {
        return bin2hex($this->pow->doubleSha256(
            json_encode([$networkId, $previousBlockId, $date, $height, $merkleRoot], JSON_THROW_ON_ERROR)
        ));
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function get(int $id): ?array
    {
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,' .
            '`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan` FROM blocks ' .
            'WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param string $blockId
     * @return array
     */
    public function getByBlockId(string $blockId): array
    {
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,' .
            '`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE ' .
            '`block_id` = :block_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            $stmt,
            'block_id',
            $blockId,
            DatabaseHelpers::ALPHA_NUMERIC,
            64
        );
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param string $previousBlockId
     * @return array
     */
    public function getByPreviousBlockId(string $previousBlockId): array
    {
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,' .
            '`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan` FROM blocks ' .
            'WHERE `orphan`=0 AND `previous_block_id` = :previous_block_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            $stmt,
            'previous_block_id',
            $previousBlockId,
            DatabaseHelpers::ALPHA_NUMERIC,
            64
        );
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return int
     */
    public function getCurrentHeight(): int
    {
        $query = 'SELECT `height` FROM blocks WHERE `orphan`=0 ORDER BY `height` DESC LIMIT 1';
        $stmt = $this->db->query($query);

        return $stmt->fetchColumn() ?: 0;
    }

    /**
     * @param int $height
     * @return array|null
     */
    public function getByHeight(int $height): ?array
    {
        // prepare the statement
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,' .
            '`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE ' .
            '`orphan` = 0 AND `height` = :height LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'height', $height, DatabaseHelpers::INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array|null
     */
    public function getCurrent(): ?array
    {
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,' .
            '`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE ' .
            '`orphan`=0 ORDER BY height DESC LIMIT 1';
        $stmt = $this->db->query($query);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param string $blockId
     * @param bool $previousBlockId
     * @return array
     */
    public function assembleFullBlock(string $blockId, bool $previousBlockId = false): array
    {
        $transaction = new Transaction();
        $currBlock = !$previousBlockId ? $this->getByBlockId($blockId) : $this->getByPreviousBlockId($blockId);

        if (!empty($currBlock)) {
            // remove internal fields
            $currBlock = $this->stripInternalFields($currBlock);

            // get the current height to calculate confirmations
            $currentHeight = $this->getCurrentHeight();
            $currBlock['confirmations'] = $currentHeight - $currBlock['height'];
            $currBlock['transactions'] = array_map(
                [$transaction, 'stripInternalFields'],
                $transaction->getTransactionsByBlockId($currBlock['block_id'])
            );
        }

        return $currBlock ?: [];
    }

    /**
     * @param array $block
     * @return array
     */
    public function stripInternalFields(array $block): array
    {
        // remove internal columns
        unset($block['id'], $block['orphan']);

        return $block;
    }

    /**
     * @param array $block
     * @return array
     */
    #[ArrayShape(['validated' => "bool", 'reason' => "string"])]
    public function validateFullBlock(array $block, bool $checkSpends = true): array
    {
        $transactions = $block['transactions'];
        unset($block['transactions']);

        return $this->validate($block, $transactions, (int)$block['transaction_count'], $checkSpends);
    }

    /**
     * @param string $reason
     * @param bool $result
     * @return array
     */
    #[ArrayShape(['validated' => "bool", 'reason' => "string"])]
    private function returnValidateResult(string $reason, bool $result): array
    {
        return [
            'validated' => $result,
            'reason' => $reason
        ];
    }

    /**
     * @param array $block
     * @param array $transactions
     * @param int $transactionCount
     * @return array
     * @throws Exception
     */
    #[ArrayShape(['validated' => "bool", 'reason' => "string"])]
    public function validate(
        array $block,
        array $transactions,
        int $transactionCount,
        bool $checkSpends = true
    ): array {
        foreach (['network_id', 'block_id', 'previous_block_id', 'date_created', 'height', 'difficulty',
                     'merkle_root', 'transaction_count', 'previous_hash', 'hash', 'nonce'] as $field) {
            if (!isset($block[$field]) || !is_scalar($block[$field])) {
                return $this->returnValidateResult('"' . $field . '" is missing,', false);
            }
        }

        $reason = '';
        $result = true;
        $height = (int)$block['height'];

        // the genesis block is fixed, it is trusted when it matches ours exactly
        if ($height === 1) {
            $genesis = $this->genesis(Config::getEnvironment());
            $isGenesis = isset($genesis['hash']) && $block['previous_block_id'] === ''
                && $block['block_id'] === $genesis['block_id'] && $block['hash'] === $genesis['hash'];

            return $this->returnValidateResult($isGenesis ? '' : 'invalid genesis block,', $isGenesis);
        }

        if ($height < 1) {
            return $this->returnValidateResult('invalid height,', false);
        }

        if ($block['network_id'] !== Config::getNetworkIdentifier()) {
            $reason .= 'networkId mismatch,';
            $result = false;
        }

        // the block must extend a block we know about
        $previousBlock = $this->getByBlockId((string)$block['previous_block_id']);

        if (empty($previousBlock)) {
            return $this->returnValidateResult($reason . 'unknown previous block,', false);
        }

        if ((int)$previousBlock['height'] !== $height - 1 || $previousBlock['hash'] !== $block['previous_hash']) {
            $reason .= 'previous block does not link,';
            $result = false;
        }

        if ($block['block_id'] !== $this->generateId(
                Config::getNetworkIdentifier(),
                (string)$block['previous_block_id'],
                (int)$block['date_created'],
                $height,
                (string)$block['merkle_root']
            )) {
            $reason .= 'block_id does not match the block,';
            $result = false;
        }

        // ensure a sane time
        if ((int)$block['date_created'] > time() + 60) {
            $reason .= 'block from the future,';
            $result = false;
        }

        // the time must be after the median of the last 11 blocks, so miners cannot drag the clock back
        if ((int)$block['date_created'] <= $this->getMedianTimePast($previousBlock)) {
            $reason .= 'block time is not after the median of the previous blocks,';
            $result = false;
        }

        // check difficulty against the block's own parent
        if ($this->getNextDifficulty($previousBlock) !== (int)$block['difficulty']) {
            $reason .= 'difficulty difference,';
            $result = false;
        }

        // check the proof of work, the hash must be correct and it must meet the difficulty
        $pow = new Pow();

        if (!$pow->verifyPow((string)$block['hash'], $this->generateBlockHeader($block), (string)$block['nonce'])) {
            $reason .= 'Proof of work fail,';
            $result = false;
        }

        if (!$pow->meetsDifficulty((string)$block['hash'], (int)$block['difficulty'])) {
            $reason .= 'hash does not meet the difficulty,';
            $result = false;
        }

        // we must have all the transactions, once each
        if ($transactionCount !== count($transactions)) {
            $reason .= 'transaction count mismatch,';
            $result = false;
        }

        $transactionIds = [];

        foreach ($transactions as $transaction) {
            $transactionIds[] = $transaction['transaction_id'] ?? '';
        }

        if (count(array_unique($transactionIds)) !== count($transactionIds)) {
            return $this->returnValidateResult($reason . 'duplicate transaction,', false);
        }

        $transactions = Transaction::sort($transactions);

        // check the transactions
        $t = new Transaction();
        $coinbase = null;
        $coinbaseRecords = 0;
        $fees = '0';
        $spent = [];

        foreach ($transactions as $transaction) {
            if ((string)($transaction['version'] ?? '') === TransactionVersion::COINBASE) {
                $coinbaseRecords++;
                $coinbase = $transaction;
            }

            $transactionReason = $t->validate($transaction, $height, $checkSpends);

            if (!$transactionReason['validated']) {
                $reason .= 'transaction validate failed: ' . $transactionReason['reason'] . ', ';
                $result = false;
                continue;
            }

            $fees = bcadd($fees, $transactionReason['fee']);

            // no output may be spent twice in the same block
            foreach ($transaction[Transaction::INPUTS] as $txIn) {
                $outpoint = $txIn['previous_transaction_id'] . ':' . $txIn['previous_tx_out_id'];

                if (isset($spent[$outpoint])) {
                    $reason .= 'output ' . $outpoint . ' is spent twice in the block,';
                    $result = false;
                }

                $spent[$outpoint] = true;
            }
        }

        if ($coinbaseRecords !== 1) {
            $reason .= 'blocks must have exactly 1 coinbase record,';
            $result = false;
        } elseif ($checkSpends) {
            // the coinbase may claim the reward plus the fees, but no more
            $coinbaseTotal = '0';

            foreach ($coinbase[Transaction::OUTPUTS] as $txOut) {
                if (ctype_digit((string)($txOut['value'] ?? ''))) {
                    $coinbaseTotal = bcadd($coinbaseTotal, (string)$txOut['value']);
                }
            }

            $allowed = bcadd($this->getRewardValue($height), $fees);

            if (bccomp($coinbaseTotal, $allowed) > 0) {
                $reason .= 'Reward ' . $coinbaseTotal . ' is greater than expected ' . $allowed . ',';
                $result = false;
            }
        }

        // test the merkle root
        $merkle = new Merkle();

        if ($block['merkle_root'] !== $merkle->computeMerkleHash($transactions)) {
            $reason .= 'merkle root issue,';
            $result = false;
        }

        return $this->returnValidateResult($reason, $result);
    }

    /**
     * The median time of the given block and the 10 before it.
     *
     * @param array $block
     * @return int
     */
    public function getMedianTimePast(array $block): int
    {
        $times = [];
        $current = $block;

        for ($i = 0; $i < 11 && !empty($current); $i++) {
            $times[] = (int)$current['date_created'];

            if ((string)$current['previous_block_id'] === '') {
                break;
            }

            $current = $this->getByBlockId((string)$current['previous_block_id']);
        }

        sort($times);

        return $times[intdiv(count($times), 2)];
    }

    /**
     * This function goes through all blocks and makes sure that all the records are sound.
     * @return void
     */
    public function checkIntegrity(): void
    {
        $height = $this->getCurrentHeight();
        $startHeight = (int)$this->store->getKey('integrity_height', 1);

        if ($startHeight < 1) {
            $startHeight = 1;
        }

        for ($i = $startHeight; $i <= $height; $i++) {
            $blockRecord = $this->getByHeight($i);

            if (empty($blockRecord)) {
                Console::log('Missing block at height ' . $i);
                continue;
            }

            $block = $this->assembleFullBlock($blockRecord['block_id']);
            $result = $this->validateFullBlock($block);

            if (!$result['validated']) {
                Console::log('Invalid block detected at height ' . $i . ': ' . $result['reason']);
            }

            $this->store->add('integrity_height', $i + 1);
        }
    }

    /**
     * This function determines if the two given blocks are at a fork
     *
     * [block_1]     [block_2]
     *          \   /
     *           \/
     *           |
     * @param array|null $block1
     * @param array|null $block2
     * @return bool
     */
    private function isFork(?array $block1, ?array $block2): bool
    {
        if (empty($block1) || empty($block2)) {
            return false;
        }

        return $block1['block_id'] !== $block2['block_id'];
    }

    /**
     * This cleans the blockchain by removing all orphan blocks & transactions. This should be called
     * AFTER resolveForks() so that you don't delete the wrong blocks.
     *
     * @return void
     */
    public function cleanBlockchain(): void
    {
        /**
         * Get the highest height and work down
         */
        // get top 2 blocks by height
        $height = $this->getCurrentHeight();

        if ($height > 1) {
            $start = (int)$this->store->getKey('clean_progress', $height);

            if ($start > $height) {
                $start = $height;
            }

            for ($h = $start; $h >= 1; $h--) {
                $query = 'SELECT `block_id` FROM blocks WHERE orphan=1 AND height=:height';
                $stmt = $this->db->prepare($query);
                $stmt = DatabaseHelpers::filterBind($stmt, 'height', $h, DatabaseHelpers::INT);
                $stmt->execute();
                $rows = $stmt->fetchAll();
                $counter = 0;

                foreach ($rows as $row) {
                    $this->delete($row['block_id']);

                    // let's not kill the CPU
                    if ($counter++ % 100 === 0) {
                        usleep(1);
                    }
                }

                $this->store->add('clean_progress', $h - 1);
            }
        }
    }

    /**
     * This just tests if our highest block is an orphan
     *
     * @return bool
     */
    public function isCurrentHeightOrphan(): bool
    {
        $query = 'SELECT `orphan` FROM blocks ORDER BY `height` DESC LIMIT 1';
        $stmt = $this->db->query($query);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * Switches to the stored chain with the most work if it isn't the active one.
     *
     * @return void
     */
    public function resolveForks(): void
    {
        $tip = $this->getCurrent();
        $stmt = $this->db->query('SELECT `block_id` FROM blocks WHERE `orphan`=1 ORDER BY `height` DESC LIMIT 50');

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $candidate = $this->getByBlockId($row['block_id']);

            if (!empty($tip) && $this->blockSelector($tip, $candidate)['block_id'] === $candidate['block_id']
                && $this->reorganize($candidate)) {
                $tip = $this->getCurrent();
            }
        }
    }

    /**
     * This function chooses BLOCK_1 or BLOCK_2. The chain with the most work since the two chains split wins.
     * When the work is equal the lower hash wins, so every node picks the same block.
     *
     * @param array $block1
     * @param array $block2
     * @return array
     */
    public function blockSelector(array $block1, array $block2): array
    {
        if (!$this->isFork($block1, $block2)) {
            return $block1;
        }

        $pow = new Pow();
        $work1 = '0';
        $work2 = '0';
        $a = $block1;
        $b = $block2;

        // walk both chains back to the block they share, adding up the work on each side
        while (!empty($a) && !empty($b) && $a['block_id'] !== $b['block_id']) {
            if ((int)$a['height'] >= (int)$b['height']) {
                $work1 = bcadd($work1, $pow->blockWork((int)$a['difficulty']));
                $a = $a['previous_block_id'] !== '' ? $this->getByBlockId($a['previous_block_id']) : [];
            } else {
                $work2 = bcadd($work2, $pow->blockWork((int)$b['difficulty']));
                $b = $b['previous_block_id'] !== '' ? $this->getByBlockId($b['previous_block_id']) : [];
            }
        }

        $strength = bccomp($work1, $work2);

        if ($strength === 0) {
            return strcmp((string)$block1['hash'], (string)$block2['hash']) <= 0 ? $block1 : $block2;
        }

        return $strength > 0 ? $block1 : $block2;
    }

    /**
     * @param array $block
     * @return string
     */
    public function generateBlockHeader(array $block): string
    {
        return
            $block['network_id'] .
            $block['block_id'] .
            $block['previous_block_id'] .
            $block['date_created'] .
            $block['height'] .
            $block['difficulty'] .
            $block['merkle_root'] .
            $block['transaction_count'] .
            $block['previous_hash'];
    }

    /**
     * @param int $nHeight
     * @return string
     */
    #[Pure]
    public function getRewardValue(int $nHeight): string
    {
        if ($nHeight <= 0) {
            $nHeight = 1;
        }

        $strHeight = (string)$nHeight;

        $targetModulus = (3600 / Config::getDesiredBlockTime()) * 24 * 365;
        $reductions = bcdiv($strHeight, (string)$targetModulus, 0);
        $nSubsidy = Config::getDefaultBlockReward(); // 100 coins

        if (bccomp($reductions, "0") === 1) {
            $reductions = (int)$reductions;

            for ($i = 0; $i < $reductions; $i++) {
                $reduction = bcmul($nSubsidy, "0.04");
                $nSubsidy = bcsub($nSubsidy, $reduction, 0);

                if (bccomp($nSubsidy, '100000000') <= 0) {
                    break;
                }
            }
        }

        return $nSubsidy;
    }

    /**
     * @param int $currentTimeSeconds
     * @param int $previousTimeSeconds
     * @param int $blocksCreated
     * @return float
     */
    public function getBlockTime(int $currentTimeSeconds, int $previousTimeSeconds, int $blocksCreated): float
    {
        return ceil(($currentTimeSeconds - $previousTimeSeconds) / $blocksCreated);
    }

    /**
     * The difficulty a block built on $parent must have. It only reads the parent and its ancestors, so every
     * block gets the same answer however it arrives and whatever else is at its height.
     *
     * @param array $parent
     * @return int
     */
    public function getNextDifficulty(array $parent): int
    {
        $height = (int)$parent['height'] + 1;
        $blocksPerPeriod = (int)(3600 / Config::getDesiredBlockTime()) * 24;

        if ($height < $blocksPerPeriod) {
            $genesisBlock = $this->getByHeight(1);

            return (int)$genesisBlock['difficulty'];
        }

        $difficulty = (int)$parent['difficulty'];

        // adjust once per period, using the time the parent's chain took over that period
        if ($height % $blocksPerPeriod === 0) {
            $oldestBlock = $parent;

            for ($i = 1; $i < $blocksPerPeriod && (string)$oldestBlock['previous_block_id'] !== ''; $i++) {
                $oldestBlock = $this->getByBlockId((string)$oldestBlock['previous_block_id']);

                if (empty($oldestBlock)) {
                    return $difficulty;
                }
            }

            $blockTime = $this->getBlockTime(
                (int)$parent['date_created'],
                (int)$oldestBlock['date_created'],
                $blocksPerPeriod
            );

            // block time was quick, increase difficulty
            if ($blockTime <= Config::getDesiredBlockTime() - 30) {
                ++$difficulty;
            }

            // block time was slow, decrease difficulty
            if ($blockTime >= Config::getDesiredBlockTime() + 30) {
                $difficulty--;
            }
        }

        return max(Config::getDefaultDifficulty(), min(255, $difficulty));
    }

    /**
     * Warning, this is a simple "add a block and its transactions to the database". If you want the block to be active
     * you need to '$this->acceptBlock(block_id)'.
     *
     * @param array $block
     * @param bool $validate
     * @param bool $orphan
     * @return bool
     */
    public function add(array $block, bool $validate = true, bool $orphan = false): bool
    {
        $result = false;
        $orphanVal = 0;

        if ($orphan) {
            $orphanVal = 1;
        }

        if ($validate) {
            try {
                $result = $this->validateFullBlock($block);
            } catch (Throwable $e) {
                Console::log('Exception thrown validating block: ' . $e->getMessage());

                return false;
            }

            if (!$result['validated']) {
                Console::log('Invalid block: ' . $result['reason']);

                return false;
            }
        }

        // ensure we have transactions
        if (!isset($block['transactions'])) {
            throw new RuntimeException('Missing transactions');
        }

        // clip transactions
        $transactions = $block['transactions'];
        unset($block['transactions']);

        // defaults
        if ((int)$block['date_created'] <= 0) {
            $block['date_created'] = time();
        }

        $ownsTransaction = !$this->db->inTransaction();

        try {
            // start a transaction
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }

            // prepare the statement and execute
            $query = 'INSERT INTO blocks (`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`' .
                ',`nonce`,`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan`) VALUES ' .
                '(:network_id,:block_id,:previous_block_id,:date_created,:height,:nonce,:difficulty,:merkle_root,' .
                ':transaction_count,:previous_hash,:hash,:orphan)';
            $stmt = $this->db->prepare($query);

            $fields = [
                [
                    'name' => 'network_id',
                    'value' => $block['network_id'],
                    'type' => DatabaseHelpers::ALPHA_NUMERIC,
                    'max_length' => 4,
                ],
                [
                    'name' => 'block_id',
                    'value' => $block['block_id'],
                    'type' => DatabaseHelpers::ALPHA_NUMERIC,
                    'max_length' => 64,
                ],
                [
                    'name' => 'previous_block_id',
                    'value' => $block['previous_block_id'],
                    'type' => DatabaseHelpers::ALPHA_NUMERIC,
                    'max_length' => 64,
                ],
                [
                    'name' => 'date_created',
                    'value' => $block['date_created'],
                    'type' => DatabaseHelpers::INT,
                    'max_length' => 20,
                ],
                [
                    'name' => 'height',
                    'value' => $block['height'],
                    'type' => DatabaseHelpers::INT,
                    'max_length' => 20,
                ],
                [
                    'name' => 'nonce',
                    'value' => (string)$block['nonce'],
                    'type' => DatabaseHelpers::ALPHA_NUMERIC,
                    'max_length' => 128,
                ],
                [
                    'name' => 'difficulty',
                    'value' => $block['difficulty'],
                    'type' => DatabaseHelpers::INT,
                    'max_length' => 20,
                ],
                [
                    'name' => 'merkle_root',
                    'value' => $block['merkle_root'],
                    'type' => DatabaseHelpers::ALPHA_NUMERIC,
                    'max_length' => 64,
                ],
                [
                    'name' => 'transaction_count',
                    'value' => $block['transaction_count'],
                    'type' => DatabaseHelpers::INT,
                    'max_length' => 20,
                ],
                [
                    'name' => 'hash',
                    'value' => $block['hash'],
                    'type' => DatabaseHelpers::ALPHA_NUMERIC,
                    'max_length' => 64,
                ],
                [
                    'name' => 'previous_hash',
                    'value' => $block['previous_hash'],
                    'type' => DatabaseHelpers::ALPHA_NUMERIC,
                    'max_length' => 64,
                ],
                [
                    'name' => 'orphan',
                    'value' => $orphanVal,
                    'type' => DatabaseHelpers::INT,
                    'max_length' => 20,
                ]
            ];
            $stmt = DatabaseHelpers::filterBindAll($stmt, $fields);
            $stmt->execute();

            // ensure the block was stored
            $blockInsertId = (int)$this->db->lastInsertId();

            if ($blockInsertId <= 0) {
                throw new RuntimeException("failed to add block to the database: " . $block['block_id']);
            }

            // add the transactions
            foreach ($transactions as $transaction) {
                $transaction['block_id'] = $block['block_id'];

                // defaults
                if ((int)$transaction['date_created'] < Config::getGenesisDate()) {
                    throw new RuntimeException("failed to add block to the database: " . $block['block_id']);
                }

                // prepare the statement and execute
                $query = 'INSERT INTO transactions (`block_id`,`transaction_id`,`date_created`,`peer`,`height`,' .
                    '`version`,`signature`,`public_key`) VALUES (:block_id,:transaction_id,:date_created,:peer,' .
                    ':height,:version,:signature,:public_key)';
                $stmt = $this->db->prepare($query);

                $fields = [
                    [
                        'name' => 'block_id',
                        'value' => $transaction['block_id'],
                        'type' => DatabaseHelpers::ALPHA_NUMERIC,
                        'max_length' => 64,
                    ],
                    [
                        'name' => 'transaction_id',
                        'value' => $transaction['transaction_id'],
                        'type' => DatabaseHelpers::ALPHA_NUMERIC,
                        'max_length' => 64,
                    ],
                    [
                        'name' => 'date_created',
                        'value' => $transaction['date_created'],
                        'type' => DatabaseHelpers::INT,
                        'max_length' => 20,
                    ],
                    [
                        'name' => 'peer',
                        'value' => $transaction['peer'],
                        'type' => DatabaseHelpers::ALPHA_NUMERIC,
                        'max_length' => 64,
                    ],
                    [
                        'name' => 'height',
                        'value' => $transaction['height'],
                        'type' => DatabaseHelpers::INT,
                        'max_length' => 20,
                    ],
                    [
                        'name' => 'version',
                        'value' => $transaction['version'],
                        'type' => DatabaseHelpers::ALPHA_NUMERIC,
                        'max_length' => 2,
                    ],
                    [
                        'name' => 'signature',
                        'value' => $transaction['signature'],
                        'type' => DatabaseHelpers::TEXT,
                        'max_length' => 8192,
                    ],
                    [
                        'name' => 'public_key',
                        'value' => $transaction['public_key'],
                        'type' => DatabaseHelpers::TEXT,
                        'max_length' => 8192,
                    ]
                ];

                $stmt = DatabaseHelpers::filterBindAll($stmt, $fields);
                $stmt->execute();

                $transactionId = (int)$this->db->lastInsertId();

                if ($transactionId <= 0) {
                    throw new RuntimeException(
                        'failed to add transaction to the database: ' . $transaction['block_id'] . ' - ' .
                        $transaction['transaction_id']
                    );
                }

                // add txIn
                foreach ($transaction[Transaction::INPUTS] as $txIn) {
                    $txIn['block_id'] = $transaction['block_id'];
                    $txIn['transaction_id'] = $transaction['transaction_id'];

                    // add the txIn record to the db
                    $query = 'INSERT INTO transaction_inputs (`block_id`,`transaction_id`,`tx_id`,' .
                        '`previous_transaction_id`,`previous_tx_out_id`,`script`) VALUES (:block_id,:transaction_id' .
                        ',:tx_id,:previous_transaction_id,:previous_tx_out_id,:script)';
                    $stmt = $this->db->prepare($query);
                    $fields = [
                        [
                            'name' => 'block_id',
                            'value' => $transaction['block_id'],
                            'type' => DatabaseHelpers::ALPHA_NUMERIC,
                            'max_length' => 64,
                        ],
                        [
                            'name' => 'transaction_id',
                            'value' => $txIn['transaction_id'],
                            'type' => DatabaseHelpers::ALPHA_NUMERIC,
                            'max_length' => 64,
                        ],
                        [
                            'name' => 'tx_id',
                            'value' => $txIn['tx_id'],
                            'type' => DatabaseHelpers::INT,
                            'max_length' => 20,
                        ],
                        [
                            'name' => 'previous_transaction_id',
                            'value' => $txIn['previous_transaction_id'],
                            'type' => DatabaseHelpers::ALPHA_NUMERIC,
                            'max_length' => 64,
                        ],
                        [
                            'name' => 'previous_tx_out_id',
                            'value' => $txIn['previous_tx_out_id'],
                            'type' => DatabaseHelpers::INT,
                            'max_length' => 20,
                        ],
                        [
                            'name' => 'script',
                            'value' => $txIn['script'],
                            'type' => DatabaseHelpers::TEXT,
                            'max_length' => 16384,
                        ]
                    ];
                    $stmt = DatabaseHelpers::filterBindAll($stmt, $fields);
                    $stmt->execute();

                    $transactionTxId = (int)$this->db->lastInsertId();

                    if ($transactionTxId <= 0) {
                        throw new RuntimeException('failed to add a new transaction tx: ' .
                            $txIn['transaction_id'] . ' - ' . $txIn['$txIn']);
                    }
                }

                // add txOut
                foreach ($transaction[Transaction::OUTPUTS] as $txOut) {
                    $txOut['block_id'] = $transaction['block_id'];
                    $txOut['transaction_id'] = $transaction['transaction_id'];
                    $txOut['spent'] = 0; // set this to zero, it will be updated on the spent transaction

                    // add the txIn record to the db
                    $query = 'INSERT INTO transaction_outputs (`block_id`,`transaction_id`,`tx_id`,`address`,`value`' .
                        ',`script`,`lock_height`,`spent`,`hash`) VALUES (:block_id,:transaction_id,:tx_id,:address,' .
                        ':value,:script,:lock_height,:spent,:hash)';
                    $stmt = $this->db->prepare($query);
                    $fields = [
                        [
                            'name' => 'block_id',
                            'value' => $transaction['block_id'],
                            'type' => DatabaseHelpers::ALPHA_NUMERIC,
                            'max_length' => 64,
                        ],
                        [
                            'name' => 'transaction_id',
                            'value' => $txOut['transaction_id'],
                            'type' => DatabaseHelpers::ALPHA_NUMERIC,
                            'max_length' => 64,
                        ],
                        [
                            'name' => 'tx_id',
                            'value' => $txOut['tx_id'],
                            'type' => DatabaseHelpers::INT,
                            'max_length' => 20,
                        ],
                        [
                            'name' => 'address',
                            'value' => $txOut['address'] ?: '',
                            'type' => DatabaseHelpers::ALPHA_NUMERIC,
                            'max_length' => 64,
                        ],
                        [
                            'name' => 'value',
                            'value' => $txOut['value'],
                            'type' => DatabaseHelpers::INT,
                            'max_length' => 20,
                        ],
                        [
                            'name' => 'script',
                            'value' => $txOut['script'],
                            'type' => DatabaseHelpers::TEXT,
                            'max_length' => 16384,
                        ],
                        [
                            'name' => 'lock_height',
                            'value' => $txOut['lock_height'],
                            'type' => DatabaseHelpers::INT,
                            'max_length' => 20,
                        ],
                        [
                            'name' => 'spent',
                            'value' => 0,
                            'type' => DatabaseHelpers::INT,
                            'max_length' => 20,
                        ],
                        [
                            'name' => 'hash',
                            'value' => (string)($txOut['hash'] ?? ''),
                            'type' => DatabaseHelpers::TEXT,
                            'max_length' => 8192,
                        ]
                    ];
                    $stmt = DatabaseHelpers::filterBindAll($stmt, $fields);

                    $stmt->execute();
                    $transactionTxId = (int)$this->db->lastInsertId();

                    if ($transactionTxId <= 0) {
                        throw new RuntimeException(
                            'failed to add a new transaction tx as unspent in the database: ' .
                            $txOut['transaction_id'] . ' - ' . $txOut['$txIn']
                        );
                    }
                }
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }

            $result = true;
        } catch (Throwable $e) {
            Console::log('Rolling back transaction: ' . $e->getMessage());

            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }

            $result = false;
        }

        return $result;
    }

    /**
     * This function effectively processes a block and all of its transactions, and makes it active. The block
     * must extend the current tip. Calling it on a block that is already active does nothing.
     *
     * @param string $blockId
     * @param int $height
     * @return bool
     */
    public function acceptBlock(string $blockId, int $height): bool
    {
        $block = $this->getByBlockId($blockId);

        if (empty($block) || (int)$block['height'] !== $height) {
            return false;
        }

        if ((int)$block['orphan'] === 0 && $this->isAccepted($blockId)) {
            return true;
        }

        $ownsTransaction = !$this->db->inTransaction();

        if ($ownsTransaction) {
            $this->reversedTransactions = [];
        }

        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }

            // a block can only extend the current tip, checked inside the transaction so another process can't
            // move the tip in between. Switching to another branch goes through reorganize()
            $tip = $this->getCurrent();
            $isGenesis = $height === 1 && (string)$block['previous_block_id'] === '';

            if (!$isGenesis && (empty($tip) || $tip['block_id'] !== $block['previous_block_id'])) {
                throw new RuntimeException('block ' . $blockId . ' does not extend the current tip');
            }

            $this->setBlockState($blockId, 0, 0);

            foreach ($this->transaction->getTransactionsByBlockId($blockId) as $transaction) {
                if (empty($transaction[Transaction::INPUTS])) {
                    continue;
                }

                $signatureHash = $this->transaction->signatureHash($transaction);

                foreach ($transaction[Transaction::INPUTS] as $txIn) {
                    $txOut = $this->transaction->getUnspentOutput(
                        $txIn['previous_transaction_id'],
                        (int)$txIn['previous_tx_out_id']
                    );

                    if ($txOut === null) {
                        throw new RuntimeException('failed to get unspent transaction for: ' .
                            $txIn['previous_transaction_id'] . ' - ' . $txIn['previous_tx_out_id']);
                    }

                    if (!$this->transaction->unlockTransaction($txIn, $txOut, $signatureHash)) {
                        throw new RuntimeException('Cannot unlock script for: ' . $transaction['transaction_id']);
                    }

                    // mark the output as spent
                    $stmt = $this->db->prepare(
                        'UPDATE transaction_outputs SET spent=1 WHERE block_id=:block_id AND ' .
                        'transaction_id=:transaction_id AND tx_id=:tx_id'
                    );
                    $stmt = DatabaseHelpers::filterBindAll($stmt, [
                        ['name' => 'block_id', 'value' => $txOut['block_id'],
                            'type' => DatabaseHelpers::ALPHA_NUMERIC, 'max_length' => 64],
                        ['name' => 'transaction_id', 'value' => $txOut['transaction_id'],
                            'type' => DatabaseHelpers::ALPHA_NUMERIC, 'max_length' => 64],
                        ['name' => 'tx_id', 'value' => (int)$txOut['tx_id'],
                            'type' => DatabaseHelpers::INT, 'max_length' => 20],
                    ]);
                    $stmt->execute();
                }

                // the transaction is in a block now, take it out of the mempool
                foreach (['mempool_transactions', 'mempool_inputs', 'mempool_outputs'] as $table) {
                    $stmt = $this->db->prepare('DELETE FROM ' . $table . ' WHERE transaction_id=:transaction_id');
                    $stmt = DatabaseHelpers::filterBind(
                        $stmt,
                        'transaction_id',
                        $transaction['transaction_id'],
                        DatabaseHelpers::ALPHA_NUMERIC,
                        64
                    );
                    $stmt->execute();
                }
            }

            $this->setBlockState($blockId, 0, 1);

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            Console::log('Rolling back transaction: ' . $e->getMessage());

            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }

            return false;
        }

        // put the transactions from reversed blocks back in the mempool
        if ($ownsTransaction) {
            foreach ($this->reversedTransactions as $transaction) {
                $this->mempool->add($transaction);
            }
        }

        return true;
    }

    /**
     * Makes the chain ending at $newTip the active chain. Active blocks above the fork point are reversed, then
     * the new branch is fully validated and accepted from the bottom up. It all happens in one database
     * transaction, so if any block on the branch fails the old chain is left exactly as it was.
     *
     * @param array $newTip a stored block
     * @return bool
     */
    public function reorganize(array $newTip): bool
    {
        // collect the branch back to the first block on the active chain
        $branch = [];
        $current = $newTip;

        while (!empty($current) && !((int)$current['orphan'] === 0 && $this->isAccepted($current['block_id']))) {
            array_unshift($branch, $current);

            if ((string)$current['previous_block_id'] === '') {
                return false;
            }

            $current = $this->getByBlockId((string)$current['previous_block_id']);
        }

        if (empty($current)) {
            return false;
        }

        $forkHeight = (int)$current['height'];
        $this->reversedTransactions = [];

        // inside someone else's transaction a savepoint gives us the same all or nothing behaviour
        $ownsTransaction = !$this->db->inTransaction();

        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            } else {
                $this->db->exec('SAVEPOINT reorganize');
            }

            // undo the active chain above the fork, top down
            $stmt = $this->db->prepare(
                'SELECT `block_id` FROM blocks WHERE `orphan`=0 AND `height`>:height ORDER BY `height` DESC'
            );
            $stmt = DatabaseHelpers::filterBind($stmt, 'height', $forkHeight, DatabaseHelpers::INT);
            $stmt->execute();

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!$this->reverseBlock($row['block_id'])) {
                    throw new RuntimeException('could not reverse block ' . $row['block_id']);
                }
            }

            // apply the new branch, bottom up, checking every spend against the state it builds on
            foreach ($branch as $branchBlock) {
                $full = $this->assembleFullBlock($branchBlock['block_id']);
                $result = $this->validateFullBlock($full);

                if (!$result['validated']) {
                    throw new RuntimeException('branch block ' . $branchBlock['block_id'] . ' is invalid: ' .
                        $result['reason']);
                }

                if (!$this->acceptBlock($branchBlock['block_id'], (int)$branchBlock['height'])) {
                    throw new RuntimeException('could not accept branch block ' . $branchBlock['block_id']);
                }
            }

            if ($ownsTransaction) {
                $this->db->commit();
            } else {
                $this->db->exec('RELEASE SAVEPOINT reorganize');
            }
        } catch (Throwable $e) {
            Console::log('Reorganize failed, keeping the current chain: ' . $e->getMessage());

            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            } elseif (!$ownsTransaction) {
                $this->db->exec('ROLLBACK TO SAVEPOINT reorganize');
                $this->db->exec('RELEASE SAVEPOINT reorganize');
            }

            return false;
        }

        // put transactions from the old chain back in the mempool, ones now in the new chain are refused
        foreach ($this->reversedTransactions as $transaction) {
            $this->mempool->add($transaction);
        }

        return true;
    }

    /**
     * @param string $blockId
     * @return bool
     */
    public function isAccepted(string $blockId): bool
    {
        $stmt = $this->db->prepare('SELECT `accepted` FROM blocks WHERE `block_id`=:block_id LIMIT 1');
        $stmt = DatabaseHelpers::filterBind($stmt, 'block_id', $blockId, DatabaseHelpers::ALPHA_NUMERIC, 64);
        $stmt->execute();

        return (int)$stmt->fetchColumn() === 1;
    }

    /**
     * @param string $blockId
     * @param int $orphan
     * @param int $accepted
     * @return void
     */
    private function setBlockState(string $blockId, int $orphan, int $accepted): void
    {
        $stmt = $this->db->prepare('UPDATE blocks SET `orphan`=:orphan, `accepted`=:accepted WHERE `block_id`=:block_id');
        $stmt = DatabaseHelpers::filterBind($stmt, 'orphan', $orphan, DatabaseHelpers::INT);
        $stmt = DatabaseHelpers::filterBind($stmt, 'accepted', $accepted, DatabaseHelpers::INT);
        $stmt = DatabaseHelpers::filterBind($stmt, 'block_id', $blockId, DatabaseHelpers::ALPHA_NUMERIC, 64);
        $stmt->execute();
    }

    /**
     * WARNING --- This will delete any block given, and does NOT reverse anything - generally safest to call
     * reverseBlock() with delete flag set to true.
     *
     * @param string $blockId
     * @return bool
     */
    public function delete(string $blockId): bool
    {
        $result = false;
        try {
            $ownsTransaction = !$this->db->inTransaction();

            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }

            // delete the block
            $query = 'DELETE FROM blocks WHERE `block_id` = :block_id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'block_id',
                value: $blockId,
                pdoType: DatabaseHelpers::ALPHA_NUMERIC,
                maxLength: 64
            );
            $stmt->execute();

            // get all transactions associated with this block
            $query = 'SELECT transaction_id FROM transactions WHERE `block_id` = :block_id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'block_id',
                value: $blockId,
                pdoType: DatabaseHelpers::ALPHA_NUMERIC,
                maxLength: 64
            );
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($transactions as $transaction) {
                // clear the transaction inputs
                $query = 'DELETE FROM transaction_inputs WHERE `transaction_id` = :transaction_id;';
                $stmt = $this->db->prepare($query);
                $stmt = DatabaseHelpers::filterBind(
                    stmt: $stmt,
                    fieldName: 'transaction_id',
                    value: $transaction['transaction_id'],
                    pdoType: DatabaseHelpers::ALPHA_NUMERIC,
                    maxLength: 64
                );
                $stmt->execute();

                // clear the transaction outputs
                $query = 'DELETE FROM transaction_outputs WHERE `transaction_id` = :transaction_id;';
                $stmt = $this->db->prepare($query);
                $stmt = DatabaseHelpers::filterBind(
                    stmt: $stmt,
                    fieldName: 'transaction_id',
                    value: $transaction['transaction_id'],
                    pdoType: DatabaseHelpers::ALPHA_NUMERIC,
                    maxLength: 64
                );
                $stmt->execute();
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
            $result = true;
        } catch (Throwable $e) {
            Console::log('Rolling back transaction: ' . $e->getMessage());
            if ($ownsTransaction ?? false) {
                $this->db->rollback();
            }
        }

        return $result;
    }

    /**
     * This is a recursive function that reverses the block given. Its spends are undone, any active block that
     * spent its outputs is reversed first, and it is marked as an orphan.
     *
     * @param string $blockId
     * @param bool $delete
     * @return bool
     */
    public function reverseBlock(string $blockId, bool $delete = false): bool
    {
        $ownsTransaction = !$this->db->inTransaction();

        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }

            $this->reverseTransactions($blockId);
            $this->setBlockState($blockId, 1, 0);

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            Console::log('Rolling back transaction: ' . $e->getMessage());

            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }

            return false;
        }

        if ($delete) {
            $this->delete($blockId);
        }

        return true;
    }

    /**
     * @param string $blockId
     * @return void
     */
    private function reverseTransactions(string $blockId): void
    {
        if (!$this->isAccepted($blockId)) {
            return;
        }

        foreach ($this->transaction->getTransactionsByBlockId($blockId) as $transaction) {
            // reverse any active block that spent this transaction's outputs
            $stmt = $this->db->prepare(
                'SELECT DISTINCT i.`block_id` FROM transaction_inputs i INNER JOIN blocks b ON ' .
                'b.`block_id`=i.`block_id` WHERE b.`orphan`=0 AND b.`accepted`=1 AND i.`block_id`!=:block_id ' .
                'AND i.`previous_transaction_id`=:transaction_id'
            );
            $stmt = DatabaseHelpers::filterBind($stmt, 'block_id', $blockId, DatabaseHelpers::ALPHA_NUMERIC, 64);
            $stmt = DatabaseHelpers::filterBind(
                $stmt,
                'transaction_id',
                $transaction['transaction_id'],
                DatabaseHelpers::ALPHA_NUMERIC,
                64
            );
            $stmt->execute();

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $this->reverseTransactions($row['block_id']);
                $this->setBlockState($row['block_id'], 1, 0);
            }

            // undo this transaction's spends
            foreach ($transaction[Transaction::INPUTS] as $txIn) {
                $stmt = $this->db->prepare(
                    'UPDATE transaction_outputs SET spent=0 WHERE transaction_id=:transaction_id AND tx_id=:tx_id ' .
                    'AND block_id IN (SELECT block_id FROM blocks WHERE orphan=0)'
                );
                $stmt = DatabaseHelpers::filterBind(
                    $stmt,
                    'transaction_id',
                    $txIn['previous_transaction_id'],
                    DatabaseHelpers::ALPHA_NUMERIC,
                    64
                );
                $stmt = DatabaseHelpers::filterBind($stmt, 'tx_id', (int)$txIn['previous_tx_out_id'], DatabaseHelpers::INT);
                $stmt->execute();
            }

            // transfers go back to the mempool once the reversal is committed
            if ((string)$transaction['version'] !== TransactionVersion::COINBASE) {
                $this->reversedTransactions[] = $this->transaction->stripInternalFields($transaction);
            }
        }
    }

    /**
     * The genesis coinbase, paying the first reward to $publicKeyRaw. Unsigned.
     *
     * @param int $date
     * @param string $publicKeyRaw
     * @return array
     */
    private function genesisTransaction(int $date, string $publicKeyRaw): array
    {
        $address = new Address();
        $script = new Script([]);
        $publicKey = $this->openSsl->formatPem($publicKeyRaw, false);
        $partialAddress = $this->transferEncoding->binToHex($address->createPartial($publicKey));
        $scriptText = 'mov ' . $partialAddress . ',ax;adha ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;' .
            'vsig ax,<hash>,bx;rem 466F7274756E65202D2043727970746F2069732066756C6C792062616E6E6564206' .
            '96E204368696E6120616E642038206F7468657220636F756E7472696573202D204A616E7561727920342C2032303232;';

        $transaction = [
            'date_created' => $date,
            'public_key' => $publicKeyRaw,
            'peer' => 'genesis',
            'version' => TransactionVersion::COINBASE,
            'height' => 1,
            Transaction::INPUTS => [],
            Transaction::OUTPUTS => [
                [
                    'tx_id' => 0,
                    'address' => $address->create($publicKey),
                    'value' => $this->getRewardValue(1),
                    'script' => $script->encodeScript($scriptText),
                    'lock_height' => 1 + Config::getLockHeight(),
                ],
            ],
        ];
        $transaction['transaction_id'] = $this->transaction->computeId($transaction);
        $transaction[Transaction::OUTPUTS][0]['hash'] = bin2hex(
            $this->pow->doubleSha256($transaction['transaction_id'] . '0')
        );

        return $transaction;
    }

    /**
     * @param string $environment
     * @param int $date
     * @param string $publicKeyRaw
     * @param string $signature
     * @return array the block without nonce and hash
     */
    private function assembleGenesis(string $environment, int $date, string $publicKeyRaw, string $signature): array
    {
        $transaction = $this->genesisTransaction($date, $publicKeyRaw);
        $transaction['signature'] = $signature;
        $merkleRoot = $this->merkle->computeMerkleHash([$transaction]);

        return [
            'network_id' => Config::getNetworkIdentifier(),
            'block_id' => $this->generateId(Config::getNetworkIdentifier(), '', $date, 1, $merkleRoot),
            'previous_block_id' => '',
            'date_created' => $date,
            'height' => 1,
            'difficulty' => Config::getDefaultDifficulty($environment),
            'merkle_root' => $merkleRoot,
            'transactions' => [$transaction],
            'transaction_count' => 1,
            'previous_hash' => '',
        ];
    }
}
