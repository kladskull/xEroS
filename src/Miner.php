<?php declare(strict_types=1);

namespace Xeros;

use Exception;
use JetBrains\PhpStorm\ArrayShape;

class Miner
{
    protected Block $block;
    protected Pow $pow;
    protected DataStore $store;

    public function __construct()
    {
        $this->block = new Block();
        $this->pow = new Pow();
        $this->store = new DataStore();
    }

    public function numberOfSetBits(int $v): int
    {
        $c = $v - (($v >> 1) & 0x55555555);
        $c = (($c >> 2) & 0x33333333) + ($c & 0x33333333);
        $c = (($c >> 4) + $c) & 0x0F0F0F0F;
        $c = (($c >> 8) + $c) & 0x00FF00FF;
        return (($c >> 16) + $c) & 0x0000FFFF;
    }

    public function hashOutput($hashesPerSecond): string
    {
        $magnitudes = ['hps', 'Khps', 'Mhps', 'Ghps', 'Thps', 'Phps', 'Ehps'];
        $mag = log($hashesPerSecond, 1000);

        return sprintf('%.2f%s', $hashesPerSecond / (1000 ** ((int)$mag)), $magnitudes[(int)$mag]);
    }

    public function mineBlock(
        string $blockHeader,
        int $difficulty,
        int $height,
        int $startingNonce = 0,
        bool $checkNewBlocks = true
    ): array|bool {
        $start = time();
        $nonce = $startingNonce;
        $bytes = (int)ceil($difficulty / 8);
        $elapsed = 0;
        $hashes = 0;

        $displayFreq = 1000000;
        if ($_ENV['ENVIRONMENT'] != 'live') {
            $displayFreq = 25000;
        }

        while (1) {
            // create work
            $bytesSet = 0;
            $hash = $this->pow->calculate($blockHeader, dechex($nonce));

            // cycle through all bytes
            for ($i = 0; $i < $bytes; $i++) {
                $byte = ord($hash[$i]);

                // if the last bit, mask the bits we don't care about
                if ($i === ($bytes - 1)) {
                    $bit = 8 - ($difficulty % 8);

                    // the breaks were left out intentionally
                    switch ($bit) {
                        case 7:
                            $byte &= ~64;
                        case 6:
                            $byte &= ~32;
                        case 5:
                            $byte &= ~16;
                        case 4:
                            $byte &= ~8;
                        case 3:
                            $byte &= ~4;
                        case 2:
                            $byte &= ~2;
                        case 1:
                            $byte &= ~1;
                        default:
                            break;
                    }
                }

                // count the bites set
                $bytesSet += $this->numberOfSetBits($byte);

                // break early if we have a set bit
                if ($bytesSet) {
                    break;
                }
            }

            // we have it
            if ($bytesSet === 0) {
                break;
            }

            // continue...
            ++$nonce;
            ++$hashes;

            // report
            if ($hashes % $displayFreq === 0) {
                $elapsed = max((time() - $start), 1);
                $hashesPerSecond = $hashes / $elapsed;
                $nonceHex = dechex($nonce);
                Console::log(
                    "Mining for height: " . $height . "  Hash rate: " . $this->hashOutput($hashesPerSecond) .
                    "  difficulty: {$difficulty}  nonce: {$nonceHex}  elapsed: {$elapsed}s"
                );
                usleep(1);
            }

            // break out?
            if ($checkNewBlocks && $hashes % 250000 === 0) {
                // check state, we may need to sync from the network
                $currentHeight = $this->block->getCurrentHeight();

                // if we're generating for block x, make sure there is nothing greater
                if ($currentHeight >= $height) {
                    Console::log('New block found on the network, restarting');
                    return [
                        'nonce' => $nonce,
                        'hash' => '',
                        'hashes' => $hashes,
                        'elapsed_time' => $elapsed,
                        'result' => true,
                    ];
                }
            }
        }

        $elapsed = time() - $start;
        return [
            'nonce' => $nonce,
            'hash' => bin2hex($hash) ?: null,
            'hashes' => $hashes,
            'elapsed_time' => $elapsed,
            'result' => true,
        ];
    }
}