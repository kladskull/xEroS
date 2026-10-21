<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;

class PowTest extends TestCase
{
    private Pow $pow;

    protected function setUp(): void
    {
        $this->pow = new Pow();
    }

    public function testDoubleSha256(): void
    {
        $this->assertEquals(hash('sha256', hash('sha256', 'abc', true), true), $this->pow->doubleSha256('abc'));
        $this->assertEquals($this->pow->doubleSha256('header' . 'ff'), $this->pow->calculate('header', 'ff'));
    }

    public function testVerifyPow(): void
    {
        $hash = bin2hex($this->pow->calculate('header', '1a'));

        $this->assertTrue($this->pow->verifyPow($hash, 'header', '1a'));
        $this->assertFalse($this->pow->verifyPow($hash, 'header', '1b'));
        $this->assertFalse($this->pow->verifyPow($hash, 'other', '1a'));
    }

    public function testLeadingZeroBits(): void
    {
        $this->assertEquals(0, $this->pow->leadingZeroBits('f' . str_repeat('0', 63)));
        $this->assertEquals(1, $this->pow->leadingZeroBits('7' . str_repeat('f', 63)));
        $this->assertEquals(4, $this->pow->leadingZeroBits('0f' . str_repeat('f', 62)));
        $this->assertEquals(9, $this->pow->leadingZeroBits('007' . str_repeat('f', 61)));
        $this->assertEquals(256, $this->pow->leadingZeroBits(str_repeat('0', 64)));
    }

    public function testMeetsDifficulty(): void
    {
        $hash = '000f' . str_repeat('f', 60);

        $this->assertTrue($this->pow->meetsDifficulty($hash, 12));
        $this->assertFalse($this->pow->meetsDifficulty($hash, 13));
        $this->assertTrue($this->pow->meetsDifficulty($hash, 0));
    }

    public function testMalformedHashNeverMeetsDifficulty(): void
    {
        $this->assertFalse($this->pow->meetsDifficulty('', 0));
        $this->assertFalse($this->pow->meetsDifficulty(str_repeat('0', 63), 1));
        $this->assertFalse($this->pow->meetsDifficulty(str_repeat('0', 65), 1));
        $this->assertFalse($this->pow->meetsDifficulty(str_repeat('z', 64), 1));
    }

    public function testBlockWorkDoublesPerBit(): void
    {
        $this->assertEquals('65536', $this->pow->blockWork(16));
        $this->assertEquals(bcmul($this->pow->blockWork(20), '2'), $this->pow->blockWork(21));
    }
}
