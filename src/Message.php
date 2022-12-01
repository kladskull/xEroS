<?php declare(strict_types=1);

namespace Blockchain;

use Throwable;
use function gzcompress;
use function gzuncompress;
use function hash;
use function json_encode;
use function time;

/**
 * Class Message
 * @package Blockchain
 */
class Message
{
    // keep the structure small for bandwidth preservation
    private const COMMAND = 'c';
    private const DATA = 'd';
    private const HASH = 'h';
    private const RESULT = 'r';
    private const TIME = 't';
    private const VERSION = 'v';

    /**
     * @param array $message
     * @return string
     */
    private function format(array $message): string
    {
        try {
            $message[self::VERSION] = '1.0.0';
            $message[self::TIME] = time();
            $message[self::HASH] = hash('ripemd160', json_encode($message, JSON_THROW_ON_ERROR));
            $packet = json_encode($message, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $packet = null;
        }

        return $packet;
    }

    /**
     * @param string $message
     * @return bool|string
     */
    private function deflate(string $message): bool|string
    {
        return gzcompress($message, 9);
    }

    /**
     * @param string $message
     * @return bool|string
     */
    private function inflate(string $message): bool|string
    {
        return gzuncompress($message, 9);
    }

    /**
     * @param $message
     * @return string
     */
    public function process($message): string
    {
        try {
            $packet = $this->format([
                self::DATA => $this->inflate($message),
            ]);
        } catch (Throwable) {
            $packet = null;
        }

        return $packet;
    }

    /**
     * @param string $command
     * @param array $data
     * @return string
     */
    public function send(string $command, array $data): string
    {
        try {
            $packet = $this->format([
                self::COMMAND => $command,
                self::DATA => $data,
            ]);
        } catch (Throwable) {
            $packet = null;
        }

        return $this->deflate($packet);
    }

    /**
     * @param bool $result
     * @param array $data
     * @return string
     */
    public function response(bool $result, array $data): string
    {
        try {
            $packet = json_encode([
                self::RESULT => $result,
                self::DATA => $data,
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $packet = null;
        }

        return $packet;
    }
}
