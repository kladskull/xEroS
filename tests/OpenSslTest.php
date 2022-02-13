<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class OpenSslTest extends TestCase
{
    private OpenSsl $openssl;
    private TransferEncoding $transferEncoding;

    protected function setUp(): void
    {
        $this->openssl = new OpenSsl();
        $this->transferEncoding = new TransferEncoding();
    }

    public function testcreateRsaKeyPair(): void
    {
        $keys = $this->openssl->createRsaKeyPair();
        $this->assertCount(3, $keys);
        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', $keys['public_key']);
        $this->assertStringContainsString('-----END PUBLIC KEY-----', $keys['public_key']);
        $this->assertStringContainsString('-----BEGIN PRIVATE KEY-----', $keys['private_key']);
        $this->assertStringContainsString('-----END PRIVATE KEY-----', $keys['private_key']);

        $this->assertNotEmpty($keys['public_key_raw']);

        $this->assertGreaterThanOrEqual(450, strlen($keys['public_key']), "public key is not as big as it should be");
        $this->assertGreaterThanOrEqual(1700, strlen($keys['private_key']), "public key is not as big as it should be");
    }

    /**
     * @throws Exception
     */
    public function testGetRandomBytesBinary(): void
    {
        $this->assertEquals(32, strlen($this->openssl->getRandomBytesBinary()));
        $this->assertEquals(64, strlen($this->openssl->getRandomBytesBinary(64)));
        $this->assertEquals(1, strlen($this->openssl->getRandomBytesBinary(1)));
    }

    /**
     * @throws Exception
     */
    public function testGetRandomBytesHex(): void
    {
        $this->assertEquals(64, strlen($this->openssl->getRandomBytesHex()));
        $this->assertEquals(128, strlen($this->openssl->getRandomBytesHex(64)));
        $this->assertEquals(2, strlen($this->openssl->getRandomBytesHex(1)));
    }

    public function testRawToPem(): void
    {
        $keys = $this->openssl->createRsaKeyPair();

        $public_key = $keys['public_key'];
        $private_key = $keys['private_key'];

        $rawPublicKey = $this->openssl->pemToBin($public_key);
        $public_key_transformed = $this->openssl->binToPem($rawPublicKey, false);
        $this->assertEquals($public_key, $public_key_transformed);

        $rawPrivateKey = $this->openssl->pemToBin($private_key);
        $private_key_transformed = $this->openssl->binToPem($rawPrivateKey, true);
        $this->assertEquals($private_key, $private_key_transformed);
    }

    /**
     * (add multiple functions below)
     * @dataProvider textDataProvider
     * @throws Exception
     */
    public function testSignAndVerifyData($text): void
    {
        $keys = $this->openssl->createRsaKeyPair();

        $public_key = $keys['public_key'];
        $private_key = $keys['private_key'];
        $signature = $this->openssl->signAndVerifyData($text, $public_key, $private_key);

        $this->assertGreaterThanOrEqual(349, strlen($signature));
        $this->assertTrue($this->openssl->verifyPow($text, $signature, $public_key));
    }

    public function textDataProvider(): array
    {
        return [
            ['This is a signed message.'],
            ['Lorem Ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry\'s standard dummy text ever since the 1500s, when an unknown printer took a galley of type and scrambled it to make a type specimen book. It has survived not only five centuries, but also the leap into electronic typesetting, remaining essentially unchanged. It was popularised in the 1960s with the release of Letraset sheets containing Lorem Ipsum passages, and more recently with desktop publishing software like Aldus PageMaker including versions of Lorem Ipsum.'],
            ['ℕ ⊆ ℕ₀ ⊂ ℤ ⊂ ℚ ⊂ ℝ ⊂ ℂ, ⊥ < a ≠ b ≡ c ≤ d ≪ ⊤ ⇒ (A ⇔ B),'],
            ['გთხოვთ ახლავე გაიაროთ რეგისტრაცია ის მეათე საერთაშორისო კონფერენციაზე დასასწრებად, რომელიც გაიმართება 10-12 მარტს,ქ. მაინცში, გერმანიაში. კონფერენცია შეჰკრებს ერთად მსოფლიოს ექსპერტებს ისეთ დარგებში როგორიცაა ინტერნეტი და Unicode-ი, ინტერნაციონალიზაცია და ლოკალიზაცია, Unicode-ის გამოყენება ოპერაციულ სისტემებსა, და გამოყენებით პროგრამებში, შრიფტებში, ტექსტების დამუშავებასა და მრავალენოვან კომპიუტერულ სისტემებში.'],
            [''],
            ['\012345'],
        ];
    }
}
