<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The node and the miner are separate processes on one database. These tests make the miner's block land in the
 * middle of the node checking a peer's block.
 */
class NodeRaceTest extends TestCase
{
    use ChainFixtures;

    /**
     * Two blocks on the current tip that both carry the same transfer, as two miners would make.
     */
    private function competingBlocks(): array
    {
        $coinbase = $this->fundOwner();
        $tx = $this->transfer([[$coinbase['transaction_id'], 0]], $this->thief, $this->spendAmount(), $this->owner);
        $tip = $this->block->getCurrent();

        // a block's time must pass the median, and both must not land in the same second as the tip
        while (time() <= (int)$tip['date_created']) {
            usleep(100000);
        }

        $ours = $this->candidateOn($tip, $this->owner, [$tx]);
        $theirs = $this->candidateOn($tip, $this->thief, [$tx]);
        $this->assertNotEquals($ours['block_id'], $theirs['block_id']);

        return [$ours, $theirs];
    }

    /**
     * A node whose Block runs $whileChecking the first time it checks a block.
     */
    private function nodeWithMiner(callable $whileChecking, string $method): Node
    {
        $racing = new class($whileChecking, $method) extends Block {
            private $hook;

            public function __construct(callable $hook, private string $method)
            {
                parent::__construct();
                $this->hook = $hook;
            }

            public function validateFullBlock(array $block, bool $checkSpends = true): array
            {
                $result = parent::validateFullBlock($block, $checkSpends);

                if ($this->method === 'validate' && $this->hook !== null) {
                    ($this->hook)();
                    $this->hook = null;

                    return parent::validateFullBlock($block, $checkSpends);
                }

                return $result;
            }

            public function acceptBlock(string $blockId, int $height): bool
            {
                if ($this->method === 'accept' && $this->hook !== null) {
                    ($this->hook)();
                    $this->hook = null;
                }

                return parent::acceptBlock($blockId, $height);
            }
        };

        $node = new Node();
        $property = (new ReflectionClass($node))->getProperty('block');
        $property->setValue($node, $racing);

        return $node;
    }

    private function handle(Node $node, array $block): string
    {
        return (new ReflectionClass($node))->getMethod('handleBlock')->invoke($node, $block);
    }

    public function testMinerBlockDuringCheckingDoesNotMakeAPeerBlockInvalid(): void
    {
        [$ours, $theirs] = $this->competingBlocks();
        $this->assertTrue($this->block->add($ours, false, true));

        // our miner's block becomes the tip while the node checks the peer's block
        $node = $this->nodeWithMiner(fn () => $this->block->acceptBlock($ours['block_id'], (int)$ours['height']), 'validate');
        $status = $this->handle($node, $theirs);

        $this->assertNotEquals('invalid', $status);
        $this->assertNotEmpty($this->block->getByBlockId($theirs['block_id']));

        // the two compete on equal work, the lower hash wins
        $winner = strcmp($ours['hash'], $theirs['hash']) < 0 ? $ours : $theirs;
        $this->assertEquals($winner['block_id'], $this->block->getCurrent()['block_id']);
    }

    public function testMinerBlockBeforeAcceptingStillComparesTheTwo(): void
    {
        [$ours, $theirs] = $this->competingBlocks();
        $this->assertTrue($this->block->add($ours, false, true));

        // the peer's block checks out, then our miner's block wins the race to the tip
        $node = $this->nodeWithMiner(fn () => $this->block->acceptBlock($ours['block_id'], (int)$ours['height']), 'accept');
        $status = $this->handle($node, $theirs);

        $this->assertNotEquals('invalid', $status);
        $winner = strcmp($ours['hash'], $theirs['hash']) < 0 ? $ours : $theirs;
        $this->assertEquals($winner['block_id'], $this->block->getCurrent()['block_id']);
    }
}
