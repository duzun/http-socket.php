<?php

use duzun\HttpSocket;

// -----------------------------------------------------
/**
 *  @author DUzun.Me
 */
// -----------------------------------------------------
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . '_PHPUnit_BaseClass.php';

// -----------------------------------------------------

class TestHttpSocket extends PHPUnit_BaseClass
{
    // -----------------------------------------------------
    /**
     * @var HttpSocket
     */
    public static $inst;

    /**
     * @var boolean
     */
    public static $log = true;

    /**
     * @var string
     */
    public static $className = 'duzun\HttpSocket';

    // Before any test
    public static function mySetUpBeforeClass()
    {
        // Template instance for all tests
        self::$inst = new HttpSocket(
            'https://httpbin.org/',
            array(
                'timeout'     => 7,
                'decode'      => true,  // accept gzip'ed response
                'redirects'   => 0,     // don't follow redirects
                'close'       => false, // keep-alive
                'use_cookies' => true,  // parse cookies
            ),
            array(
                'Accept'     => 'application/json,*/*;q=0.8',
                'Referer'    => 'https://github.com/duzun/http-socket.php/blob/master/tests/' . basename(__FILE__),
                // 'User-Agent' => 'Mozilla/5.0 (compatible; '.HttpSocket::class.'/'.HttpSocket::VERSION.'; +https://github.com/duzun/http-socket.php)',
            )
        );
    }

    // After all tests
    public static function myTearDownAfterClass()
    {
        self::$inst = null;
    }

    // -----------------------------------------------------
    // -----------------------------------------------------
    public function testSimple()
    {
        $http = clone self::$inst;

        // $res === $http if there was no redirect
        $res = $http
            ->setOption('decode', false) // we need .headers.CONTENT_LENGTH to equal the actual body size for this test
            ->setPath('/get')
            ->write()
            ->read()
            ->close() // close explicitly, but should close on __destruct
        ;

        self::log('response', array(
            'code'    => $res->code,
            'msg'     => $res->msg,
            'headers' => $res->headers,
            'cookie'  => $res->cookie,
            'body'    => $res->body,
            'phases'  => $res->getPhases(),
            // 'timings' => $res->getTimings(),
        ));

        // Response
        $this->assertEquals(200, $res->code);
        $this->assertEquals('OK', $res->msg);
        $this->assertEquals('keep-alive', $res->headers['connection']); // .options.close == false
        $this->assertEquals($res->headers['content-length'], strlen($res->body));

        $json = json_decode($res->getBody()); // ->body
        $this->assertNotEmpty($json);

        // Request
        $this->assertEquals($json->url, $res->url); // ->getUrl()
        $this->assertEquals(443, $res->port); // ->getPort()

        $expectedRequestHeaders = array_change_key_case((array) $json->headers);
        $actualRequestHeaders = $res->rheaders;

        $this->assertEquals('keep-alive', $actualRequestHeaders['connection']);
        unset($actualRequestHeaders['connection']);

        $this->assertEquals($expectedRequestHeaders, $actualRequestHeaders);
        // $this->assertEquals(count($expectedRequestHeaders), count($actualRequestHeaders));
    }

    // -----------------------------------------------------
}
