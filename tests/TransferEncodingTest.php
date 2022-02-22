<?php declare(strict_types=1);

namespace Xeros;

use PHPUnit\Framework\TestCase;

class TransferEncodingTest extends TestCase
{
    private TransferEncoding $transferEncoding;

    protected function setUp(): void
    {
        $this->transferEncoding = new TransferEncoding();
    }

    /**
     * (add multiple functions below)
     * @dataProvider textDataProvider
     * @dataProvider hexDataProvider
     * @throws Exception
     */
    public function testEncodeDecodeBase58Strings(string $text): void
    {
        $b58 = $this->transferEncoding->encodeBase58($text);
        $transformed = $this->transferEncoding->decodeBase58($b58);
        $this->assertEquals($text, $transformed);
    }

    /**
     * (add multiple functions below)
     * @dataProvider hexDataProvider
     * @throws Exception
     */
    public function testEncodeDecodeBase58Binary(string $hex): void
    {
        $b58 = $this->transferEncoding->encodeBase58(hex2bin($hex));
        $transformed = bin2hex($this->transferEncoding->decodeBase58($b58));
        $this->assertEquals($hex, $transformed);
    }

    /**
     * (add multiple functions below)
     * @dataProvider hexDataProvider
     * @throws Exception
     */
    public function testIsBase58(string $hex): void
    {
        $this->assertTrue($this->transferEncoding->isBase58($this->transferEncoding->binToBase58($hex)));
    }

    /**
     * @throws Exception
     */
    public function testIsBase58BlankOrNull(): void
    {
        $this->assertFalse($this->transferEncoding->isBase58(''));
    }

    /**
     * (add multiple functions below)
     * @dataProvider badBase58StringsProvider
     * @throws Exception
     */
    public function testDecodeBase58WithInvalidCharacters(string $base58): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->transferEncoding->decodeBase58($base58);
    }

    /**
     * @dataProvider hexDataProvider
     */
    public function testBinToBase58($hex): void
    {
        $binary = hex2bin($hex);
        $base58 = $this->transferEncoding->binToBase58($binary);
        $transformed = $this->transferEncoding->base58ToBin($base58);
        $this->assertEquals($binary, $transformed);
    }

    /**
     * @dataProvider hexDataProvider
     */
    public function testHexToBase58($hex): void
    {
        $base58 = $this->transferEncoding->binToBase58($hex);
        $transformed = $this->transferEncoding->base58ToBin($base58);
        $this->assertEquals($hex, $transformed);
    }

    public function badBase58StringsProvider(): array
    {
        return [
            ["O3s5UxvFBmqARZ153n6VyY"],
            ["0Hsifm1gvP41CGBFgD5r2H"],
            ["I7BMPpwfPH9WKpLbJZ3wcX"],
            ["?YNjCV2kwhUyFWGjaiDvuE"],
        ];
    }

    public function hexDataProvider(): array
    {
        return [
            ['b8fb9cd9d7993240b3e97c23d391a397'],
            ['83dcbc62439edb31c8bc1678c7c7da6e'],
            ['87ea5cc9925f87f1547a6afc4aef1ae5'],
            ['723cc884423d7a87be59957dd441e694'],
            ['1493b969b75100b490c7505043b7e459'],
            ['10091f86408db9f1fa89961d88f8eba82d24ef6f8c6e18616ac34c86fe63a764'],
            ['71e2b38bb37389aaae7eebbcbfdaf2b481dac26ed20a9be7e92f9aa543a24bd5'],
            ['540e2fc328d997440d1e059119ff435275991af5e590c3cf6c4f3d1d29c1d358'],
            ['1aa6babfa42d727f3f24debbccb6044572b4a3817d5ed3778b6e0110a65f71e1'],
            ['3b9ad5256b028633d5cd5979c22eacd943fafebb9ec32d3d1f6aa3ada983e2de'],
            ['0e77f3dbf398e6c23e5e3f8e62cc08d6ac75c6aa4985ae4ac24f85aaeb17f8a075fa366b78381043f886a8aa6adbfcc9a900f3105ceef68016afac6c80283351'],
            ['fc09253606dcf56b87d34811dbd021c386317bd2c9f7baa39e5e8d1456ad44c241a25d9ef66cbecdbba4ccbcb6ccd50338cb1c73e4b1f7e6ec9d277013cc3f94'],
            ['89e6c3d3c3b3931cc3e1a86c77263900f339ed8b006ab5bd2e2d17e08d6f3615f7e1ead4777d942ba8c236ff5896ef3850ccb75b4ed8400e7626cb0f8cac5b1d'],
            ['4c550da52aa5ab72022092f8ec845613cf878b35101ca72c44041b6fa5480e8e04f8a29e98704ede248ed9b4998ababb37da29dfd202a993fae2e65530bff6b3'],
            ['b312dbaf4e9f75c2118bcba5080e7adb8bd30f2418843abf5c780fd32487f9d7e593e5ae0591dc3040e472ad8dd082945afcc4b064dcb128a9213a950c454e60'],
        ];
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
