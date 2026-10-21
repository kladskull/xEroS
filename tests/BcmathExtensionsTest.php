<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;

class BcmathExtensionsTest extends TestCase
{
    public function testAbs(): void
    {
        $this->assertEquals('5', BcmathExtensions::bcabs('-5'));
        $this->assertEquals('5', BcmathExtensions::bcabs('5'));
        $this->assertEquals('0', BcmathExtensions::bcabs('0'));
        $this->assertEquals('123456789012345678901234567890', BcmathExtensions::bcabs('-123456789012345678901234567890'));
    }

    public function testHexToDecimal(): void
    {
        $this->assertEquals('0', BcmathExtensions::bchexdec('0'));
        $this->assertEquals('255', BcmathExtensions::bchexdec('ff'));
        $this->assertEquals('255', BcmathExtensions::bchexdec('FF'));
        $this->assertEquals('18446744073709551616', BcmathExtensions::bchexdec('10000000000000000'));
        $this->assertEquals(
            '115792089237316195423570985008687907853269984665640564039457584007913129639935',
            BcmathExtensions::bchexdec(str_repeat('f', 64))
        );
    }
}
