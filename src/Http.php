<?php

namespace Blockchain;

use function curl_close;
use function curl_exec;
use function curl_init;
use function curl_setopt;
use function trim;

/**
 * Class Http
 * @package Blockchain
 */
class Http
{
    /**
     * @param string $url
     * @return string
     */
    public function get(string $url): string
    {
        // create curl resource
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $output = curl_exec($ch);
        curl_close($ch);

        return trim($output);
    }
}
