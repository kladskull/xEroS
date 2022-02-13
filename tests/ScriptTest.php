<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ScriptTest extends TestCase
{
    private array $dummyBlock;

    protected function setUp(): void
    {
        $height = 5;
        $date = time();
        $block = new Block();

        $this->dummyBlock = [
            'network_id' => Config::getNetworkIdentifier(),
            'previous_block_id' => '',
            'date_created' => $date,
            'height' => $height,
            'difficulty' => Config::getDefaultDifficulty(),
            'merkle_root' => 'SOME_ROOT',
            'transactions' => [
                [
                    'transaction_id' => 'TX_ID',
                    'date_created' => $date,
                    'fee' => "0.5",
                    'version' => Version::Coinbase,
                    'signature' => "480f0fd1080d84692b6d0371930d5ed40c5a954473d8fbe4ad9b5f774d41d85eaa68868acb88f07e5b484e97d6a3d1513285962829dbd8031984c24a5c9208c51d31fd820cd87741b9d2f614305863261d50036808fdae0685d89a3a41fccaeadd10c689cbe4b8a8d49db2bfc83e01f53650930c212c11292f79a88470991b5c726625a9e8d5af0decb45c9ef7b88e924bfbe51855062b0d5667f2fbdbdc1079dfe0978f341ec6104ea09b65475b767d41ac280ed7e11c6c2ce5c55f3255447ac343d2d87724c8ebed6dbbb4527a51901016e184d2f5d0720db26efae64369464f81c377ad0d378fb1a1a5495a366e956ca2689e20f6a54103d40a761a8472d5",
                    'public_key' => "MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAufnOzEGWNt3F8jVB6iH1MWtGSA7J+tczrf0fsA84WUBqtOwzafHVSc6UAylOyHzHXgD5hlOIMThNKDwYz6tGqv486MVuxyj40iZ+9d0hACpJJ1uA4S8PYV4vl5yszBu8zo3ue481b/tKbSqdp4UTmuuWhrGl0xK/erZF6rW634OwUCD/hV9e061hRo/844cAudLfPyFZT02SkrNTaEfdmRiQhZolj3PgbD+Pq5lN54sK7xjUA1NuFzoZdGRjQ6UX/MHAGXsHanEvndR1wS9CZMoZXBHJ4aGD7GH0sYuzYAGcc9ZwHOFYAZ6YGm8nI71fmjHiy0hkW8z1s4m11A+tawIDAQAB",
                    'tx_in' => [
                        [
                            'tx_id' => '1',
                            'prev_transaction_id' => 'PREV_TX',
                            'prev_tx_id' => 'PREV_TX',
                            'script' => '',
                        ],
                    ],
                    'tx_out' => [
                        [
                            'tx_id' => '1',
                            'address' => 'Bc14BDGV11UKCHhjSQcHc4cfSky1dfkWPfnD',
                            'value' => '50',
                            'script' => '',
                            'lock_height' => '51',
                            'version' => Version::Coinbase,
                        ],
                    ],
                ],
            ],
            'transaction_count' => 1,
            'previous_hash' => 'PREV_HASH',
            'hash' => 'SOME_HASH',
        ];
        $this->dummyBlock['block_id'] = $block->generateId('', $date, $height);
    }

    protected function tearDown(): void
    {

    }

    public function testBlankScriptLoading(): void
    {
        $script = new Script([]);
        $result = $script->loadScript('');
        $this->assertTrue($result);
    }

    public function testScriptLoading(): void
    {
        $program = 'mov ax,10;';

        $script = new Script([]);
        $result = $script->loadScript($program);
        $this->assertTrue($result);
    }

    public function testMov(): void
    {
        $program = 'mov ax,10;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('10', $sm->getRegister('ax'));
    }

    public function testAssignAllRegisters(): void
    {
        $program = 'mov ax,1;';
        $program .= 'mov bx,2;';
        $program .= 'mov cx,3;';
        $program .= 'mov dx,4;';
        $program .= 'mov ex,5;';
        $program .= 'mov sx,6;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('1', $sm->getRegister('ax'));
        $this->assertEquals('2', $sm->getRegister('bx'));
        $this->assertEquals('3', $sm->getRegister('cx'));
        $this->assertEquals(-2, $sm->getRegister('dx'));
        $this->assertFalse($sm->getRegister('ex'));
        $this->assertFalse($sm->getRegister('sx'));
    }

    public function testPushAndOverPop(): void
    {
        $program = 'mov ax,test;';
        $program .= 'push ax;';
        $program .= 'pop bx;';
        $program .= 'mov cx,1;';
        $program .= 'pop cx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('test', $sm->getRegister('ax'));
        $this->assertEquals('test', $sm->getRegister('bx'));
        $this->assertEquals('', $sm->getRegister('cx'));
    }

    public function testPushAndPopWithoutRegister(): void
    {
        $program = 'push another test;';
        $program .= 'pop bx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('another test', $sm->getRegister('bx'));
    }

    public function testClr(): void
    {
        $program = 'mov ax,A;';
        $program .= 'mov bx,B;';
        $program .= 'mov ax,B;';
        $program .= 'mov bx,A;';
        $program .= 'clr ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('', $sm->getRegister('ax'));
        $this->assertEquals('A', $sm->getRegister('bx'));
    }

    public function testStringCompareLt(): void
    {
        $program = 'mov ax,A;';
        $program .= 'mov bx,B;';
        $program .= 'cmp ax,bx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('A', $sm->getRegister('ax'));
        $this->assertEquals('B', $sm->getRegister('bx'));
        $this->assertEquals(-1, $sm->getRegister('dx'));
    }

    public function testStringCompareGt(): void
    {
        $program = 'mov ax,B;';
        $program .= 'mov bx,A;';
        $program .= 'cmp ax,bx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('B', $sm->getRegister('ax'));
        $this->assertEquals('A', $sm->getRegister('bx'));
        $this->assertEquals(1, $sm->getRegister('dx'));
    }

    public function testStringCompareEq(): void
    {
        $program = 'mov ax,A;';
        $program .= 'mov bx,A;';
        $program .= 'cmp ax,bx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('A', $sm->getRegister('ax'));
        $this->assertEquals('A', $sm->getRegister('bx'));
        $this->assertEquals(0, $sm->getRegister('dx'));
    }

    public function testIntCompareLt(): void
    {
        $program = 'mov ax,1;';
        $program .= 'mov bx,2;';
        $program .= 'cmp ax,bx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('1', $sm->getRegister('ax'));
        $this->assertEquals('2', $sm->getRegister('bx'));
        $this->assertEquals(-1, $sm->getRegister('dx'));
    }

    public function testIntCompareGt(): void
    {
        $program = 'mov ax,2;';
        $program .= 'mov bx,1;';
        $program .= 'cmp ax,bx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('2', $sm->getRegister('ax'));
        $this->assertEquals('1', $sm->getRegister('bx'));
        $this->assertEquals(1, $sm->getRegister('dx'));
    }

    public function testIntCompareEq(): void
    {
        $program = 'mov ax,2;';
        $program .= 'mov bx,2;';
        $program .= 'cmp ax,bx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('2', $sm->getRegister('ax'));
        $this->assertEquals('2', $sm->getRegister('bx'));
        $this->assertEquals(0, $sm->getRegister('dx'));
    }

    public function testIntCompareWithOneValue(): void
    {
        $program = 'mov ax,2;';
        $program .= 'cmp ax,bx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('2', $sm->getRegister('ax'));
        $this->assertEquals(1, $sm->getRegister('dx')); // treated as a string
    }

    public function testAdd(): void
    {
        $program = 'mov ax,100000000000000000000000000;';
        $program .= 'mov bx,1;';
        $program .= 'add ax,2;';
        $program .= 'cmp ax,bx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('100000000000000000000000002', $sm->getRegister('ax'));
        $this->assertEquals(1, $sm->getRegister('dx'));
    }

    public function testPrecision(): void
    {
        $program = 'prec 3;';
        $program .= 'mov ax,10.34;';
        $program .= 'add ax,0.16;';
        $program .= 'cmp ax,bx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('10.500', $sm->getRegister('ax'));
    }

    public function testPrecisionWithoutPrecision(): void
    {
        $program = 'prec 0;';
        $program .= 'mov ax,10.34;';
        $program .= 'add ax,1.16;';
        $program .= 'cmp ax,bx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('11', $sm->getRegister('ax'));
    }

    public function testSub(): void
    {
        $program = 'mov ax,10;';
        $program .= 'sub ax,3;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('7', $sm->getRegister('ax'));
    }

    public function testSubNegative(): void
    {
        $program = 'mov ax,10;';
        $program .= 'sub ax,-3;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('13', $sm->getRegister('ax'));
    }

    public function testMul(): void
    {
        $program = 'mov ax,10;';
        $program .= 'mul ax,3;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('30', $sm->getRegister('ax'));
    }

    public function testDiv(): void
    {
        $program = 'mov ax,10;';
        $program .= 'div ax,3;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('3', $sm->getRegister('ax'));
    }

    public function testDivByZero(): void
    {
        $program = 'mov ax,10;';
        $program .= 'div ax,0;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('0', $sm->getRegister('ax'));
    }

    public function testInc(): void
    {
        $program = 'inc ax;';
        $program .= 'inc ax;';
        $program .= 'inc ax;';
        $program .= 'inc ax;';
        $program .= 'inc ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('5', $sm->getRegister('ax'));
    }

    public function testDec(): void
    {
        $program = 'dec ax;';
        $program .= 'dec ax;';
        $program .= 'dec ax;';
        $program .= 'dec ax;';
        $program .= 'dec ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('-5', $sm->getRegister('ax'));
    }

    public function testAbs(): void
    {
        $program = 'dec ax;';
        $program .= 'dec ax;';
        $program .= 'dec ax;';
        $program .= 'dec ax;';
        $program .= 'dec ax;';
        $program .= 'abs ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('5', $sm->getRegister('ax'));
    }

    public function testMin(): void
    {
        $program = 'mov ax,10;';
        $program .= 'mov bx, 5;';
        $program .= 'min ax, bx;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);


        $sm = $script->debugGetStateMachine();
        $this->assertEquals('5', $sm->getRegister('ax'));
    }

    public function testMinToStack(): void
    {
        $program = 'min 10, 5;';
        $program .= 'pop ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('5', $sm->getRegister('ax'));
    }

    public function testMax(): void
    {
        $program = 'mov ax, 5;';
        $program .= 'max ax, 10;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('10', $sm->getRegister('ax'));
    }

    public function testNeg(): void
    {
        $program = 'mov ax, 5;';
        $program .= 'neg ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('-5', $sm->getRegister('ax'));
    }

    public function testNegANegative(): void
    {
        $program = 'mov ax, -5;';
        $program .= 'neg ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('-5', $sm->getRegister('ax'));
    }

    public function testNot0to1(): void
    {
        $program = 'mov ax, 0;';
        $program .= 'not ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('1', $sm->getRegister('ax'));
    }

    public function testNot5to0(): void
    {
        $program = 'mov ax, 5;';
        $program .= 'not ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('0', $sm->getRegister('ax'));
    }

    public function testBadScript(): void
    {
        $program = 'this is a test script that will catch on fire;';

        $script = new Script([]);
        $script->loadScript($program);
        $result = $script->run(false);
        $this->assertEquals(false, $result);
    }

    public function testNop(): void
    {
        $program = 'nop;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('', $sm->getRegister('ax'));
    }

    public function testBase64Encode(): void
    {
        $program = 'mov ax,this should be base64!;';
        $program .= 'b64e ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('dGhpcyBzaG91bGQgYmUgYmFzZTY0IQ==', $sm->getRegister('ax'));
    }

    public function testBase64Decode(): void
    {
        $program = 'mov ax,dGhpcyBzaG91bGQgYmUgYmFzZTY0IQ==;';
        $program .= 'b64d ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('this should be base64!', $sm->getRegister('ax'));
    }

    public function testBase58Encode(): void
    {
        $program = 'mov ax,this should work;';
        $program .= 'b58e ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('FNiwznCj81uoBubBgMBUTC', $sm->getRegister('ax'));
    }

    public function testBase58Decode(): void
    {
        $program = 'mov ax,FNiwznCj81uoBubBgMBUTC;';
        $program .= 'b58d ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('this should work', $sm->getRegister('ax'));
    }

    public function testHexEncode(): void
    {
        $program = 'mov ax,This should end up being hex;';
        $program .= 'hexe ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('546869732073686f756c6420656e64207570206265696e6720686578', $sm->getRegister('ax'));
    }

    public function testHexDecode(): void
    {
        $program = 'mov ax,546869732073686f756c6420656e64207570206265696e6720686578;';
        $program .= 'hexd ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('This should end up being hex', $sm->getRegister('ax'));
    }

    public function testripemd160(): void
    {
        $program = 'mov ax,This should hash nicely;';
        $program .= 'r160 ax;';
        $program .= 'hexe ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('2b8f190c1835310f81a2ca72d71d7b1706775162', $sm->getRegister('ax'));
    }

    public function testSha256(): void
    {
        $program = 'mov ax,This should hash nicely;';
        $program .= 's256 ax;';
        $program .= 'hexe ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('c5559105264fe44c9898ff4d0132ca348737d121834a5b7a4947698352e6eeb0', $sm->getRegister('ax'));
    }

    public function testCreateAddressFromPublicKey(): void
    {
        $program = 'mov ax,MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAufnOzEGWNt3F8jVB6iH1MWtGSA7J+tczrf0fsA84WUBqtOwzafHVSc6UAylOyHzHXgD5hlOIMThNKDwYz6tGqv486MVuxyj40iZ+9d0hACpJJ1uA4S8PYV4vl5yszBu8zo3ue481b/tKbSqdp4UTmuuWhrGl0xK/erZF6rW634OwUCD/hV9e061hRo/844cAudLfPyFZT02SkrNTaEfdmRiQhZolj3PgbD+Pq5lN54sK7xjUA1NuFzoZdGRjQ6UX/MHAGXsHanEvndR1wS9CZMoZXBHJ4aGD7GH0sYuzYAGcc9ZwHOFYAZ6YGm8nI71fmjHiy0hkW8z1s4m11A+tawIDAQAB;';
        $program .= 'adpk ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('Bc14BDGV11UKCHhjSQcHc4cfSky1dfkWPfnD', $sm->getRegister('ax'));
    }

    public function testVerificationOfAddressFromPublicKey(): void
    {
        $block = $this->dummyBlock;

        $program = 'mov ax,MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAufnOzEGWNt3F8jVB6iH1MWtGSA7J+tczrf0fsA84WUBqtOwzafHVSc6UAylOyHzHXgD5hlOIMThNKDwYz6tGqv486MVuxyj40iZ+9d0hACpJJ1uA4S8PYV4vl5yszBu8zo3ue481b/tKbSqdp4UTmuuWhrGl0xK/erZF6rW634OwUCD/hV9e061hRo/844cAudLfPyFZT02SkrNTaEfdmRiQhZolj3PgbD+Pq5lN54sK7xjUA1NuFzoZdGRjQ6UX/MHAGXsHanEvndR1wS9CZMoZXBHJ4aGD7GH0sYuzYAGcc9ZwHOFYAZ6YGm8nI71fmjHiy0hkW8z1s4m11A+tawIDAQAB;';
        $program .= 'adpk ax;';
        $program .= 'mov bx,txoadr;';
        $program .= 'vadr bx,txoadr;';

        $script = new Script($block);
        $script->loadScript($program);

        // get state, ensure state is false
        $sm = $script->debugGetStateMachine();
        $this->assertEquals(false, $sm->getRegister('sx'));

        $script->run(false);

        // get state, ensure state is true
        $sm = $script->debugGetStateMachine();
        $this->assertEquals(true, $sm->getRegister('sx'));
    }

    public function testVerificationOfAddressFromPublicKeyWithWrongPublicKey(): void
    {
        $block = $this->dummyBlock;

        $program = 'mov ax,MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAowRVqZ8r6LYWw+TysgTDM6K7JXqH1ralIbeU6j+BZ14IeuISAzCezkDW5/dtGUjF+tjCK9wj9hVlk48z6p2J3ZVXTOMNXeE0xWIQnYU/4G8pSmf31V2sLk1Wu9+xDPf/r7U9YSfSnV9oL7tDnll/7bi1i9PD9rpjcOcByPp1rZ6cV4rl3nv6FpB16UW+ZvWvrVvYqtcs3A92XcCbBAVDlaO+bJHfOjv1oh8/+pYxdDF30fr2WDDxXY9cNy+Z7TDkRW1+9y1IWY7CDHLIeOLo+WfdX71KrmkIX/i/r87SISYwbmS3dql4EcQpxqwLw5umiwPFbHCcNe5p6hJkQd2D+QIDAQAB;';
        $program .= 'adpk ax;';
        $program .= 'mov bx,txoadr;';
        $program .= 'vadr ax,bx;';

        $script = new Script($block);
        $script->loadScript($program);

        // get state, ensure state is false
        $sm = $script->debugGetStateMachine();
        $this->assertEquals(false, $sm->getRegister('sx'));

        $script->run(false);

        // get state, ensure state is true
        $sm = $script->debugGetStateMachine();
        $this->assertEquals(false, $sm->getRegister('sx'));
    }

    public function testVerificationForceFail(): void
    {
        $program = 'vfal;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        // get state, ensure state is false
        $sm = $script->debugGetStateMachine();
        $this->assertEquals(false, $sm->getRegister('sx'));
    }

    public function testVerificationForceTrue(): void
    {
        $program = 'mov ax,1;';
        $program .= 'vtru;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        // get state, ensure state is false
        $sm = $script->debugGetStateMachine();
        $this->assertEquals(true, $sm->getRegister('sx'));
    }

    public function testEncodeDecodeScript(): void
    {
        $block = $this->dummyBlock;

        $program = 'mov ax,MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAowRVqZ8r6LYWw+TysgTDM6K7JXqH1ralIbeU6j+BZ14IeuISAzCezkDW5/dtGUjF+tjCK9wj9hVlk48z6p2J3ZVXTOMNXeE0xWIQnYU/4G8pSmf31V2sLk1Wu9+xDPf/r7U9YSfSnV9oL7tDnll/7bi1i9PD9rpjcOcByPp1rZ6cV4rl3nv6FpB16UW+ZvWvrVvYqtcs3A92XcCbBAVDlaO+bJHfOjv1oh8/+pYxdDF30fr2WDDxXY9cNy+Z7TDkRW1+9y1IWY7CDHLIeOLo+WfdX71KrmkIX/i/r87SISYwbmS3dql4EcQpxqwLw5umiwPFbHCcNe5p6hJkQd2D+QIDAQAB;';
        $program .= 'adpk ax;';
        $program .= 'mov bx,txoadr;';
        $program .= 'vadr ax,bx;';

        $script = new Script($block);
        $compressed = $script->encodeScript($program);
        $uncompressed = $script->decodeScript($compressed);
        $this->assertEquals($program, $uncompressed);
    }

    public function testCreateAddressFromPartial(): void
    {
        $program = 'mov bx,MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAowRVqZ8r6LYWw+TysgTDM6K7JXqH1ralIbeU6j+BZ14IeuISAzCezkDW5/dtGUjF+tjCK9wj9hVlk48z6p2J3ZVXTOMNXeE0xWIQnYU/4G8pSmf31V2sLk1Wu9+xDPf/r7U9YSfSnV9oL7tDnll/7bi1i9PD9rpjcOcByPp1rZ6cV4rl3nv6FpB16UW+ZvWvrVvYqtcs3A92XcCbBAVDlaO+bJHfOjv1oh8/+pYxdDF30fr2WDDxXY9cNy+Z7TDkRW1+9y1IWY7CDHLIeOLo+WfdX71KrmkIX/i/r87SISYwbmS3dql4EcQpxqwLw5umiwPFbHCcNe5p6hJkQd2D+QIDAQAB;';
        $program .= 'mov ax,5fb13528c685dcbe50e29f19630c73ae193816bd8585cfda34427d4250454055;';
        $program .= 'hexd ax;';
        $program .= 'adha ax;';
        $program .= 'adpa bx;';
        $program .= 'hexe bx;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('Bc1KeciJDMgd9f3qASsnspspiq7ao7W9Kyet', $sm->getRegister('ax'));
        $this->assertEquals('5fb13528c685dcbe50e29f19630c73ae193816bd8585cfda34427d4250454055', $sm->getRegister('bx'));
    }

    public function testPayToPublicKeyHash(): void
    {
        $program = 'mov ax,5fb13528c685dcbe50e29f19630c73ae193816bd8585cfda34427d4250454055;';
        $program .= 'mov bx,ax;';
        $program .= 'hexd ax;';
        $program .= 'adha ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('Bc1KeciJDMgd9f3qASsnspspiq7ao7W9Kyet', $sm->getRegister('ax'));
        $this->assertEquals('5fb13528c685dcbe50e29f19630c73ae193816bd8585cfda34427d4250454055', $sm->getRegister('bx'));
    }

    public function testHexAMessage(): void
    {
        $program = 'mov ax,The Year of Inflation Infamy - The New York Times 16/Dec/2021;';
        $program .= 'hexe ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('5468652059656172206f6620496e666c6174696f6e20496e66616d79202d20546865204e657720596f726b2054696d65732031362f4465632f32303231', $sm->getRegister('ax'));
    }
}

// https://reference.cash/protocol/blockchain/transaction/locking-script

/**
 *  --------------------------------------------------------------------------------------------------------------
 *  scriptSig (part 1 - script to claim an unspent transaction)
 *  -> push [signature];push [publicKey];dup;
 *  --------------------------------------------------------------------------------------------------------------
 *  push [signature];   // push the sig to the stack
 *  push [publicKey];   // put the public key in a register
 *  dup;                // duplicate the public key to the stack
 *
 *  --------------------------------------------------------------------------------------------------------------
 *  Pay to Public Key Hash - use this instead - doesn't expose public key (part 2 - script to claim this unspent output)
 *  -> mov ax,<sha256>;adha ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;vsig ax,<hash>,bx;
 *  --------------------------------------------------------------------------------------------------------------
 *  mov ax,<sha256>;    // move the hashed public key into a register
 *  adha ax;            // convert the hash to an address
 *  pop bx;             // pop the first public key
 *  adpk bx;            // convert the public key to an address
 *  vadr ax,bx;         // compare the address to the scriptAddress
 *  pop ax;             // move the second public key into a register
 *  pop bx;             // move the signature into a register
 *  vsig ax,<hash>,bx;  // verify that pubkey<ax> signed <bx> to produce <cx>
 *  --------------------------------------------------------------------------------------------------------------
 */


/**
 * Pay to Public Key - Shouldn't use - exposes public key
 *  mov ax,<public_key>;
 *  adpk ax;
 *  mov bx,txoadr;
 *  vadr bx,txoadr;
 */

/**
 * Pay to Public Key Hash - use this instead - doesn't expose public key
 *  mov ax,<sha256>;
 *  hexd ax;
 *  adha ax; // hash to address
 *  mov bx,txoadr;
 *  vadr bx,txoadr;
 */
