<?php declare(strict_types=1);

namespace Blockchain;

use InvalidArgumentException;
use RuntimeException;

class Merkle_new
{
    private array $hashList;
    private mixed $hashAlgorithm;

    public function __construct(array $data, $hashAlgorithm = 'sha256')
    {
        if (empty($data)) {
            throw new InvalidArgumentException("Input data array cannot be empty.");
        }

        $this->hashAlgorithm = $hashAlgorithm;
        $this->hashList = $this->build($data);
    }

    private function hash($data): string
    {
        return hash($this->hashAlgorithm, $data);
    }

    private function build(array $data): array
    {
        $hashList = [];

        foreach ($data as $item) {
            $hashList[] = $this->hash($item);
        }

        // Duplicate the last item until the total number is a power of two
        while (count($hashList) & (count($hashList) - 1)) {
            $hashList[] = end($hashList);
        }

        return $hashList;
    }

    public function getRootHash()
    {
        return $this->buildTree($this->hashList)[0];
    }

    private function buildTree(array $hashList): array
    {
        $tree = $hashList;

        while (count($tree) > 1) {
            $level = [];

            for ($i = 0; $i < count($tree); $i += 2) {
                $left = $tree[$i];
                $right = $tree[$i + 1] ?? $tree[$i]; // If right node doesn't exist, use left node hash

                $level[] = $this->hash($left . $right);
            }

            $tree = $level;
        }

        return $tree;
    }

    public function verify(array $data, $rootHash): bool
    {
        if (empty($data)) {
            return false;
            //throw new InvalidArgumentException("Input data array cannot be empty.");
        }

        $treeRootHash = $this->getRootHash();

        if ($rootHash !== $treeRootHash) {
            return false;
            //throw new RuntimeException("Root hash mismatch. Data has been tampered with.");
        }

        // Verify each transaction hash against the calculated Merkle root hash
        foreach ($data as $item) {
            if (!in_array($this->hash($item), $this->hashList)) {
                return false;
                //throw new RuntimeException("Transaction hash mismatch. Data has been tampered with.");
            }
        }

        return true;
    }
}

/*
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
*/

/*
// Example usage:

$data = ["Transaction 1", "Transaction 2", "Transaction 3"];

try {
$merkleTree = new MerkleTree($data);

echo "Root hash: " . $merkleTree->getRootHash() . "\n";

// Simulating a verification process
$rootHash = $merkleTree->getRootHash();
$isVerified = $merkleTree->verify($data, $rootHash);
echo "Is verified: " . ($isVerified ? "true" : "false") . "\n";
} catch (InvalidArgumentException $e) {
echo "Error: " . $e->getMessage() . "\n";
} catch (RuntimeException $e) {
echo "Error: " . $e->getMessage() . "\n";
}
*/