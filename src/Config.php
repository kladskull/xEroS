<?php declare(strict_types=1);

namespace Blockchain;

/**
 * Class Config
 */
class Config
{
    // Project
    private const PRODUCT_NAME = 'Xero';
    private const PRODUCT_COPYRIGHT = 'Copyright (c)2021,2022 by Kladskull <kladskull@currazy.com>';
    private const VERSION = '0.0001';
    private const ENVIRONMENT = "prod";

    // Genesis block
    private const GENESYS_DATE = 1638738156; // Sunday, December 5, 2021 4:02:36 PM GMT-05:00

    // Block creation
    private const DESIRED_BLOCK_TIME = 600;

    // Transaction creation
    private const MAX_UNSPENT_PER_TRANSACTION = 100;
    private const MAX_SPENT_PER_TRANSACTION = 100;

    // Coin
    private const DEFAULT_BLOCK_REWARD = "5000000000";
    private const MINIMUM_FEE = "10000000"; // 0.1 xc
    private const FEE_PER_KB = "1000000"; // 0.01 xc for every started 1000 bytes, see Transaction::minimumFee()
    private const MAX_CURRENCY_SUPPLY = "21000000000000000"; // Satoshi's per coin x 210,000,000
    private const MAX_TRANSACTION_SIZE = 100;

    // mining
    private const DEFAULT_LIVE_DIFFICULTY = 28;
    private const DEFAULT_TEST_DIFFICULTY = 24;
    private const DEFAULT_DEV_DIFFICULTY = 16;
    private const LOCK_HEIGHT = 144; // 144 blocks ~ 1 day

    // Scripting
    private const MAX_LOOPS = 100;
    private const MAX_SCRIPT_LENGTH = 2048;
    private const MAX_DECODED_SCRIPT_LENGTH = 4096; // scripts are compressed, this caps what they expand to

    // the chain never switches to a branch that left it more than this many blocks below the tip
    private const MAX_REORG_DEPTH = 144;

    // a block may be dated this far ahead of our clock
    private const MAX_FUTURE_BLOCK_TIME = 7200;

    // Size limits in bytes, see Transaction::size(). A full block stays well under the 4MB network packet limit.
    private const MAX_BLOCK_SIZE = 1000000;
    private const MAX_TRANSACTION_BYTES = 500000;

    // Mempool
    private const MAX_MEMPOOL_TRANSACTIONS = 5000;
    private const MEMPOOL_EXPIRY = 259200; // 3 days, unmined transactions are dropped after this

    // Peers
    private const MAX_PEERS = 60;
    private const BAN_SECONDS = 86400;
    private const MAX_REBROADCASTING_PEERS = 30;
    private const PEER_REQUEST_TIMEOUT = 10;

    // host identifier
    private const HOST_ID_SERVICE = 'https://api64.ipify.org';

    // Hosts that are allowed to mine on this node
    private const PUBLIC_API_ACCESS = true;
    private const ALLOWED_PUBLIC_HOSTS = [];
    private const ALLOWED_LOCAL_HOSTS = ['127.0.0.1'];

    // Each network has its own id, address format and seed peers, so blocks, transactions and addresses from
    // one are never valid on another
    private const NETWORKS = [
        'live' => [
            'id' => 'xv01',
            'address_header' => 'Xa',
            'chain_version' => '00',
            'seed_peers' => ['3.97.76.137:7747', '3.99.119.232:7747'],
        ],
        'test' => [
            'id' => 'xt01',
            'address_header' => 'xa',
            'chain_version' => '01',
            'seed_peers' => [],
        ],
        'dev' => [
            'id' => 'xd01',
            'address_header' => 'xd',
            'chain_version' => '02',
            'seed_peers' => [],
        ],
    ];

    /**
     * @return string
     */
    public static function getAddressHeader(): string
    {
        return self::NETWORKS[self::getEnvironment()]['address_header'];
    }

    /**
     * @return array
     */
    public static function getAllowedLocalHosts(): array
    {
        return self::ALLOWED_LOCAL_HOSTS;
    }

    /**
     * @return array
     */
    public static function getAllowedPublicHosts(): array
    {
        return self::ALLOWED_PUBLIC_HOSTS;
    }

    /**
     * @return string
     */
    public static function getChainVersion(): string
    {
        return self::NETWORKS[self::getEnvironment()]['chain_version'];
    }

    /**
     * @return string
     */
    public static function getDefaultBlockReward(): string
    {
        return self::DEFAULT_BLOCK_REWARD;
    }

    /**
     * @return int
     */
    public static function getDesiredBlockTime(): int
    {
        return self::DESIRED_BLOCK_TIME;
    }

    /**
     * The network this node runs on: live, test or dev. Unknown names are treated as live.
     *
     * @return string
     */
    public static function getEnvironment(): string
    {
        return self::normalizeEnvironment($_ENV['ENVIRONMENT'] ?? self::ENVIRONMENT);
    }

    /**
     * Runs $work as if this node were on $environment, e.g. to build another network's genesis block.
     *
     * @param string $environment
     * @param callable $work
     * @return mixed
     */
    public static function withEnvironment(string $environment, callable $work): mixed
    {
        $had = array_key_exists('ENVIRONMENT', $_ENV);
        $previous = $_ENV['ENVIRONMENT'] ?? null;
        $_ENV['ENVIRONMENT'] = self::normalizeEnvironment($environment);

        try {
            return $work();
        } finally {
            if ($had) {
                $_ENV['ENVIRONMENT'] = $previous;
            } else {
                unset($_ENV['ENVIRONMENT']);
            }
        }
    }

    /**
     * @param string $environment
     * @return string
     */
    public static function normalizeEnvironment(string $environment): string
    {
        return match (strtolower(trim($environment))) {
            'dev', 'development' => 'dev',
            'test', 'testnet' => 'test',
            default => 'live',
        };
    }

    /**
     * @param string|null $environment defaults to this node's
     * @return int
     */
    public static function getDefaultDifficulty(?string $environment = null): int
    {
        return match (self::normalizeEnvironment($environment ?? self::getEnvironment())) {
            'dev' => self::DEFAULT_DEV_DIFFICULTY,
            'test' => self::DEFAULT_TEST_DIFFICULTY,
            default => self::DEFAULT_LIVE_DIFFICULTY,
        };
    }

    /**
     * @return string
     */
    public static function getDbEnvironment(): string
    {
        // matches phinx.php: live is xero.db, the others are xero-dev.db and xero-test.db
        $environment = self::getEnvironment();

        return $environment === 'live' ? '' : $environment;
    }

    /**
     * @return int
     */
    public static function getGenesisDate(): int
    {
        return self::GENESYS_DATE;
    }

    /**
     * @return string
     */
    public static function getHostIdService(): string
    {
        return self::HOST_ID_SERVICE;
    }

    /**
     * @return array
     */
    public static function getInitialPeers(): array
    {
        // SEED_PEERS in .env replaces the built in list, e.g. SEED_PEERS=10.0.0.5:7747,10.0.0.6:7747
        if (isset($_ENV['SEED_PEERS'])) {
            return array_values(array_filter(array_map('trim', explode(',', (string)$_ENV['SEED_PEERS']))));
        }

        return self::NETWORKS[self::getEnvironment()]['seed_peers'];
    }

    /**
     * @return int
     */
    public static function getListenAddress(): string
    {
        return $_ENV['LISTEN_IP'] ?? '0.0.0.0';
    }

    /**
     * @return int
     */
    public static function getListenPort(): int
    {
        return (int)($_ENV['LISTEN_PORT'] ?? 7747);
    }

    /**
     * @return int
     */
    public static function getLockHeight(): int
    {
        return self::LOCK_HEIGHT;
    }

    /**
     * @return string
     */
    public static function getMaxCurrencySupply(): string
    {
        return self::MAX_CURRENCY_SUPPLY;
    }

    /**
     * @return int
     */
    public static function getMaxLoops(): int
    {
        return self::MAX_LOOPS;
    }

    /**
     * @return int
     */
    public static function getMaxPeers(): int
    {
        return self::MAX_PEERS;
    }

    /**
     * @return int
     */
    public static function getMaxRebroadcastPeers(): int
    {
        return self::MAX_REBROADCASTING_PEERS;
    }

    /**
     * @return int
     */
    public static function getMaxScriptLength(): int
    {
        return self::MAX_SCRIPT_LENGTH;
    }

    /**
     * @return int
     */
    public static function getMaxDecodedScriptLength(): int
    {
        return self::MAX_DECODED_SCRIPT_LENGTH;
    }

    /**
     * @return int
     */
    public static function getMaxReorgDepth(): int
    {
        return self::MAX_REORG_DEPTH;
    }

    /**
     * @return int
     */
    public static function getMaxFutureBlockTime(): int
    {
        return self::MAX_FUTURE_BLOCK_TIME;
    }

    /**
     * @return int
     */
    public static function getMaxBlockSize(): int
    {
        return self::MAX_BLOCK_SIZE;
    }

    /**
     * @return int
     */
    public static function getMaxTransactionBytes(): int
    {
        return self::MAX_TRANSACTION_BYTES;
    }

    /**
     * Encrypts the private keys in the accounts table when set. Best given in the environment when the node
     * starts rather than written in .env next to the database.
     *
     * @return string
     */
    public static function getWalletPassphrase(): string
    {
        $passphrase = $_ENV['WALLET_PASSPHRASE'] ?? getenv('WALLET_PASSPHRASE');

        return is_string($passphrase) ? $passphrase : '';
    }

    /**
     * How long a misbehaving peer is banned. BAN_SECONDS in the environment overrides it.
     *
     * @return int
     */
    public static function getBanSeconds(): int
    {
        $seconds = $_ENV['BAN_SECONDS'] ?? getenv('BAN_SECONDS');

        return is_numeric($seconds) ? (int)$seconds : self::BAN_SECONDS;
    }

    /**
     * @return int
     */
    public static function getMaxMempoolTransactions(): int
    {
        return self::MAX_MEMPOOL_TRANSACTIONS;
    }

    /**
     * @return int
     */
    public static function getMempoolExpiry(): int
    {
        return self::MEMPOOL_EXPIRY;
    }

    /**
     * @return int
     */
    public static function getMaxSpentTransactionCount(): int
    {
        return self::MAX_SPENT_PER_TRANSACTION;
    }

    /**
     * @return int
     */
    public static function getMaxTransactionSize(): int
    {
        return self::MAX_TRANSACTION_SIZE;
    }

    /**
     * @return int
     */
    public static function getMaxUnspentTransactionCount(): int
    {
        return self::MAX_UNSPENT_PER_TRANSACTION;
    }

    /**
     * @return string
     */
    public static function getFeePerKb(): string
    {
        return self::FEE_PER_KB;
    }

    /**
     * @return string
     */
    public static function getMinimumTransactionFee(): string
    {
        return self::MINIMUM_FEE;
    }

    /**
     * @return string
     */
    public static function getNetworkIdentifier(): string
    {
        return self::NETWORKS[self::getEnvironment()]['id'];
    }

    /**
     * @return int
     */
    public static function getPeerRequestTimeout(): int
    {
        return self::PEER_REQUEST_TIMEOUT;
    }

    /**
     * @return string
     */
    public static function getProductCopyright(): string
    {
        return self::PRODUCT_COPYRIGHT;
    }

    /**
     * @return string
     */
    public static function getProductName(): string
    {
        return self::PRODUCT_NAME;
    }

    /**
     * @return bool
     */
    public static function getPublicAccess(): bool
    {
        return self::PUBLIC_API_ACCESS;
    }

    /**
     * @return string
     */
    public static function getVersion(): string
    {
        return self::VERSION;
    }
}
