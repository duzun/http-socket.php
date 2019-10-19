# http-socket.php [![Build Status](https://travis-ci.org/duzun/http-socket.php.svg?branch=master)](https://travis-ci.org/duzun/http-socket.php)
HTTP (&lt;2.0) Requests over Socket - get as close to the socket level as possible

## Usage

```php
use duzun\HttpSocket;

$http = new HttpSocket(
        // host / URL
        'https://httpbin.org/path',
        // Options
        array(
            'timeout'     => 7,
            'decode'      => true,  // accept gzip'ed response & decode it
            'redirects'   => 3,     // follow up to 3 redirects
            'close'       => false, // keep-alive
            'use_cookies' => true,  // parse cookies
        ),
        // Request Headers
        array(
            'Accept'     => 'application/json,*/*;q=0.8',
            // 'User-Agent' => 'Mozilla/5.0 (compatible; '.HttpSocket::class.'/'.HttpSocket::VERSION.'; +https://github.com/duzun/http-socket.php)',
        ),
        // Request Body
        '' 
    );

// $res === $http if there was no redirect
$res = $http
    ->setOption('decode', false) // if we need .headers.CONTENT_LENGTH to equal the actual body size
    ->setOption('method', 'GET')
    ->setPath('/get')
    // ->open() // auto-open on first use
    ->writeHead([
        'origin' => 'https://httpbin.org/'
    ])
    // ->write('request body', ['header' => 'value']) // auto-write on first read
    ->read()
    // ->close() // auto-closes on __destruct
;

// write & read automatically on get*()
$headers = $res->getCode(); 
$headers = $res->getHeaders(); 
$body    = $res->getBody();

// The response
$res->code    === 200;
$res->headers === [ 
    'content-type' => 'application/json',
    'date' => 'Sat, 19 Oct 2019 20:58:18 GMT',
    'content-length' => '402',
    'connection' => 'keep-alive',
    // ...
];
$res->body    === '...';
$res->url     === 'https://httpbin.org/get';
$res->phases  === [
    'open' => 442908,
    'writeHead' => 18,
    'readHead' => 132561,
    'readBody' => 6,
    'total' => 575607,
];

unset($res); // auto-calls $res->close();
```
