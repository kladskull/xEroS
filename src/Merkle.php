<?php declare(strict_types=1);

namespace Blockchain;

/**
 * Class Merkle
 * @package Blockchain
 */
class Merkle
{
    /**
     * @param array $transactions
     * @return string|null
     */
    public function computeMerkleHash(array $transactions): ?string
    {
        // sort it
        $transactions = Transaction::sort($transactions); // sort the array

        // calculate the merkle root
        $tree = new \drupol\phpmerkle\Merkle();

        foreach ($transactions as $tx) {
            $tree[] = $tx['transaction_id'];
        }

        // compute the merkle root
        return $tree->hash();
    }
}
