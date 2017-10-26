<?php
namespace Blue\Tools\Api;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use InvalidArgumentException;
use PHPUnit_Framework_TestCase;
use RuntimeException;

class ClientTest extends PHPUnit_Framework_TestCase
{

    /**
     * @scenario Constructor should handle invalid inputs
     * @dataProvider invalidConstructorData
     * @expectedException InvalidArgumentException
     *
     * @param $id
     * @param $secret
     * @param $url
     */
    public function testValidClient($id, $secret, $url)
    {
        $client = new Client($id, $secret, $url);
        $this->fail('Constructor was successful with invalid data');

    }

    public function invalidConstructorData() {
        return [
            ['', 'some_secret', 'http://www.whatever.com'],
            ['someone', '', 'http://www.whatever.com'],
            ['someone', 'some_secret', ''],
            ['someone', 'some_secret', 'com.notvalid.www//:http'],
        ];
    }


    /**
     * @scenario A non-deferred response should have content available immediately
     */
    public function testGetReady()
    {


        $mockHandler = new MockHandler(
            [
                new Response(200, [], 'ABC')
            ]
        );

        $client = new Client('someone', 'some_secret', 'http://www.whatever.com', $mockHandler);

        $response = $client->get('some_path', []);

        $this->assertEquals('ABC', $response->getBody()->getContents());

    }


    /**
     * @scenario POST should work just like GET
     */
    public function testPost()
    {


        $mockHandler = new MockHandler(
            [
                new Response(200, [], 'ABC')
            ]
        );

        $client = new Client('someone', 'some_secret', 'http://www.whatever.com', $mockHandler);
        $response = $client->post('some_path', [], 'data');

        $this->assertEquals('ABC', $response->getBody()->getContents());

    }


    /**
     * @scenario If the client detects a deferred result, it will keep trying until the result is available
     */
    public function testGetDeferredWithRetries()
    {


        $mockHandler = new MockHandler(
            [
                new Response(202, [], 'NOT'),
                new Response(202, [], 'READY'),
                new Response(202, [], 'YET'),
                new Response(200, [], 'Finally!')
            ]
        );

        $client = new Client('someone', 'some_secret', 'http://www.whatever.com', $mockHandler);

        $client->setDeferredResultInterval(0);

        $response = $client->get('some_path', []);

        $this->assertEquals('Finally!', $response->getBody()->getContents());
    }


    /**
     * @scenario An exception is thrown when a deferred result cannot be obtained after a configured number of attempts
     *
     * @expectedException RuntimeException
     */
    public function testGetDeferredResultMaxAttemptsReached()
    {


        $mockHandler = new MockHandler(
            [
                new Response(202, [], 'First attempt'),
                new Response(202, [], 'Second attempt'),
                new Response(202, [], 'Third attempt'),
                new Response(200, [], 'Finally!')
            ]
        );

        $client = new Client('someone', 'some_secret', 'http://www.whatever.com', $mockHandler);

        $client->setDeferredResultInterval(0);

        $client->setDeferredResultMaxAttempts(2);

        $response = $client->get('some_path', []);

        $content = $response->getBody()->getContents();

    }
}
