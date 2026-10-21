<?php declare(strict_types=1);

namespace Blockchain;


class Merkle
{
    private array $hashList;
    private mixed $hashAlgorithm;

    public function __construct(array $data = [], $hashAlgorithm = 'sha256')
    {
        $this->hashAlgorithm = $hashAlgorithm;
        $this->hashList = $this->build($data);
    }

    public function computeMerkleHash(array $transactions): ?string
    {
        $transactions = Transaction::sort($transactions);

        // the transaction id is the hash of the transaction contents, the signature is added so the root commits
        // to it as well
        $transactionIds = array_map(function ($transaction) {
            return $transaction['transaction_id'] . ':' . $transaction['signature'];
        }, $transactions);

        $merkleTree = new Merkle($transactionIds, 'sha256');

        return $merkleTree->getRootHash();
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
        }

        $treeRootHash = $this->getRootHash();

        if ($rootHash !== $treeRootHash) {
            return false;
        }

        // Verify each transaction hash against the calculated Merkle root hash
        foreach ($data as $item) {
            if (!in_array($this->hash($item), $this->hashList)) {
                return false;
            }
        }

        return true;
    }
}
