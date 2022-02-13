<?php declare(strict_types=1);

namespace Xeros;

class Config
{
    // Project
    private const ProductName = 'Xero';
    private const ProductCopyright = 'Copyright (c)2021,2022 by Mike Curry <mike@currazy.com>';
    private const Version = '0.0001';
    private const NetworkIdentifier = "xv01";

    // Genesis block
    private const GenesisDate = 1638738156; // Sunday, December 5, 2021 4:02:36 PM GMT-05:00

    // Block creation
    private const DesiredBlockTime = 600;

    // Transaction creation
    private const MaxUnspentPerTransaction = 100;
    private const MaxSpentPerTransaction = 100;

    // Coin
    private const DefaultBlockReward = "5000000000";
    private const MinimumFee = "10000000"; // 0.1 xc
    private const AddressHeader = 'Xa'; // Xa = live / xa = testnet
    private const ChainVersion = '00'; // 00 = live, 01 = testnet
    private const DefaultDifficulty = 28;
    private const MaxCurrencySupply = "21000000000000000"; // Satoshi's per coin x 210,000,000
    private const MaxTransactionSize = 100;

    // mining
    private const LockHeight = 144; // 144 blocks ~ 1 day

    // Scripting
    private const MaxLoops = 100;
    private const MaxScriptLength = 2048;

    // Peers
    private const MaxPeers = 60;
    private const MaxRebroadcastPeers = 30;
    private const PeerRequestTimeout = 10;

    // host identifier
    private const HostIdService = 'https://api64.ipify.org';

    // Hosts that are allowed to mine on this node
    private const PublicApiAccess = true;
    private const AllowedPublicHosts = [];
    private const AllowedLocalHosts = ['127.0.0.1'];

    // Initial Peers to connect to
    private const InitialPeerList = [
        'http://peer1.solutionxero.io/',
    ];

    public static function getAddressHeader(): string
    {
        return self::AddressHeader;
    }

    public static function getAllowedLocalHosts(): array
    {
        return self::AllowedLocalHosts;
    }

    public static function getAllowedPublicHosts(): array
    {
        return self::AllowedPublicHosts;
    }

    public static function getChainVersion(): string
    {
        return self::ChainVersion;
    }

    public static function getDefaultBlockReward(): string
    {
        return self::DefaultBlockReward;
    }

    public static function getDesiredBlockTime(): int
    {
        return self::DesiredBlockTime;
    }

    public static function getDefaultDifficulty(): int
    {
        return self::DefaultDifficulty;
    }

    public static function getGenesisDate(): int
    {
        return self::GenesisDate;
    }

    public static function getHostIdService(): string
    {
        return self::HostIdService;
    }

    public static function getInitialPeers(): array
    {
        return self::InitialPeerList;
    }

    public static function getLockHeight(): int
    {
        return self::LockHeight;
    }

    public static function getMaxCurrencySupply(): string
    {
        return self::MaxCurrencySupply;
    }

    public static function getMaxLoops(): int
    {
        return self::MaxLoops;
    }

    public static function getMaxPeers(): int
    {
        return self::MaxPeers;
    }

    public static function getMaxRebroadcastPeers(): int
    {
        return self::MaxRebroadcastPeers;
    }

    public static function getMaxScriptLength(): int
    {
        return self::MaxScriptLength;
    }

    public static function getMaxSpentTransactionCount(): int
    {
        return self::MaxSpentPerTransaction;
    }

    public static function getMaxTransactionSize(): int
    {
        return self::MaxTransactionSize;
    }

    public static function getMaxUnspentTransactionCount(): int
    {
        return self::MaxUnspentPerTransaction;
    }

    public static function getMinimumTransactionFee(): string
    {
        return self::MinimumFee;
    }

    public static function getNetworkIdentifier(): string
    {
        return self::NetworkIdentifier;
    }

    public static function getPeerRequestTimeout(): int
    {
        return self::PeerRequestTimeout;
    }

    public static function getProductCopyright(): string
    {
        return self::ProductCopyright;
    }

    public static function getProductName(): string
    {
        return self::ProductName;
    }

    public static function getPublicAccess(): bool
    {
        return self::PublicApiAccess;
    }

    public static function getVersion(): string
    {
        return self::Version;
    }

}