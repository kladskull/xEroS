<?php declare(strict_types=1);

namespace Blockchain;

use JetBrains\PhpStorm\NoReturn;
use function base64_decode;
use function base64_encode;
use function bcadd;
use function bccomp;
use function bcdiv;
use function bcmul;
use function bcsub;
use function ctype_digit;
use function hash;
use function strtolower;
use function usleep;

class ScriptFunctions
{
    private TransferEncoding $transferEncoding;

    // [instruction] currently not used, but here for future improvements
    public array $systemConstants = [
        'false' => ['instruction' => 0],
        'true' => ['instruction' => 1],
        'time' => ['instruction' => 3],
    ];

    public array $systemRegisters = [
        'ax' => ['instruction' => 30],
        'bx' => ['instruction' => 31],
        'cx' => ['instruction' => 32],
        'dx' => ['instruction' => 33],
        'ex' => ['instruction' => 34],
        'sx' => ['instruction' => 35],
    ];

    public array $functions = [
        // comparison (register work)
        'cmp' => ['instruction' => 50, 'required_params' => 2],

        // arithmetic/numeric (register work)
        'abs' => ['instruction' => 60, 'required_params' => 1],
        'add' => ['instruction' => 61, 'required_params' => 2],
        'dec' => ['instruction' => 62, 'required_params' => 1],
        'div' => ['instruction' => 63, 'required_params' => 2],
        'inc' => ['instruction' => 64, 'required_params' => 1],
        'mul' => ['instruction' => 65, 'required_params' => 2],
        'min' => ['instruction' => 66, 'required_params' => 2],
        'max' => ['instruction' => 67, 'required_params' => 2],
        'prec' => ['instruction' => 68, 'required_params' => 1],
        'neg' => ['instruction' => 69, 'required_params' => 1],
        'not' => ['instruction' => 70, 'required_params' => 1],
        'sub' => ['instruction' => 71, 'required_params' => 2],

        // data transfer (register/stack work)
        'clr' => ['instruction' => 90, 'required_params' => 1],
        'mov' => ['instruction' => 91, 'required_params' => 2],
        'nop' => ['instruction' => 92, 'required_params' => 0],
        'rem' => ['instruction' => 93, 'required_params' => 1],
        'pop' => ['instruction' => 94, 'required_params' => 1],
        'push' => ['instruction' => 95, 'required_params' => 1],
        'dup' => ['instruction' => 96, 'required_params' => 0],

        // crypto (register work)
        'adpk' => ['instruction' => 110, 'required_params' => 1],
        'adha' => ['instruction' => 111, 'required_params' => 1],
        'adpa' => ['instruction' => 112, 'required_params' => 1],
        'r160' => ['instruction' => 113, 'required_params' => 1],
        's256' => ['instruction' => 114, 'required_params' => 1],

        // verification (program flow/result) any false value exits and doesn't execute any further
        'vadr' => ['instruction' => 130, 'required_params' => 2], // compare add (trans accepted/denied)
        'vsig' => ['instruction' => 131, 'required_params' => 3], // compare add (trans accepted/denied)
        'vfal' => ['instruction' => 132, 'required_params' => 0], // force transaction to fail
        'vtru' => ['instruction' => 133, 'required_params' => 0], // force transaction to pass

        // encoding (register work)
        'b58e' => ['instruction' => 150, 'required_params' => 1],
        'b58d' => ['instruction' => 151, 'required_params' => 1],
        'b64e' => ['instruction' => 152, 'required_params' => 1],
        'b64d' => ['instruction' => 153, 'required_params' => 1],
        'hexe' => ['instruction' => 154, 'required_params' => 1],
        'hexd' => ['instruction' => 155, 'required_params' => 1],

        // navigation (to be done!)
        /*'lbl'  => ['instruction' => 170, 'required_params' => 1], // label X:
        'jmp'  => ['instruction' => 170, 'required_params' => 2], // jmp to a label
        'jz'  => ['instruction' => 172, 'required_params' => 2],  // jump if == 0 is zero
        'jnz'  => ['instruction' => 172, 'required_params' => 2],  // jump if != 0  is not zero
        'jl'  => ['instruction' => 172, 'required_params' => 2],  // jump if < less than
        'jle'  => ['instruction' => 172, 'required_params' => 2],  // jump if <= x  is less than or equal
        'jg'  => ['instruction' => 172, 'required_params' => 2],  // jump if [decimal] is less than or equal
        'jge'  => ['instruction' => 172, 'required_params' => 2],  // jump if [decimal] is less than or equal
        'je'  => ['instruction' => 172, 'required_params' => 2],  // jump if cmp is equal
        'jne'  => ['instruction' => 172, 'required_params' => 2],  // jump if cmp is NOT equal*/


    ];

    private Address $address;
    private OpenSsl $openssl;

    public function __construct()
    {
        $this->transferEncoding = new TransferEncoding();
        $this->address = new Address();
        $this->openssl = new OpenSsl();
    }

    /********************************************************************************
     *
     *    COMPARISON
     *
     ********************************************************************************/

    // -1 (l < r), 0 (l = r), 1 (l > r)
    #[NoReturn]
    public function cmp(StateMachine $stateMachine, string|bool|int $leftValue, string|bool|int $rightValue): void
    {
        $leftValue = $stateMachine->getRegister($leftValue);
        $rightValue = $stateMachine->getRegister($rightValue);

        // straight digits or formatted hex
        if (ctype_digit($leftValue) && ctype_digit($rightValue)) {
            $result = bccomp($leftValue, $rightValue, $stateMachine->getPrecision());
        } elseif ($leftValue < $rightValue) {
            $result = -1;
        } elseif ($leftValue > $rightValue) {
            $result = 1;
        } else {
            $result = 0;
        }

        $stateMachine->setRegister('dx', $result, true);
    }

    /********************************************************************************
     *
     *    ARITHMETIC
     *
     ********************************************************************************/

    // make the location positive
    #[NoReturn]
    public function abs(StateMachine $stateMachine, string $location): void
    {
        $value = $stateMachine->getRegister($location);

        if (bccomp($value, "0", $stateMachine->getPrecision()) < 0) {
            $stateMachine->setRegister(
                $location,
                bcmul($stateMachine->getRegister($location), "-1", $stateMachine->getPrecision())
            );
        }
    }

    // add number with the location
    #[NoReturn]
    public function add(StateMachine $stateMachine, string $location, string $number): void
    {
        $stateMachine->setRegister(
            $location,
            bcadd($stateMachine->getRegister($location), $number, $stateMachine->getPrecision())
        );
    }

    // decrement the location
    #[NoReturn]
    public function dec(StateMachine $stateMachine, string $location): void
    {
        $stateMachine->setRegister(
            $location,
            bcsub($stateMachine->getRegister($location), "1", $stateMachine->getPrecision())
        );
    }

    // divide [register value] with the number
    #[NoReturn]
    public function div(StateMachine $stateMachine, string $location, string $number): void
    {
        $registerVal = $stateMachine->getRegister($location);

        if (bccomp($number, "0", $stateMachine->getPrecision()) !== 0 &&
            bccomp($registerVal, "0", $stateMachine->getPrecision()) !== 0) {
            $value = bcdiv($registerVal, $number, $stateMachine->getPrecision());
        } else {
            $value = 0;
        }

        $stateMachine->setRegister($location, $value);
    }

    // increment the location
    #[NoReturn]
    public function inc(StateMachine $stateMachine, string $location): void
    {
        $stateMachine->setRegister(
            $location,
            bcadd($stateMachine->getRegister($location), "1", $stateMachine->getPrecision())
        );
    }

    // sets location to the greater number
    #[NoReturn]
    public function max(StateMachine $stateMachine, string|bool|int $left, string|bool|int $right): void
    {
        $location = $left;
        $left = $stateMachine->getRegister($left);
        $right = $stateMachine->getRegister($right);

        if (bccomp($left, $right, $stateMachine->getPrecision()) >= 0) {
            return;
        }

        $stateMachine->setRegister($location, $right);
    }

    // sets location to the smaller number
    #[NoReturn]
    public function min(StateMachine $stateMachine, string|bool|int $left, string|bool|int $right): void
    {
        $location = $left;
        $left = $stateMachine->getRegister($left);
        $right = $stateMachine->getRegister($right);

        if (bccomp($left, $right, $stateMachine->getPrecision()) <= 0) {
            return;
        }

        $stateMachine->setRegister($location, $right);
    }

    // multiply number with the location
    #[NoReturn]
    public function mul(StateMachine $stateMachine, string $location, string $number): void
    {
        $stateMachine->setRegister(
            $location,
            bcmul($stateMachine->getRegister($location), $number, $stateMachine->getPrecision())
        );
    }

    // negate the location (flip the sign)
    #[NoReturn]
    public function neg(StateMachine $stateMachine, string $location): void
    {
        if (bccomp($stateMachine->getRegister($location), '0') >= 0) {
            $stateMachine->setRegister(
                $location,
                bcmul($stateMachine->getRegister($location), "-1", $stateMachine->getPrecision())
            );
        }
    }

    // no-op
    #[NoReturn]
    public function nop(StateMachine $stateMachine): void
    {
        usleep(0);
    }

    // remark
    #[NoReturn]
    public function rem(StateMachine $stateMachine, string $location): void
    {}

    // if 0 or 1, flip, otherwise make 0
    #[NoReturn]
    public function not(StateMachine $stateMachine, string $location): void
    {
        $value = (string)$stateMachine->getRegister($location);

        if (bccomp($value, '0', $stateMachine->getPrecision()) === 0) {
            $value = "1";
        } elseif (bccomp($value, '1', $stateMachine->getPrecision()) === 0) {
            $value = "0";
        } else {
            $value = "0";
        }

        $stateMachine->setRegister($location, $value);
    }

    // set precision for the script
    #[NoReturn]
    public function prec(StateMachine $stateMachine, string $precision): void
    {
        $stateMachine->setPrecision((int)$precision);
    }

    // subtract number with the location
    #[NoReturn]
    public function sub(StateMachine $stateMachine, string $location, string $number): void
    {
        $stateMachine->setRegister(
            $location,
            bcsub($stateMachine->getRegister($location), $number, $stateMachine->getPrecision())
        );
    }

    /********************************************************************************
     *
     *    DATA TRANSFER
     *
     ********************************************************************************/
    #[NoReturn]
    public function clr(StateMachine $stateMachine, string $source): void
    {
        if ($source !== 'sx' && $stateMachine->isRegister($source)) {
            if ($source === 'ex') {
                $stateMachine->setRegister($source, false);
            } elseif ($source === 'dx') {
                $stateMachine->setRegister($source, -2);
            } else {
                $stateMachine->setRegister($source, '');
            }
        }
    }

    #[NoReturn]
    public function mov(StateMachine $stateMachine, string|bool|int $source, string $destination): void
    {
        if ($stateMachine->isRegister($source)) {
            $source = $stateMachine->getRegister($source);
        }

        $stateMachine->setRegister($destination, $source);
    }

    #[NoReturn]
    public function pop(StateMachine $stateMachine, string $source): void
    {
        $value = $stateMachine->popStack();
        $stateMachine->setRegister($source, $value);
    }

    #[NoReturn]
    public function push(StateMachine $stateMachine, string|bool|int $source): void
    {
        if ($stateMachine->isRegister($source)) {
            $source = $stateMachine->getRegister($source);
        }

        $stateMachine->pushStack($source);
    }

    #[NoReturn]
    public function dup(StateMachine $stateMachine): void
    {
        $source = $stateMachine->popStack();
        $stateMachine->pushStack($source); // replace the original
        // push another copy on the stack
        $stateMachine->pushStack($source);
    }

    /********************************************************************************
     *
     *    CRYPTO
     *
     ********************************************************************************/
    #[NoReturn]
    public function adpk(StateMachine $stateMachine, string $location): void
    {
        $stateMachine->setRegister($location, $this->address->create($stateMachine->getRegister($location)));
    }

    #[NoReturn]
    public function adha(StateMachine $stateMachine, string $location): void
    {
        $stateMachine->setRegister(
            $location,
            $this->address->createAddressFromPartial(
                $this->transferEncoding->hexToBin($stateMachine->getRegister($location))
            )
        );
    }

    #[NoReturn]
    public function adpa(StateMachine $stateMachine, string $location): void
    {
        $stateMachine->setRegister($location, $this->address->createPartial($stateMachine->getRegister($location)));
    }

    #[NoReturn]
    public function r160(StateMachine $stateMachine, string $source): void
    {
        $stateMachine->setRegister($source, hash('ripemd160', $stateMachine->getRegister($source), true));
    }

    #[NoReturn]
    public function s256(StateMachine $stateMachine, string $source): void
    {
        $stateMachine->setRegister($source, hash('sha256', $stateMachine->getRegister($source), true));
    }

    /********************************************************************************
     *
     *    ENCODING
     *
     ********************************************************************************/

    // base58encode
    #[NoReturn]
    public function b58e(StateMachine $stateMachine, string $location): void
    {
        $stateMachine->setRegister(
            $location,
            $this->transferEncoding->binToBase58($stateMachine->getRegister($location))
        );
    }

    // base58decode
    #[NoReturn]
    public function b58d(StateMachine $stateMachine, string $location): void
    {
        $stateMachine->setRegister(
            $location,
            $this->transferEncoding->base58ToBin($stateMachine->getRegister($location))
        );
    }

    // base64encode
    #[NoReturn]
    public function b64e(StateMachine $stateMachine, string $location): void
    {
        $stateMachine->setRegister($location, base64_encode($stateMachine->getRegister($location)));
    }

    // base64decode
    #[NoReturn]
    public function b64d(StateMachine $stateMachine, string $location): void
    {
        $value = base64_decode($stateMachine->getRegister($location));

        if ($value === false) {
            $value = '';
        }

        $stateMachine->setRegister($location, $value);
    }

    // binary to hex
    #[NoReturn]
    public function hexe(StateMachine $stateMachine, string $location): void
    {
        $stateMachine->setRegister(
            $location,
            strtolower($this->transferEncoding->binToHex($stateMachine->getRegister($location)))
        );
    }

    // binary to hex
    #[NoReturn]
    public function hexd(StateMachine $stateMachine, string $location): void
    {
        $stateMachine->setRegister($location, $this->transferEncoding->hexToBin($stateMachine->getRegister($location)));
    }

    /********************************************************************************
     *
     *    VERIFICATION
     *
     ********************************************************************************/

    // binary to hex
    #[NoReturn]
    public function vadr(StateMachine $stateMachine, string|bool|int $leftValue, string|bool|int $rightValue): void
    {
        $result = 0;

        if ($stateMachine->getRegister($leftValue) === $stateMachine->getRegister($rightValue)) {
            $result = 1;
        }

        $stateMachine->setRegister('sx', $result, true);
    }

    // binary to hex
    #[NoReturn]
    public function vsig(
        StateMachine $stateMachine,
        string|bool|int $firstValue,
        string|bool|int $secondValue,
        string|bool|int $thirdValue,
    ): void {
        $publicKey = $stateMachine->getRegister($firstValue);
        $text = $stateMachine->getRegister($secondValue);
        $signature = $stateMachine->getRegister($thirdValue);
        $nResult = 0;

        $this->openssl->verifySignature($text, $signature, $publicKey);
        $stateMachine->setRegister('sx', $nResult, true);
    }

    // binary to hex
    #[NoReturn]
    public function vfal(StateMachine $stateMachine): void
    {
        $stateMachine->setRegister('sx', 0, true);
    }

    // binary to hex
    #[NoReturn]
    public function vtru(StateMachine $stateMachine): void
    {
        $stateMachine->setRegister('sx', 1, true);
    }
}
