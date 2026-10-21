<?php declare(strict_types=1);

namespace Blockchain;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WalletEncryptionTest extends TestCase
{
    private PDO $db;
    private Account $account;
    private mixed $previous;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->beginTransaction();
        $this->account = new Account();
        $this->previous = $_ENV['WALLET_PASSPHRASE'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) {
            unset($_ENV['WALLET_PASSPHRASE']);
        } else {
            $_ENV['WALLET_PASSPHRASE'] = $this->previous;
        }

        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function storedKey(int $id): string
    {
        $stmt = $this->db->prepare('SELECT private_key FROM accounts WHERE id=:id');
        $stmt->execute([':id' => $id]);

        return (string)$stmt->fetchColumn();
    }

    private function canSign(array $account): bool
    {
        $openSsl = new OpenSsl();
        $signature = $openSsl->signAndVerifyData('text', $account['public_key'], $account['private_key']);

        return $openSsl->verifySignature('text', $signature, $account['public_key']);
    }

    public function testKeyIsStoredEncryptedAndReadBackUsable(): void
    {
        $_ENV['WALLET_PASSPHRASE'] = 'correct horse';
        $id = $this->account->create();

        $this->assertTrue(Account::isEncrypted($this->storedKey($id)));
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $this->storedKey($id));

        $account = $this->account->get($id);
        $this->assertFalse(Account::isEncrypted($account['private_key']));
        $this->assertTrue($this->canSign($account));
        $this->assertEquals($account['private_key'], $this->account->getNewestAccount()['private_key']);
    }

    public function testWithoutAPassphraseKeysStayPlain(): void
    {
        unset($_ENV['WALLET_PASSPHRASE']);
        $id = $this->account->create();

        $this->assertFalse(Account::isEncrypted($this->storedKey($id)));
        $this->assertTrue($this->canSign($this->account->get($id)));
    }

    public function testLockedWalletNeedsTheRightPassphrase(): void
    {
        $_ENV['WALLET_PASSPHRASE'] = 'correct horse';
        $id = $this->account->create();

        $_ENV['WALLET_PASSPHRASE'] = 'wrong';

        try {
            $this->account->get($id);
            $this->fail('a wrong passphrase must not open the key');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('WALLET_PASSPHRASE', $e->getMessage());
        }

        unset($_ENV['WALLET_PASSPHRASE']);
        $this->expectException(RuntimeException::class);
        $this->account->get($id);
    }

    public function testStoredKeysCanBeEncryptedLater(): void
    {
        unset($_ENV['WALLET_PASSPHRASE']);
        $id = $this->account->create();
        $plain = $this->account->get($id)['private_key'];

        $_ENV['WALLET_PASSPHRASE'] = 'later';
        $this->assertGreaterThanOrEqual(1, $this->account->encryptStoredKeys('later'));
        $this->assertTrue(Account::isEncrypted($this->storedKey($id)));
        $this->assertEquals(0, $this->account->encryptStoredKeys('later'));

        // the same key comes back out
        $this->assertEquals(
            openssl_pkey_get_details(openssl_pkey_get_private($plain))['key'],
            openssl_pkey_get_details(openssl_pkey_get_private($this->account->get($id)['private_key']))['key']
        );
    }

    public function testEncryptingNeedsAPassphrase(): void
    {
        $this->expectException(RuntimeException::class);
        $this->account->encryptStoredKeys('');
    }
}
