<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class NetworkTest extends TestCase
{
    private const NETWORKS = ['live', 'test', 'dev'];

    private Address $address;
    private OpenSsl $openSsl;

    protected function setUp(): void
    {
        $this->address = new Address();
        $this->openSsl = new OpenSsl();
    }

    private function on(string $environment, callable $work): mixed
    {
        return Config::withEnvironment($environment, $work);
    }

    public function testNetworksDoNotShareIdsOrAddressFormats(): void
    {
        foreach (['getNetworkIdentifier', 'getAddressHeader', 'getChainVersion'] as $getter) {
            $values = array_map(fn ($env) => $this->on($env, fn () => Config::$getter()), self::NETWORKS);

            $this->assertCount(3, array_unique($values), $getter);
        }
    }

    public function testAddressIsOnlyValidOnItsOwnNetwork(): void
    {
        $keys = $this->openSsl->createRsaKeyPair();

        foreach (self::NETWORKS as $made) {
            $address = $this->on($made, fn () => $this->address->create($keys['public_key']));

            foreach (self::NETWORKS as $checked) {
                $valid = $this->on($checked, fn () => $this->address->validateAddress($address));
                $this->assertSame($made === $checked, $valid, $made . ' address checked on ' . $checked);
            }
        }
    }

    public function testSameTransactionHasADifferentIdOnEachNetwork(): void
    {
        $transaction = new Transaction();
        $tx = [
            'date_created' => time(),
            'public_key' => 'key',
            'peer' => 'test',
            'version' => TransactionVersion::TRANSFER,
            'height' => 2,
            Transaction::INPUTS => [],
            Transaction::OUTPUTS => [],
        ];

        $ids = array_map(fn ($env) => $this->on($env, fn () => $transaction->computeId($tx)), self::NETWORKS);
        $hashes = array_map(fn ($env) => $this->on($env, fn () => $transaction->signatureHash($tx)), self::NETWORKS);

        // a transaction signed for one network cannot be replayed on another
        $this->assertCount(3, array_unique($ids));
        $this->assertCount(3, array_unique($hashes));
    }

    public function testOnlyLiveHasBuiltInSeedPeers(): void
    {
        $had = array_key_exists('SEED_PEERS', $_ENV);
        $seeds = $_ENV['SEED_PEERS'] ?? null;
        unset($_ENV['SEED_PEERS']);

        try {
            $this->assertNotEmpty($this->on('live', fn () => Config::getInitialPeers()));
            $this->assertEquals([], $this->on('test', fn () => Config::getInitialPeers()));
            $this->assertEquals([], $this->on('dev', fn () => Config::getInitialPeers()));
        } finally {
            if ($had) {
                $_ENV['SEED_PEERS'] = $seeds;
            }
        }
    }

    public function testEnvironmentIsRestoredAfterAnError(): void
    {
        $before = Config::getEnvironment();

        try {
            Config::withEnvironment($before === 'live' ? 'test' : 'live', function () {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertEquals($before, Config::getEnvironment());
    }
}
