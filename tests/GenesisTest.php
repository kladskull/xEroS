<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GenesisTest extends TestCase
{
    private Block $block;
    private Pow $pow;
    private Transaction $transaction;

    protected function setUp(): void
    {
        $this->block = new Block();
        $this->pow = new Pow();
        $this->transaction = new Transaction();
    }

    public static function environmentProvider(): array
    {
        return [['live'], ['test'], ['dev']];
    }

    /**
     * Problems with a genesis block, empty if it is sound.
     */
    private function problems(array $genesis): array
    {
        $problems = [];
        $header = $this->block->generateBlockHeader($genesis);

        if (!$this->pow->verifyPow($genesis['hash'], $header, $genesis['nonce'])) {
            $problems[] = 'hash does not match the header';
        }

        if (!$this->pow->meetsDifficulty($genesis['hash'], (int)$genesis['difficulty'])) {
            $problems[] = 'hash does not meet the difficulty';
        }

        $check = $this->transaction->validate($genesis['transactions'][0], 1);

        if ($check['validated'] !== true) {
            $problems[] = 'coinbase: ' . $check['reason'];
        }

        return $problems;
    }

    #[DataProvider('environmentProvider')]
    public function testGenesisIsSound(string $environment): void
    {
        $genesis = $this->block->genesis($environment);
        $this->assertNotEmpty($genesis);

        Config::withEnvironment($environment, function () use ($genesis, $environment) {
            $this->assertEquals([], $this->problems($genesis));
            $this->assertEquals(
                $this->block->generateId(Config::getNetworkIdentifier(), '', $genesis['date_created'], 1, $genesis['merkle_root']),
                $genesis['block_id']
            );
            $this->assertEquals(Config::getNetworkIdentifier(), $genesis['network_id']);
            $this->assertStringStartsWith(
                Config::getAddressHeader(),
                $genesis['transactions'][0][Transaction::OUTPUTS][0]['address']
            );
        });

        $this->assertEquals((new Merkle())->computeMerkleHash($genesis['transactions']), $genesis['merkle_root']);
        $this->assertEquals(Config::getDefaultDifficulty($environment), (int)$genesis['difficulty']);
        $this->assertGreaterThanOrEqual(Config::getGenesisDate(), $genesis['date_created']);
    }

    public function testEachNetworkHasItsOwnGenesis(): void
    {
        $ids = array_map(fn ($env) => $this->block->genesis($env[0])['block_id'], self::environmentProvider());

        $this->assertCount(3, array_unique($ids));
    }

    public function testStoredGenesisIsOurs(): void
    {
        $genesis = $this->block->genesis(Config::getEnvironment());
        $stored = $this->block->getByHeight(1);

        $this->assertEquals($genesis['block_id'], $stored['block_id']);
        $this->assertEquals($genesis['hash'], $stored['hash']);
        $this->assertTrue($this->block->validateFullBlock($genesis)['validated']);
    }

    public function testOtherGenesisIsRejected(): void
    {
        $genesis = $this->block->genesis(Config::getEnvironment());
        $keys = (new OpenSsl())->createRsaKeyPair();
        $other = $this->block->createGenesis(Config::getEnvironment(), time(), $keys['public_key'], $keys['private_key']);
        $other['nonce'] = '0';
        $other['hash'] = bin2hex($this->pow->calculate($this->block->generateBlockHeader($other), '0'));

        $this->assertFalse($this->block->validateFullBlock($other)['validated']);

        // same hash but a different block id is not ours either
        $other['hash'] = $genesis['hash'];
        $this->assertFalse($this->block->validateFullBlock($other)['validated']);
    }

    public function testCreatedGenesisCanBeMinedAndPaysTheKey(): void
    {
        $keys = (new OpenSsl())->createRsaKeyPair();
        $genesis = $this->block->createGenesis('dev', time(), $keys['public_key'], $keys['private_key']);
        $header = $this->block->generateBlockHeader($genesis);

        for ($nonce = 0; ; $nonce++) {
            $hash = bin2hex($this->pow->calculate($header, dechex($nonce)));

            if ($this->pow->meetsDifficulty($hash, (int)$genesis['difficulty'])) {
                break;
            }
        }

        $genesis['nonce'] = dechex($nonce);
        $genesis['hash'] = $hash;
        $coinbase = $genesis['transactions'][0];

        $this->assertEquals([], $this->problems($genesis));
        $this->assertEquals((new Address())->create($keys['public_key']), $coinbase[Transaction::OUTPUTS][0]['address']);
        $this->assertEquals($this->block->getRewardValue(1), $coinbase[Transaction::OUTPUTS][0]['value']);
        $this->assertEquals(1 + Config::getLockHeight(), (int)$coinbase[Transaction::OUTPUTS][0]['lock_height']);
    }

    public function testUnknownEnvironmentIsLive(): void
    {
        $this->assertEquals('live', Config::normalizeEnvironment('prod'));
        $this->assertEquals('live', Config::normalizeEnvironment('production'));
        $this->assertEquals('live', Config::normalizeEnvironment('LIVE'));
        $this->assertEquals('dev', Config::normalizeEnvironment('development'));
        $this->assertEquals('test', Config::normalizeEnvironment(' test '));
        $this->assertEquals(Config::getDefaultDifficulty('live'), Config::getDefaultDifficulty('prod'));
    }
}
