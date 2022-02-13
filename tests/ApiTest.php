<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;


class ApiTest extends TestCase
{
    private Http $http;

    protected function setUp(): void
    {
        $this->http = new Http();
    }

    /**
     * @throws JsonException
     */
    public function testApiParseResponse(): void
    {
        $response = $this->http->get(Config::getTestNodeUrl() . '/ping.php');
        $this->assertTrue(Api::validateResponse($response));
    }

}