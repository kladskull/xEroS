<?php declare(strict_types=1);

namespace Blockchain;

use JetBrains\PhpStorm\NoReturn;
use function array_pop;
use function count;
use function str_pad;

class StateMachine
{
    private array $script = [];

    private int $executableOperations;
    private int $precision;
    private int $scriptCounter;

    // for function operations & storage
    private string|int|bool $registers_ax;
    private string|int|bool $registers_bx;
    private string|int|bool $registers_cx;
    private int $registers_dx; // used for int status (only assignable internally)
    private bool $registers_ex; // used for compare status (only assignable internally)
    private int $registers_sx; // evaluated at end as completion state (only assignable internally)

    private array $container;
    // the stack
    private array $stack = [];

    #[NoReturn]
    public function setContainer(array $container): void
    {
        $this->container = $container;
    }

    #[NoReturn]
    protected function resetStateMachine(): void
    {
        $this->precision = 0;
        $this->scriptCounter = 0;
        $this->executableOperations = 0;
        $this->script = [];
        // for function operations & storage
        $this->registers_ax = '';
        $this->registers_bx = '';
        $this->registers_cx = '';
        $this->registers_dx = -2; // set to something it cannot be
        $this->registers_ex = false;
        // boolean state only, used for evaluations, evaluated at end as completion state
        $this->registers_sx = -2;
        // the stack
        $this->stack = [];
    }

    private function showBool(int $value): string
    {
        return $value === 1 ? 'true' : 'false';
    }

    #[NoReturn]
    public function dumpState(bool $finalState = false): void
    {
        echo $finalState ? "Final State\n" : "State\n";
        echo "+----------------+\n";
        echo "| script counter : `$this->scriptCounter`\n";
        echo "| max executions : `$this->executableOperations`\n";
        echo "| precision      : `$this->precision`\n";
        echo "| Register ax    : `$this->registers_ax`\n";
        echo "| Register bx    : `$this->registers_bx`\n";
        echo "| Register cx    : `$this->registers_cx`\n";
        echo "| Register dx    : " . $this->registers_dx . "\n";
        echo "| Register ex    : " . $this->showBool((int)$this->registers_ex) . "\n";
        echo "| Register sx    : " . $this->showBool($this->registers_sx) . "\n";
        echo "+----------------+\n";

        foreach ($this->container as $key => $item) {
            echo "| Container-> $key : " . $item . "\n";
        }

        if (count($this->stack)) {
            echo "+----------------+\n";

            foreach ($this->stack as $s) {
                echo "| Stack          : `$s`\n";
            }
        }

        echo "+----------------+\n";
        echo "| Script         :\n";
        $lsc = 0;

        foreach ($this->script as $line) {
            $this->dumpScriptLine($line, ($lsc++ === $this->scriptCounter));
        }

        echo "-----------------+\n\n";
    }

    #[NoReturn]
    private function dumpScriptLine($line, $current = false): void
    {
        echo $current ? "  -> " : "     ";
        echo str_pad($line['command'], 10) . ' ';

        foreach ($line['params'] as $param) {
            echo "  " . $param . ' ';
        }

        echo "\n";
    }

    public function getExecutableOperations(): int
    {
        return $this->executableOperations;
    }

    public function getScriptCounter(): int
    {
        return $this->scriptCounter;
    }

    #[NoReturn]
    public function incScriptCounter(): void
    {
        $this->scriptCounter++;
    }

    public function getPrecision(): int
    {
        return $this->precision;
    }

    #[NoReturn]
    public function setPrecision(int $precision): void
    {
        if ($precision < 0) {
            $precision = 0;
        }

        if ($precision > 16) {
            $precision = 16;
        }

        // set the precision
        $this->precision = $precision;
    }

    public function getScript(): array
    {
        return $this->script;
    }

    #[NoReturn]
    public function setScript(array $script): void
    {
        // set up the state machine
        $this->resetStateMachine();

        // set the script
        $this->script = $script;
        $this->executableOperations = count($script) - 1;
    }

    public function isRegister(string $value): bool
    {
        return match ($value) {
            'ax', 'bx', 'cx', 'dx', 'ex', 'sx' => true,
        default => false,
        };
    }

    public function getRegister(string $register): string|bool|int
    {
        $found = false;
        $value = $register;

        if ($register === 'ax') {
            $value = $this->registers_ax;
            $found = true;
        } elseif ($register === 'bx') {
            $value = $this->registers_bx;
            $found = true;
        } elseif ($register === 'cx') {
            $value = $this->registers_cx;
            $found = true;
        } elseif ($register === 'dx') {
            $value = $this->registers_dx;
            $found = true;
        } elseif ($register === 'ex') {
            $value = $this->registers_ex;
            $found = true;
        } elseif ($register === 'sx') {
            $value = $this->registers_sx;
            $found = true;
        }

        // check the container for the value
        if (!$found && isset($this->container[$register])) {
            $value = $this->container[$register];
        }

        return $value;
    }

    // if it's not a register, we will throw it on the stack
    #[NoReturn]
    public function setRegister(string $register, string|int|bool $value, bool $system = false): void
    {
        if ($this->isRegister($register)) {
            if ($register === 'ax') {
                $this->registers_ax = $value;
            } elseif ($register === 'bx') {
                $this->registers_bx = $value;
            } elseif ($register === 'cx') {
                $this->registers_cx = $value;
            }

            if ($system) {
                if ($register === 'dx') {
                    $this->registers_dx = $value; // not assignable by interpreter
                } elseif ($register === 'ex') {
                    $this->registers_ex = $value; // not assignable by interpreter
                } elseif ($register === 'sx') {
                    $this->registers_sx = $value; // not assignable by interpreter
                }
            }
        } else {
            $this->pushStack($value);
        }
    }

    #[NoReturn]
    public function pushStack(string $value): void
    {
        $this->stack[] = $value;
    }

    public function popStack(): string
    {
        return array_pop($this->stack) ?? '';
    }
}
