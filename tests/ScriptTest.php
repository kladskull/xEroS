<?php declare(strict_types=1);

namespace Xeros;

use PHPUnit\Framework\TestCase;
use Xeros\Script;
use Xeros\TransactionVersion;

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
                    'version' => TransactionVersion::Coinbase,
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
                            'version' => TransactionVersion::Coinbase,
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
        $program = 'mov 10,ax;';

        $script = new Script([]);
        $result = $script->loadScript($program);
        $this->assertTrue($result);
    }

    public function testMov(): void
    {
        $program = 'mov 10,ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('10', $sm->getRegister('ax'));
    }

    public function testAssignAllRegisters(): void
    {
        $program = 'mov 1,ax;';
        $program .= 'mov 2,bx;';
        $program .= 'mov 3,cx;';
        $program .= 'mov 4,dx;';
        $program .= 'mov 5,ex;';
        $program .= 'mov 6,sx;';

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
        $program = 'mov test,ax;';
        $program .= 'push ax;';
        $program .= 'pop bx;';
        $program .= 'mov 1,cx;';
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
        $program = 'mov A,ax;';
        $program .= 'mov B,bx;';
        $program .= 'mov B,ax;';
        $program .= 'mov A,bx;';
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
        $program = 'mov A,ax;';
        $program .= 'mov B,bx;';
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
        $program = 'mov B,ax;';
        $program .= 'mov A,bx;';
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
        $program = 'mov A,ax;';
        $program .= 'mov B,bx;';
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
        $program = 'mov 1,ax;';
        $program .= 'mov 2,bx;';
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
        $program = 'mov 2,ax;';
        $program .= 'mov 1,bx;';
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
        $program = 'mov 2,ax;';
        $program .= 'mov 2,bx;';
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
        $program = 'mov 2,ax;';
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
        $program = 'mov 100000000000000000000000000,ax;';
        $program .= 'mov 1,bx;';
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
        $program .= 'mov 10.34,ax;';
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
        $program .= 'mov 10.34,ax;';
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
        $program = 'mov 10,ax;';
        $program .= 'sub ax,3;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('7', $sm->getRegister('ax'));
    }

    public function testSubNegative(): void
    {
        $program = 'mov 10,ax;';
        $program .= 'sub ax,-3;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('13', $sm->getRegister('ax'));
    }

    public function testMul(): void
    {
        $program = 'mov 10,ax;';
        $program .= 'mul ax,3;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('30', $sm->getRegister('ax'));
    }

    public function testDiv(): void
    {
        $program = 'mov 10,ax;';
        $program .= 'div ax,3;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('3', $sm->getRegister('ax'));
    }

    public function testDivByZero(): void
    {
        $program = 'mov 10,ax;';
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
        $program = 'mov 10,ax;';
        $program .= 'mov 5,bx;';
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
        $program = 'mov 5,ax;';
        $program .= 'max ax, 10;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('10', $sm->getRegister('ax'));
    }

    public function testNeg(): void
    {
        $program = 'mov 5,ax;';
        $program .= 'neg ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('-5', $sm->getRegister('ax'));
    }

    public function testNegANegative(): void
    {
        $program = 'mov -5,ax;';
        $program .= 'neg ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('-5', $sm->getRegister('ax'));
    }

    public function testNot0to1(): void
    {
        $program = 'mov 0,ax;';
        $program .= 'not ax;';

        $script = new Script([]);
        $script->loadScript($program);
        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('1', $sm->getRegister('ax'));
    }

    public function testNot5to0(): void
    {
        $program = 'mov 5,ax;';
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
        $program = 'mov this should be base64!,ax;';
        $program .= 'b64e ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('dGhpcyBzaG91bGQgYmUgYmFzZTY0IQ==', $sm->getRegister('ax'));
    }

    public function testBase64Decode(): void
    {
        $program = 'mov dGhpcyBzaG91bGQgYmUgYmFzZTY0IQ==,ax;';
        $program .= 'b64d ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('this should be base64!', $sm->getRegister('ax'));
    }

    public function testBase58Encode(): void
    {
        $program = 'mov this should work,ax;';
        $program .= 'b58e ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('FNiwznCj81uoBubBgMBUTC', $sm->getRegister('ax'));
    }

    public function testBase58Decode(): void
    {
        $program = 'mov FNiwznCj81uoBubBgMBUTC,ax;';
        $program .= 'b58d ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('this should work', $sm->getRegister('ax'));
    }

    public function testHexEncode(): void
    {
        $program = 'mov This should end up being hex,ax;';
        $program .= 'hexe ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('546869732073686f756c6420656e64207570206265696e6720686578', $sm->getRegister('ax'));
    }

    public function testHexDecode(): void
    {
        $program = 'mov 546869732073686f756c6420656e64207570206265696e6720686578,ax;';
        $program .= 'hexd ax;';

        $script = new Script([]);
        $script->loadScript($program);

        $script->run(false);

        $sm = $script->debugGetStateMachine();
        $this->assertEquals('This should end up being hex', $sm->getRegister('ax'));
    }

    public function testripemd160(): void
    {
        $program = 'mov This should hash nicely,ax;';
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
        $program = 'mov This should hash nicely,ax;';
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
        $program = 'mov MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAufnOzEGWNt3F8jVB6iH1MWtGSA7J+tczrf0fsA84WUBqtOwzafHVSc6UAylOyHzHXgD5hlOIMThNKDwYz6tGqv486MVuxyj40iZ+9d0hACpJJ1uA4S8PYV4vl5yszBu8zo3ue481b/tKbSqdp4UTmuuWhrGl0xK/erZF6rW634OwUCD/hV9e061hRo/844cAudLfPyFZT02SkrNTaEfdmRiQhZolj3PgbD+Pq5lN54sK7xjUA1NuFzoZdGRjQ6UX/MHAGXsHanEvndR1wS9CZMoZXBHJ4aGD7GH0sYuzYAGcc9ZwHOFYAZ6YGm8nI71fmjHiy0hkW8z1s4m11A+tawIDAQAB,ax;';
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

        $program = 'mov MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAufnOzEGWNt3F8jVB6iH1MWtGSA7J+tczrf0fsA84WUBqtOwzafHVSc6UAylOyHzHXgD5hlOIMThNKDwYz6tGqv486MVuxyj40iZ+9d0hACpJJ1uA4S8PYV4vl5yszBu8zo3ue481b/tKbSqdp4UTmuuWhrGl0xK/erZF6rW634OwUCD/hV9e061hRo/844cAudLfPyFZT02SkrNTaEfdmRiQhZolj3PgbD+Pq5lN54sK7xjUA1NuFzoZdGRjQ6UX/MHAGXsHanEvndR1wS9CZMoZXBHJ4aGD7GH0sYuzYAGcc9ZwHOFYAZ6YGm8nI71fmjHiy0hkW8z1s4m11A+tawIDAQAB,ax;';
        $program .= 'adpk ax;';
        $program .= 'mov txoadr,bx;';
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

        $program = 'mov MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAowRVqZ8r6LYWw+TysgTDM6K7JXqH1ralIbeU6j+BZ14IeuISAzCezkDW5/dtGUjF+tjCK9wj9hVlk48z6p2J3ZVXTOMNXeE0xWIQnYU/4G8pSmf31V2sLk1Wu9+xDPf/r7U9YSfSnV9oL7tDnll/7bi1i9PD9rpjcOcByPp1rZ6cV4rl3nv6FpB16UW+ZvWvrVvYqtcs3A92XcCbBAVDlaO+bJHfOjv1oh8/+pYxdDF30fr2WDDxXY9cNy+Z7TDkRW1+9y1IWY7CDHLIeOLo+WfdX71KrmkIX/i/r87SISYwbmS3dql4EcQpxqwLw5umiwPFbHCcNe5p6hJkQd2D+QIDAQAB,ax;';
        $program .= 'adpk ax;';
        $program .= 'mov txoadr,bx;';
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
        $program = 'mov 1,ax;';
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

        $program = 'mov MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAowRVqZ8r6LYWw+TysgTDM6K7JXqH1ralIbeU6j+BZ14IeuISAzCezkDW5/dtGUjF+tjCK9wj9hVlk48z6p2J3ZVXTOMNXeE0xWIQnYU/4G8pSmf31V2sLk1Wu9+xDPf/r7U9YSfSnV9oL7tDnll/7bi1i9PD9rpjcOcByPp1rZ6cV4rl3nv6FpB16UW+ZvWvrVvYqtcs3A92XcCbBAVDlaO+bJHfOjv1oh8/+pYxdDF30fr2WDDxXY9cNy+Z7TDkRW1+9y1IWY7CDHLIeOLo+WfdX71KrmkIX/i/r87SISYwbmS3dql4EcQpxqwLw5umiwPFbHCcNe5p6hJkQd2D+QIDAQAB,ax;';
        $program .= 'adpk ax;';
        $program .= 'mov txoadr,bx;';
        $program .= 'vadr ax,bx;';

        $script = new Script($block);
        $compressed = $script->encodeScript($program);
        $uncompressed = $script->decodeScript($compressed);
        $this->assertEquals($program, $uncompressed);
    }

    public function testCreateAddressFromPartial(): void
    {
        $program = 'mov MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAowRVqZ8r6LYWw+TysgTDM6K7JXqH1ralIbeU6j+BZ14IeuISAzCezkDW5/dtGUjF+tjCK9wj9hVlk48z6p2J3ZVXTOMNXeE0xWIQnYU/4G8pSmf31V2sLk1Wu9+xDPf/r7U9YSfSnV9oL7tDnll/7bi1i9PD9rpjcOcByPp1rZ6cV4rl3nv6FpB16UW+ZvWvrVvYqtcs3A92XcCbBAVDlaO+bJHfOjv1oh8/+pYxdDF30fr2WDDxXY9cNy+Z7TDkRW1+9y1IWY7CDHLIeOLo+WfdX71KrmkIX/i/r87SISYwbmS3dql4EcQpxqwLw5umiwPFbHCcNe5p6hJkQd2D+QIDAQAB,bx;';
        $program .= 'mov 5fb13528c685dcbe50e29f19630c73ae193816bd8585cfda34427d4250454055,ax;';
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
        $program = 'mov 5fb13528c685dcbe50e29f19630c73ae193816bd8585cfda34427d4250454055,ax;';
        $program .= 'mov ax,bx;';
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
        $program = 'mov The Year of Inflation Infamy - The New York Times 16/Dec/2021,ax;';
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
 *  push [publicKey];   // push the public key into the stack
 *  dup;                // duplicate the public key to the stack
 *
 *  --------------------------------------------------------------------------------------------------------------
 *  Pay to Public Key Hash - use this instead - doesn't expose public key (part 2 - script to claim this unspent output)
 *  -> mov <sha256>,ax;adha ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;vsig ax,<hash>,bx;
 *  --------------------------------------------------------------------------------------------------------------
 *  mov <sha256>,ax;    // move the hashed public key into a register
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
 *  mov <public_key>,ax;
 *  adpk ax;
 *  mov txoadr,bx;
 *  vadr bx,txoadr;
 */

/**
 * Pay to Public Key Hash - use this instead - doesn't expose public key
 *  mov <sha256>,ax;
 *  hexd ax;
 *  adha ax; // hash to address
 *  mov txoadr,bx;
 *  vadr bx,txoadr;
 */
