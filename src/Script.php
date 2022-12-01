<?php declare(strict_types=1);

namespace Blockchain;

use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\NoReturn;
use function count;
use function ctype_xdigit;
use function explode;
use function gzcompress;
use function gzuncompress;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
use function time;
use function trim;

/**
 * Class Block
 * @package Blockchain
 */
class Script
{
    private const COMMAND_SEP = ' ';
    private const PARAM_SEP = ',';
    private const LINE_SEP = ';';
    public string $lastError = '';

    private bool $runnable;

    public Transaction $transaction;
    public TransferEncoding $transferEncoding;

    private StateMachine $stateMachine;
    private ScriptFunctions $scriptFunctions;

    #[NoReturn]
    public function __construct(array $container)
    {
        $this->transaction = new Transaction();
        $this->stateMachine = new StateMachine();
        $this->scriptFunctions = new ScriptFunctions();
        $this->transferEncoding = new TransferEncoding();
        $this->runnable = false;
        // load the container into the state machine
        $this->stateMachine->setContainer($container);
    }

    /**
     * @return string
     */
    public function getErrorMessage(): string
    {
        return $this->lastError;
    }

    /**
     * encoding actually makes the string slightly bigger overall than the script, but it needs to be encoded, so that
     * is doesn't interfere with json. An uncompressed script is about ~433 bytes, encoding it to base58 without
     * compression is about 592, and compressing it reduces it to 502.
     *
     * @param string $script
     * @return string
     */
    public function encodeScript(string $script): string
    {
        return $this->transferEncoding->binToBase58(gzcompress($script, -1));
    }

    /**
     * see note above
     * @param string $compressed
     * @return bool|string
     */
    public function decodeScript(string $compressed): bool|string
    {
        return gzuncompress($this->transferEncoding->base58ToBin($compressed));
    }

    /**
     * @param bool $debug
     * @return bool
     */
    public function run(bool $debug): bool
    {
        if (!$this->runnable) {
            return false;
        }

        while (1) {
            if (!$this->execute($debug)) {
                break;
            }

            // if at any point the sx register is false, we are done
            $sxRegister = $this->stateMachine->getRegister('sx');

            if ($sxRegister === -1) {
                break;
            }
        }

        // get the end state of the execution, get the overall state from sx
        $returnVal = $this->stateMachine->getRegister('sx');

        return $returnVal > 0;
    }

    /**
     * @param bool $debug
     * @return bool
     */
    public function execute(bool $debug): bool
    {
        // check if we are done
        $sc = $this->stateMachine->getScriptCounter();

        if ($sc > $this->stateMachine->getExecutableOperations()) {
            if ($debug) {
                $this->stateMachine->dumpState(true);
            }

            return false; // we are done
        }

        // set the program state
        $this->executeStatement(
            $this->stateMachine->getScript()[$sc]
        );

        if ($debug) {
            $this->stateMachine->dumpState();
        }

        // increment the script counter
        $this->stateMachine->incScriptCounter();

        return true;
    }

    /**
     * @param string $script
     * @return bool
     */
    public function loadScript(string $script): bool
    {
        $this->runnable = $this->validateScript($script);

        if ($this->runnable) {
            $this->runnable = true;
            $operations = $this->parseScript($script);
            $this->stateMachine->setScript($operations);

            return true;
        }

        return false;
    }

    /**
     * @return StateMachine
     */
    public function debugGetStateMachine(): StateMachine
    {
        return $this->stateMachine;
    }

    /**
     * @param string $text
     * @return string
     */
    private function cleanScript(string $text): string
    {
        while (str_contains($text, '  ')) {
            $text = str_replace('  ', ' ', $text);
        }

        return trim($text);
    }

    /**
     * @param string $script
     * @return array
     */
    public function parseScript(string $script): array
    {
        $fullScript = [];
        $script = str_replace(["\t", "\n", "\r"], '', $script);
        $lines = explode(self::LINE_SEP, $script);

        foreach ($lines as $line) {
            if (!empty(trim($line))) {
                $fullScript[] = $this->parseStatement($line);
            }
        }

        return $fullScript;
    }

    /**
     * @param string $line
     * @return array
     */
    #[ArrayShape(['command' => "string", 'params' => "string[]", 'param_count' => "int"])]
    public function parseStatement(string $line): array
    {
        // ensure we split this right
        $line = $this->cleanScript($line);
        $params = [];
        $newParams = [];
        $firstSpace = strpos($line, self::COMMAND_SEP);

        if ($firstSpace > 0) {
            $command = strtolower(substr($line, 0, $firstSpace));
            $paramsStr = substr($line, $firstSpace);
            // split the params,
            $params = explode(self::PARAM_SEP, $paramsStr);

            // replace constants with values, numeric hex with whole numbers
            foreach ($params as $param) {
                $newParams[] = $this->standardizeNumeric($this->executeValue(trim($param)));
            }
        } else {
            $command = $line;
        }

        return [
            'command' => $command,
            'params' => $newParams,
            'param_count' => count($params),
        ];
    }

    /**
     * checks basic formatting and command, doesn't test if correct registers are used, etc.
     *
     * @param string $script
     * @return bool
     */
    public function validateScript(string $script): bool
    {
        // break the script by operation
        $op = 0;
        $operations = $this->parseScript($script);

        foreach ($operations as $operation) {
            // check the command and params
            $command = $operation['command'];
            $params = $operation['params'];
            $paramCount = $operation['param_count'];

            // ensure its callable/valid
            if (isset($this->scriptFunctions->functions[$command])) {
                $reqParams = $this->scriptFunctions->functions[$command]['required_params'];

                if (count($params) !== $reqParams) {
                    $this->lastError = "expected $reqParams parameters for `$command` but given $paramCount";

                    return false;
                }
            } else {
                $this->lastError = "invalid command `$command` encountered on operation $op";

                return false;
            }

            ++$op;
        }

        return true;
    }

    /**
     * convert hex to int
     *
     * @param string $value
     * @return string
     */
    private function standardizeNumeric(string $value): string
    {
        // must be in the format of 0x...
        if (!str_starts_with($value, '0x')) {
            return $value;
        }

        $result = $value;

        if (ctype_xdigit($value)) {
            $result = BcmathExtensions::bchexdec($value);
        }

        return $result;
    }

    /**
     * @param string $value
     * @return string
     */
    public function executeValue(string $value): string
    {
        return match ($value) {
            'false' => '0',
            'true' => '1',
            'time' => time(),
        default => $value,
        };
    }

    /**
     * @param array $statement
     * @return void
     */
    #[NoReturn]
    private function executeStatement(array $statement): void
    {
        switch ($statement['command']) {
            case 'abs':
                $this->scriptFunctions->abs($this->stateMachine, $statement['params'][0]);
                break;

            case 'add':
                $this->scriptFunctions->add($this->stateMachine, $statement['params'][0], $statement['params'][1]);
                break;

            case 'adpk':
                $this->scriptFunctions->adpk($this->stateMachine, $statement['params'][0]);
                break;

            case 'adha':
                $this->scriptFunctions->adha($this->stateMachine, $statement['params'][0]);
                break;

            case 'adpa':
                $this->scriptFunctions->adpa($this->stateMachine, $statement['params'][0]);
                break;

            case 'b58e':
                $this->scriptFunctions->b58e($this->stateMachine, $statement['params'][0]);
                break;

            case 'b58d':
                $this->scriptFunctions->b58d($this->stateMachine, $statement['params'][0]);
                break;

            case 'b64e':
                $this->scriptFunctions->b64e($this->stateMachine, $statement['params'][0]);
                break;

            case 'b64d':
                $this->scriptFunctions->b64d($this->stateMachine, $statement['params'][0]);
                break;

            case 'clr':
                $this->scriptFunctions->clr($this->stateMachine, $statement['params'][0]);
                break;

            case 'cmp':
                $this->scriptFunctions->cmp($this->stateMachine, $statement['params'][0], $statement['params'][1]);
                break;

            case 'dec':
                $this->scriptFunctions->dec($this->stateMachine, $statement['params'][0]);
                break;

            case 'div':
                $this->scriptFunctions->div($this->stateMachine, $statement['params'][0], $statement['params'][1]);
                break;

            case 'dup':
                $this->scriptFunctions->dup($this->stateMachine);
                break;

            case 'hexe':
                $this->scriptFunctions->hexe($this->stateMachine, $statement['params'][0]);
                break;

            case 'hexd':
                $this->scriptFunctions->hexd($this->stateMachine, $statement['params'][0]);
                break;

            case 'inc':
                $this->scriptFunctions->inc($this->stateMachine, $statement['params'][0]);
                break;

            case 'max':
                $this->scriptFunctions->max($this->stateMachine, $statement['params'][0], $statement['params'][1]);
                break;

            case 'min':
                $this->scriptFunctions->min($this->stateMachine, $statement['params'][0], $statement['params'][1]);
                break;

            case 'mov':
                $this->scriptFunctions->mov($this->stateMachine, $statement['params'][0], $statement['params'][1]);
                break;

            case 'mul':
                $this->scriptFunctions->mul($this->stateMachine, $statement['params'][0], $statement['params'][1]);
                break;

            case 'neg':
                $this->scriptFunctions->neg($this->stateMachine, $statement['params'][0]);
                break;

            case 'nop':
                $this->scriptFunctions->nop($this->stateMachine);
                break;

            case 'rem':
                $this->scriptFunctions->rem($this->stateMachine, $statement['params'][0]);
                break;

            case 'not':
                $this->scriptFunctions->not($this->stateMachine, $statement['params'][0]);
                break;

            case 'prec':
                $this->scriptFunctions->prec($this->stateMachine, $statement['params'][0]);
                break;

            case 'sub':
                $this->scriptFunctions->sub($this->stateMachine, $statement['params'][0], $statement['params'][1]);
                break;

            case 'pop':
                $this->scriptFunctions->pop($this->stateMachine, $statement['params'][0]);
                break;

            case 'push':
                $this->scriptFunctions->push($this->stateMachine, $statement['params'][0]);
                break;

            case 'r160':
                $this->scriptFunctions->r160($this->stateMachine, $statement['params'][0]);
                break;

            case 's256':
                $this->scriptFunctions->s256($this->stateMachine, $statement['params'][0]);
                break;

            case 'vadr':
                $this->scriptFunctions->vadr($this->stateMachine, $statement['params'][0], $statement['params'][1]);
                break;

            case 'vsig':
                $this->scriptFunctions->vsig(
                    $this->stateMachine, $statement['params'][0], $statement['params'][1], $statement['params'][2]);
                break;

            case 'vfal':
                $this->scriptFunctions->vfal($this->stateMachine);
                break;

            case 'vtru':
                $this->scriptFunctions->vtru($this->stateMachine);
                break;
        }
    }
}
