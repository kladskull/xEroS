<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;

class MerkleTest extends TestCase
{
    private Merkle $merkle;

    protected function setUp(): void
    {
        $this->merkle = new Merkle();
    }

    private function tx(string $id, string $signature = 'sig'): array
    {
        return ['transaction_id' => $id, 'signature' => $signature, 'version' => TransactionVersion::TRANSFER, 'date_created' => 1700000000];
    }

    public function testSingleTransactionRootIsItsLeafHash(): void
    {
        $this->assertEquals(hash('sha256', 'a:sig'), $this->merkle->computeMerkleHash([$this->tx('a')]));
    }

    public function testTwoTransactions(): void
    {
        $expected = hash('sha256', hash('sha256', 'a:sig') . hash('sha256', 'b:sig'));

        $this->assertEquals($expected, $this->merkle->computeMerkleHash([$this->tx('a'), $this->tx('b')]));
    }

    public function testOddCountRepeatsTheLastLeaf(): void
    {
        $a = hash('sha256', 'a:sig');
        $b = hash('sha256', 'b:sig');
        $c = hash('sha256', 'c:sig');
        $expected = hash('sha256', hash('sha256', $a . $b) . hash('sha256', $c . $c));

        $this->assertEquals($expected, $this->merkle->computeMerkleHash([$this->tx('a'), $this->tx('b'), $this->tx('c')]));
    }

    public function testOrderDoesNotMatter(): void
    {
        $txs = [$this->tx('a'), $this->tx('b'), $this->tx('c')];

        $this->assertEquals(
            $this->merkle->computeMerkleHash($txs),
            $this->merkle->computeMerkleHash(array_reverse($txs))
        );
    }

    public function testSignatureIsCommitted(): void
    {
        $this->assertNotEquals(
            $this->merkle->computeMerkleHash([$this->tx('a', 'one')]),
            $this->merkle->computeMerkleHash([$this->tx('a', 'two')])
        );
    }

    public function testVerify(): void
    {
        $data = ['x', 'y', 'z'];
        $tree = new Merkle($data);
        $root = $tree->getRootHash();

        $this->assertTrue($tree->verify($data, $root));
        $this->assertFalse($tree->verify($data, str_repeat('0', 64)));
        $this->assertFalse($tree->verify(['w'], $root));
        $this->assertFalse($tree->verify([], $root));
    }
}
