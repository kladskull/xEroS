<?php

namespace Xeros;

use Exception;

class Message
{
    // keep the structure small for bandwidth preservation
    private const COMMAND = 'c';
    private const DATA = 'd';
    private const HASH = 'h';
    private const RESULT = 'r';
    private const TIME = 't';
    private const VERSION = 'v';

    private function format(array $message): string
    {
        try {
            $message[self::VERSION] = '1.0.0';
            $message[self::TIME] = time();
            $message[self::HASH] = hash('ripemd160', json_encode($message, JSON_THROW_ON_ERROR));
            $packet = json_encode($message, JSON_THROW_ON_ERROR);
        } catch (Exception) {
            $packet = null;
        }
        return $packet;
    }

    private function deflate(string $message): bool|string
    {
        return gzcompress($message, 9);
    }

    private function inflate(string $message): bool|string
    {
        return gzuncompress($message, 9);
    }

    public function process($message): string
    {
        try {
            $packet = $this->format([
                self::DATA => $this->inflate($message),
            ]);
        } catch (Exception) {
            $packet = null;
        }
        return $packet;
    }

    public function send(string $command, array $data): string
    {
        try {
            $packet = $this->format([
                self::COMMAND => $command,
                self::DATA => $data,
            ]);
        } catch (Exception) {
            $packet = null;
        }
        return $this->deflate($packet);
    }

    public function response(bool $result, array $data): string
    {
        try {
            $packet = json_encode([
                self::RESULT => $result,
                self::DATA => $data,
            ], JSON_THROW_ON_ERROR);
        } catch (Exception) {
            $packet = null;
        }
        return $packet;
    }
}