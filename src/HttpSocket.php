<?php

namespace duzun;

/**
 * HTTP Write-Read over socket
 *
 * @author Dumitru Uzun
 * @version 0.0.1
 */
class HttpSocket
{
    const VERSION = '0.0.1';

    // State of the instance constants
    const ERROR        = -1;
    const CREATED      = 0;
    const OPEN         = 1;
    const WROTE_HEAD   = 2;
    const WROTE        = 3;
    const READ_HEAD    = 4;
    const REDIRECTED   = 5;
    const READ         = 6;

    // Options
    public $options;

    // Request
    public $host;
    public $path;
    public $rheaders;
    public $rbody;
    // public $request; // ::getRequestHeadStr() . self::BOUNDARY . $this->rbody . self::BOUNDARY

    // Response
    public $code;
    public $msg;
    public $headers;
    public $body;
    public $redirect;
    public $cookie;

    // Internal state
    protected $_host;
    protected $_port;
    protected $_socket;
    protected $_state = 0;
    protected $_timings;
    protected $_phases;

    const EOL = "\r\n";
    const BOUNDARY = "\r\n\r\n";
    const CHUNK_SIZE = 1024;


    // ------------------------------------------------------------------------
    /**
     * Executes a HTTP write-read session.
     *
     * @param  string $host      - IP/HOST address or URL
     * @param  array  $options   - list of option as key-value:
     *                              timeout - connection timeout in seconds
     *                              host    - goes to headers, overrides $host (ex. $host == '127.0.0.1', $options['host'] == 'www.example.com')
     *                              port    - useful when $host is not a full URL
     *                              scheme  - http, ssl, tls, udp, ...
     *                              close   - whether to close connection o not
     *                              use_cookies - If true, parse cookies and preserve them on redirect
     *                              redirects - number of allowed redirects
     *                              redirect_method - if (string), this is the new method for redirect request, else
     *                                                if true, preserve method, else use 'GET' on redirect.
     *                                                by default preserve on 307 and 308, GET on 301-303
     * @param  array  $head      - list off HTTP headers to be sent along with the request to $host
     * @param  mixed  $body      - data to be sent as the contents of the request. If is array or object, a http query is built.
     */
    public function __construct($host, $options = null, $head = null, $body = null)
    {
        empty($options) and $options = array();

        // If $host is a URL
        if ($p = strpos($host, '://') and $p < 7) {
            $p = parse_url($host);
            if (!$p) {
                throw new \Exception('Wrong host specified'); // error
            }
            $host = $p['host'];
            $path = isset($p['path']) ? $p['path'] : null;
            if (isset($p['query'])) {
                $path .= '?' . $p['query'];
            }
            $port = isset($p['port']) ? $p['port'] : null;

            // .path & .query do not belong to options
            unset($p['path'], $p['query']);
            $options += $p;
        }
        // If $host is not an URL, but might contain path and port
        else {
            $p = explode('/', $host, 2);
            list($host, $path) = $p;
            $p = explode(':', $host, 2);
            list($host, $port) = $p;
        }

        $this->setPath($path);

        // $options['port'] has priority over $host's port
        if ($port && empty($options['port'])) {
            $options['port'] = $port;
        }

        $this->host =
            $this->_host = $host;

        if (!empty($options['scheme'])) {
            switch ($p['scheme']) {
                case 'http':
                case 'ftp':
                    break;
                case 'https':
                    $this->_host = 'tls://' . $host;
                    break;
                default:
                    $this->_host = $options['scheme'] . '://' . $host;
            }
        }

        $this->rheaders = array(
            'host'   => null,
            // 'accept' => 'text/html,application/xhtml+xml,application/xml;q =0.9,*/*;q=0.8',
            'accept' => '*/*',
            'user-agent' => 'Mozilla/5.0 (compatible; ' . get_class($this) . '/' . self::VERSION . '; +https://github.com/duzun/http-socket.php)',
        );

        if ($body) {
            $this->setRequestBody($body);
        }

        if ($head) {
            $this->setRequestHeaders($head);
        }

        $this->options  = $options;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __clone()
    {
        $this->_clearResponse(false);
        $this->_state   = self::CREATED;
        $this->redirect =
            $this->_socket  =
            $this->_timings =
            $this->_phases  = null;
    }

    /**
     * Open the socket.
     *
     * @return self $this or FALSE when _state == ERROR
     */
    public function open()
    {
        if ($this->_state >= self::OPEN) {
            return $this;
        }

        if ($this->_state === self::ERROR) return false;

        $timeout = isset($this->options['timeout']) ? $this->options['timeout'] : @ini_get('default_socket_timeout');

        $errno  = 0;
        $errstr = null;
        isset($this->_port) or $this->_port = $this->getPort(80);
        $this->_timings[__FUNCTION__] = $fsTime = microtime(true);
        $fs     = @fsockopen($this->_host, $this->_port, $errno, $errstr, $timeout);
        $this->_phases[__FUNCTION__]  = (int) round((microtime(true) - $fsTime) * 1e6);
        if (!$fs) {
            $this->_state = self::ERROR;
            throw new \Exception('Unable to create socket "' . $this->_host . ':' . $this->_port . '" ' . $errstr, $errno);
        }

        $this->_socket = $fs;
        $this->_state = self::OPEN;

        return $this;
    }

    /**
     * Write the HTTP request head over the socket.
     *
     * @param  array  $head - list off HTTP headers to be sent along with the request to $host
     * @return self $this or FALSE when _state == ERROR
     */
    public function writeHead($head = null)
    {
        $this->reuse($head);

        if ($this->_state >= self::WROTE_HEAD) {
            if ($head) {
                throw new \Exception("Can't set head, request already sent");
            }

            return $this;
        }

        if ($head) {
            $this->setRequestHeaders($head);
        }

        if (!$this->open()) {
            return false;
        }

        $head = $this->getRequestHeadStr() . self::BOUNDARY;
        $this->_timings[__FUNCTION__] = $fsTime = microtime(true);
        $bytes = fwrite($this->_socket, $head);
        $this->_phases[__FUNCTION__]  = (int) round((microtime(true) - $fsTime) * 1e6);

        if ($bytes === false) {
            $this->_state = self::ERROR;
            $this->close();
            throw new \Exception('Unable to write to socket "' . $this->_host . ':' . $this->_port . '"');
        }

        $this->_state = self::WROTE_HEAD;

        return $this;
    }

    /**
     * Write the HTTP request over the socket.
     *
     * @param  mixed  $body - data to be sent as the contents of the request. If is array or object, a http query is built.
     * @param  array  $head - list off HTTP headers to be sent along with the request to $host
     * @return self $this or FALSE when _state == ERROR
     */
    public function write($body = null, $head = null)
    {
        $this->reuse($body || $head);

        if ($this->_state >= self::WROTE) {
            if ($body) {
                throw new \Exception("Can't set head or body, request already sent");
            }
            return $this;
        }

        if ($body) {
            $this->setRequestBody($body);
        }

        if (!$this->writeHead($head)) {
            return false;
        }

        if ($this->rbody) {
            $body = $this->rbody . self::BOUNDARY;
            $this->_timings[__FUNCTION__] = $fsTime = microtime(true);
            $bytes = fwrite($this->_socket, $body);
            $this->_phases[__FUNCTION__]  = (int) round((microtime(true) - $fsTime) * 1e6);

            if ($bytes === false) {
                $this->_state = self::ERROR;
                $this->close();
                throw new \Exception('Unable to write to socket "' . $this->_host . ':' . $this->_port . '"');
            }
        } else {
            // In the case of reuse, these values might be present from the previous request
            unset($this->_timings[__FUNCTION__], $this->_phases[__FUNCTION__]);
        }

        $this->_state = self::WROTE;

        return $this;
    }

    public function readHead()
    {
        if ($this->_state >= self::READ_HEAD) {
            return $this->headers;
        }

        $this->write();

        if ($this->_state === self::ERROR) return false;

        // read headers
        $fs = $this->_socket;
        $rsps = '';
        $this->_timings[__FUNCTION__] = $fsTime = microtime(true);
        while ($open = !feof($fs) && ($p = @fgets($fs, self::CHUNK_SIZE))) {
            if (self::EOL == $p) {
                break;
            }

            $rsps .= $p;
        }
        $this->_phases[__FUNCTION__]  = (int) round((microtime(true) - $fsTime) * 1e6);

        // End of response
        if (!$open) {
            if (!$rsps) {
                $this->close();
                $this->_state = self::ERROR;
                throw new \Exception('unable to read from socket or empty response');
            }
        }

        // Parse headers
        $headers = array();
        $head    = explode(self::EOL, rtrim($rsps));

        list($prot, $code, $msg) = explode(' ', array_shift($head), 3);
        foreach ($head as $v) {
            $v = explode(':', $v, 2);
            $k = strtolower(strtr($v[0], '_ ', '--'));
            $v = isset($v[1]) ? trim($v[1]) : null;

            // Gather headers
            if (isset($headers[$k])) {
                if (isset($v)) {
                    if (is_array($headers[$k])) {
                        $headers[$k][] = $v;
                    } else {
                        $headers[$k] = array($headers[$k], $v);
                    }
                }
            } else {
                $headers[$k] = $v;
            }
        }

        if (!empty($headers['set-cookie']) && !empty($this->options['use_cookies'])) {
            $this->cookie = self::parse_cookie((array) $headers['set-cookie']);
        }

        $this->headers = $headers;

        $_code = (int) $code;
        $this->code = $code === (string) $_code ? $_code : $code;
        $this->msg  = $msg;

        $this->_state = self::READ_HEAD;

        if (!$open) {
            $this->close(true);
        }

        return $headers;
    }

    public function followRedirect()
    {
        if ($this->_state >= self::REDIRECTED) {
            return $this->redirect;
        }

        $headers = $this->readHead();

        if ($this->_state === self::ERROR) return false;

        $_preserve_method = true;
        switch ($this->code) {
            case 301:
            case 302:
            case 303:
                $_preserve_method = false;
            case 307:
            case 308:
                $options = $this->options;

                // repeat request using the same method and post data
                if (@$options['redirects'] > 0 && $loc = @$headers['location']) {
                    $host = $this->host;
                    if (!empty($options['host'])) {
                        $host = $options['host'];
                    }
                    is_array($loc) and $loc = end($loc);

                    $loc = self::abs_url($loc, compact('host', 'port', 'path') + array('scheme' => empty($options['scheme']) ? '' : $options['scheme']));
                    $rheaders = $this->rheaders;
                    unset($rheaders['host'], $options['host'], $options['port'], $options['scheme']);
                    if (isset($options['redirect_method'])) {
                        $redirect_method = $options['redirect_method'];
                        if (is_string($redirect_method)) {
                            $options['method'] = $redirect_method = strtoupper($redirect_method);
                            $_preserve_method  = true;
                            if ('POST' != $redirect_method && 'PUT' != $redirect_method && 'DELETE' != $redirect_method) {
                                $body = null;
                            }
                        } else {
                            $_preserve_method = (bool) $redirect_method;
                        }
                    }
                    if (!$_preserve_method) {
                        $body = null;
                        unset($options['method']);
                    }
                    --$options['redirects'];
                    // ??? could save cookies for redirect
                    $t = $this->cookie;
                    if ($t) {
                        $now = time();
                        // @TODO: Filter out cookies by $c['domain'] and $c['path'] (compare to $loc)
                        foreach ($t as $c) {
                            if (empty($c['expires']) || $c['expires'] >= $now) {
                                $rheaders['cookie'] = (empty($rheaders['cookie']) ? '' : $rheaders['cookie'] . '; ') .
                                    $c['key'] . '=' . $c['value'];
                            }
                        }
                    }
                    $this->close();
                    $this->redirect = new self($loc, $rheaders, $body, $options);
                    return $this->redirect;
                }
                break;
        }

        $this->_state = self::REDIRECTED;
    }

    public function readBody()
    {
        if ($this->_state >= self::READ) {
            return $this->body;
        }

        $headers = $this->readHead();

        if ($this->_state === self::ERROR) return false;

        $fs = $this->_socket;

        $open = !!$fs;
        if (!$open) {
            // $this->_state = self::READ; // looks like an error
            return null;
        }

        $code = $this->code;
        $rsps = null;

        // Detect body length
        if (@!$fs || $code < 200 || 204 == $code || 304 == $code || 'HEAD' == $this->getMethod()) {
            $te = 1; // no body
        } elseif (isset($headers['transfer-encoding']) && strtolower($headers['transfer-encoding']) === 'chunked') {
            $te = 3;
        } elseif (isset($headers['content-length'])) {
            $bl = (int) $headers['content-length'];
            $te = 2;
        } else {
            $te = 0; // useless, just to avoid Notice: Undefined variable: te...
        }

        $this->_timings[__FUNCTION__] = $fsTime = microtime(true);
        switch ($te) {
            case 1:
                break;

            case 2:
                while ($bl > 0 and $open &= !feof($fs) && ($p = @fread($fs, $bl))) {
                    $rsps .= $p;
                    $bl -= strlen($p);
                }
                break;

            case 3:
                while ($open &= !feof($fs) && ($p = @fgets($fs, self::CHUNK_SIZE))) {
                    $_re = explode(';', rtrim($p));
                    $cs  = reset($_re);
                    $bl  = hexdec($cs);
                    if (!$bl) {
                        break;
                    }
                    // empty chunk
                    while ($bl > 0 and $open &= !feof($fs) && ($p = @fread($fs, $bl))) {
                        $rsps .= $p;
                        $bl -= strlen($p);
                    }
                    @fgets($fs, 3); // \r\n
                }
                if ($open &= !feof($fs) && ($p = @fgets($fs, self::CHUNK_SIZE))) {
                    if ($p = rtrim($p)) {
                        // ??? Trailer Header
                        $v = explode(':', $p, 2);
                        $k = strtolower(strtr($v[0], '_ ', '--'));
                        $v = isset($v[1]) ? trim($v[1]) : null;

                        // Gather headers
                        if (isset($headers[$k])) {
                            if (isset($v)) {
                                if (is_array($headers[$k])) {
                                    $headers[$k][] = $v;
                                } else {
                                    $headers[$k] = array($headers[$k], $v);
                                }
                            }
                        } else {
                            $headers[$k] = $v;
                        }

                        $this->headers = $headers;

                        @fgets($fs, 3); // \r\n
                    }
                }
                break;

            default:
                while ($open &= !feof($fs) && ($p = @fread($fs, self::CHUNK_SIZE))) {
                    // ???
                    $rsps .= $p;
                }
                break;
        }
        $this->_phases[__FUNCTION__]  = (int) round((microtime(true) - $fsTime) * 1e6);

        $this->_state = self::READ;
        $this->close(true);

        if (
            '' != $rsps &&
            isset($this->options['decode']) && 'gzip' == $this->options['decode'] &&
            isset($headers['content-encoding']) && 'gzip' == $headers['content-encoding']
        ) {
            $r = self::gzdecode($rsps);
            if (false === $r) {
                $this->_state = self::ERROR;
                throw new \Exception("Can't gzdecode(response), try ['decode' => false] option");
            }
            unset($this->headers['content-encoding']);
            $rsps = $r;
        }

        $this->body = $rsps;

        return $rsps;
    }

    public function read()
    {
        $this->readHead();

        $res = $this;
        while ($redirect = $res->followRedirect()) {
            $res = $redirect;
        }

        $res->readBody();

        return $res;
    }

    public function close($ifNotKeppAlive = false)
    {
        if (!$this->_socket) return $this;

        if ($ifNotKeppAlive) {
            $headers = $this->headers;
            $options = $this->options;
            if (
                empty($options['close']) and
                !isset($headers['connection']) || $headers['connection'] !== 'close'
            ) {
                return $this;
            }
        }
        $this->_timings[__FUNCTION__] = microtime(true);
        $this->_phases['total'] = (int) round((microtime(true) - $this->_timings['open']) * 1e6);

        if (!fclose($this->_socket)) return false;

        $this->_port = // will re-read port from options on re-open
            $this->_socket = null;
        if ($this->_state >= self::OPEN && $this->_state < self::READ) {
            $this->_state = self::READ;
        }

        return $this;
    }

    public function reuse($clearRequest = false)
    {
        if ($this->_state === self::READ && $this->_socket) {
            $this->_state = self::OPEN;

            // Clear response fields, except cookies
            $this->_clearResponse(false);

            if ($clearRequest) {
                $this->rbody = null;
                $this->rheaders = array_intersect_key(array('host' => 1, 'accept' => 1, 'user-agent' => 1), $this->rheaders);
            }

            return true;
        }
        return false;
    }

    protected function _clearResponse($clearCookiesToo = false)
    {
        // Clear response fields, except cookies
        $this->body = $this->header = $this->code = $this->msg = null;

        if ($clearCookiesToo) {
            $this->cookie = null;
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    /**
     * Get response code
     *
     * @return int HTTP response code
     */
    public function getCode()
    {
        if (!isset($this->code)) {
            $this->readHead();
        }

        return $this->code;
    }

    /**
     * Get response headers as key-value array, PHP style (eg. ['CONTENT_TYPE' => 'text/html; charset=UTF-8']).
     * If a header is present more than once, the value would be an array, listing all values for the header.
     *
     * @return array HTTP response headers
     */
    public function getHeaders()
    {
        return isset($this->headers) ? $this->headers : $this->readHead();
    }

    /**
     * Get response body
     *
     * @return string HTTP response body
     */
    public function getBody()
    {
        return isset($this->body) ? $this->body : $this->readBody();
    }

    // -------------------------------------------------------------------------
    public function setOption($name, $value = null)
    {
        is_array($name) or $name = array($name => $value);
        foreach ($name as $name => $value) {
            $this->options[$name] = $value;
        }

        return $this;
    }

    /**
     * Set request path
     * @return self $this
     */
    public function setPath($path)
    {
        if (strncmp($path, '/', 1)) {
            $path = '/' . $path;
        }
        $this->path = $path;

        return $this;
    }

    /**
     * @param  mixed $body - data to be sent as the contents of the request. If is array or object, a http query is built.
     * @return self $this
     */
    public function setRequestBody($body)
    {
        if ($body) {
            if (is_array($body) || is_object($body)) {
                $body = http_build_query($body);
                $this->rheaders += array('content-type' => 'application/x-www-form-urlencoded');
            }
            $body = (string) $body;
            $this->rheaders['content-length'] = strlen($body);
            $this->rbody = $body;
        }

        return $this;
    }

    /**
     * @param  array $head - list off HTTP headers to be sent along with the request to $host
     * @return self $this
     */
    public function setRequestHeaders($head)
    {
        if ($head) {
            if (!is_array($head)) {
                $head = explode(self::EOL, $head);
            }
            foreach ($head as $i => $v) {
                if (is_int($i)) {
                    $v = explode(':', $v, 2);
                    if (count($v) != 2) {
                        // Invalid header
                        continue;
                    }
                    list($i, $v) = $v;
                }
                $i = strtolower(strtr($i, ' _', '--'));
                if (isset($v)) {
                    $this->rheaders[$i] = trim($v);
                } else {
                    unset($this->rheaders[$i]);
                }
            }
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    /**
     * Get the host the socket connects to
     *
     * @return string
     */
    public function getHost()
    {
        return $this->_host;
    }

    /**
     * Get the port the socket connects to
     *
     * @return int
     */
    public function getPort($default = 80)
    {
        if (isset($this->_port)) return $this->_port;
        if (isset($this->options['port'])) return $this->options['port'];
        return self::scheme_to_port($this->options['scheme'], $default);
    }

    /**
     * Get the request URL
     *
     * @return string
     */
    public function getUrl()
    {
        $scheme = $this->options['scheme'];
        return  $scheme . '://' .
            $this->host . ($this->_port && $this->_port !== self::scheme_to_port($scheme) ? ':' . $this->_port : '') .
            $this->path;
    }

    /**
     * Get the request method
     *
     * @return string
     */
    public function getMethod()
    {
        $options = $this->options;
        return empty($options['method']) ? empty($this->rbody) ? 'GET' : 'POST' : strtoupper($options['method']);
    }

    public function getRequestHeadStr()
    {
        $path = $this->path;
        $meth = $this->getMethod();
        $options = $this->options;
        $rheaders = $this->rheaders;
        $prot = empty($options['protocol']) ? 'HTTP/1.1' : $options['protocol'];

        if (!isset($rheaders['host'])) {
            $rheaders['host'] = isset($options['host']) ? $options['host'] : $this->host;
        }

        if (isset($options['decode']) && $options['decode'] == 'gzip') {
            // if(self::gz_supported()) {
            $rheaders['accept-encoding'] = 'gzip';
            // }
            // else {
            // $options['decode'] = NULL;
            // }
        }

        if (!isset($rheaders['connection'])) {
            $rheaders['connection'] = !isset($options['close']) || $options['close'] ? 'close' : 'keep-alive';
        }

        // Store the actually sent headers
        $this->rheaders = $rheaders;

        $head = array("$meth $path $prot");
        foreach ($rheaders as $i => $v) {
            $i = explode('-', $i);
            foreach ($i as &$j) {
                $j = ucfirst($j);
            }

            $i      = implode('-', $i);
            $head[] = $i . ': ' . $v;
        }

        return implode(self::EOL, $head);
    }

    // -------------------------------------------------------------------------
    /**
     * Get timestamps of last request phases in seconds.
     *
     * Eg.
     * [
     *  'open'      => 1571163565.465671,
     *  'writeHead' => 1571163565.558276,
     *  'readHead'  => 1571163565.558384,
     *  'readBody'  => 1571163565.57586,
     *  'close'     => 1571163565.588129,
     * ]
     *
     * @return array
     */
    public function getTimings()
    {
        return $this->_timings;
    }

    /**
     * Get durations of last request phases in microseconds.
     *
     * Eg.
     * [
     *  'open'      => 22763,
     *  'writeHead' => 80,
     *  'readHead'  => 17302,
     *  'readBody'  => 11787,
     *  'total'     => 122460,
     * ]
     *
     * @return array
     */
    public function getPhases()
    {
        return $this->_phases;
    }

    // -------------------------------------------------------------------------
    public function __get($name)
    {
        $meth = 'get' . $name;
        if (method_exists($this, $meth) && is_callable(array($this, $meth))) {
            return $this->$meth();
        }

        // Read-only access to private properties
        $_name = '_' . $name;
        if (isset($this->{$_name})) {
            return $this->{$_name};
        }
    }

    // -------------------------------------------------------------------------
    /**
     * @param  $str
     * @return mixed
     */
    public static function parse_cookie($str)
    {
        $ret = array();
        if (is_array($str)) {
            foreach ($str as $k => $v) {
                $ret[$k] = self::parse_cookie($v);
            }
            return $ret;
        }

        $str          = explode(';', $str);
        $t            = explode('=', array_shift($str), 2);
        $ret['key']   = $t[0];
        $ret['value'] = $t[1];
        foreach ($str as $t) {
            $t = explode('=', trim($t), 2);
            if (count($t) == 2) {
                $ret[strtolower($t[0])] = $t[1];
            } else {
                $ret[strtolower($t[0])] = true;
            }
        }

        if (!empty($ret['expires']) && is_string($ret['expires'])) {
            $t = strtotime($ret['expires']);
            if (false !== $t and -1 !== $t) {
                $ret['expires'] = $t;
            }
        }

        return $ret;
    }

    /**
     * Get the default port for a given scheme
     *
     * @param  string  $scheme
     * @param  int     $default If scheme unknown or empty, return this value
     * @return int
     */
    public static function scheme_to_port($scheme, $default = 80)
    {
        switch ($scheme) {
            case 'tls':
            case 'ssl':
            case 'https':
                return 443;

            case 'ftp':
                return 21;

            case 'sftp':
                return 22;

            case 'http':
                return 80;

            default:
                return $default;
        }
    }

    // -------------------------------------------------------------------------
    /**
     * Check whether $path is a valid url.
     *
     * @param  string $path - a path to check
     * @return bool   TRUE if $path is a valid URL, FALSE otherwise
     */
    public static function is_url_path($path)
    {
        return preg_match('/^[a-zA-Z]+\:\/\//', $path);
    }


    /**
     * Given a $url (relative or absolute) and a $base url, returns absolute url for $url.
     *
     * @param  string $url     - relative or absolute URL
     * @param  string $base    - Base URL for $url
     * @return string absolute URL for $url
     */
    public static function abs_url($url, $base)
    {
        if (!self::is_url_path($url)) {
            $t = is_array($base) ? $base : parse_url($base);
            if (strncmp($url, '//', 2) == 0) {
                if (!empty($t['scheme'])) {
                    $url = $t['scheme'] . ':' . $url;
                }
            } else {
                $base = (empty($t['scheme']) ? '//' : $t['scheme'] . '://') .
                    $t['host'] . (empty($t['port']) ? '' : ':' . $t['port']);
                if (!empty($t['path'])) {
                    $s = dirname($t['path'] . 'f');
                    if (DIRECTORY_SEPARATOR != '/') {
                        $s = strtr($s, DIRECTORY_SEPARATOR, '/');
                    }
                    if ($s && '.' !== $s && '/' !== $s && substr($url, 0, 1) !== '/') {
                        $base .= '/' . ltrim($s, '/');
                    }
                }
                $url = rtrim($base, '/') . '/' . ltrim($url, '/');
            }
        } else {
            $p = strpos($url, ':');
            if (substr($url, $p + 3, 1) === '/' && in_array(substr($url, 0, $p), array('http', 'https'))) {
                $url = substr($url, 0, $p + 3) . ltrim(substr($url, $p + 3), '/');
            }
        }
        return $url;
    }

    // -------------------------------------------------------------------------
    /**
     * Find a function to decode gzip data.
     * @return string A gzip decode function name, or false if not found
     */
    public static function gz_supported()
    {
        function_exists('zlib_decode') and $_gzdecode = 'zlib_decode' or
            function_exists('gzdecode') and $_gzdecode    = 'gzdecode' or
            $_gzdecode                                    = false;
        return $_gzdecode;
    }

    /**
     * gzdecode() (for PHP < 5.4.0)
     */
    public static function gzdecode($str)
    {
        /**
         * @var mixed
         */
        static $_gzdecode;
        if (!isset($_gzdecode)) {
            $_gzdecode = self::gz_supported();
        }

        return $_gzdecode ? $_gzdecode($str) : self::_gzdecode($str);
    }

    /**
     * Alternative gzdecode() (for PHP < 5.4.0)
     * source: https://github.com/Polycademy/upgradephp/blob/master/upgrade.php
     */
    protected static function _gzdecode($gzdata, $maxlen = null)
    {
        #-- decode header
        $len = strlen($gzdata);
        if ($len < 20) {
            return;
        }
        $head                                   = substr($gzdata, 0, 10);
        $head                                   = unpack('n1id/C1cm/C1flg/V1mtime/C1xfl/C1os', $head);
        list($ID, $CM, $FLG, $MTIME, $XFL, $OS) = array_values($head);
        $FTEXT                                  = 1 << 0;
        $FHCRC                                  = 1 << 1;
        $FEXTRA                                 = 1 << 2;
        $FNAME                                  = 1 << 3;
        $FCOMMENT                               = 1 << 4;
        $head                                   = unpack('V1crc/V1isize', substr($gzdata, $len - 8, 8));
        list($CRC32, $ISIZE)                    = array_values($head);

        #-- check gzip stream identifier
        if (0x1f8b != $ID) {
            trigger_error('gzdecode: not in gzip format', E_USER_WARNING);
            return;
        }
        #-- check for deflate algorithm
        if (8 != $CM) {
            trigger_error('gzdecode: cannot decode anything but deflated streams', E_USER_WARNING);
            return;
        }
        #-- start of data, skip bonus fields
        $s = 10;
        if ($FLG & $FEXTRA) {
            $s += $XFL;
        }
        if ($FLG & $FNAME) {
            $s = strpos($gzdata, "\000", $s) + 1;
        }
        if ($FLG & $FCOMMENT) {
            $s = strpos($gzdata, "\000", $s) + 1;
        }
        if ($FLG & $FHCRC) {
            $s += 2; // cannot check
        }

        #-- get data, uncompress
        $gzdata = substr($gzdata, $s, $len - $s);
        if ($maxlen) {
            $gzdata = gzinflate($gzdata, $maxlen);
            return ($gzdata); // no checks(?!)
        } else {
            $gzdata = gzinflate($gzdata);
        }

        #-- check+fin
        $chk = crc32($gzdata);
        if ($CRC32 != $chk) {
            trigger_error("gzdecode: checksum failed (real$chk != comp$CRC32)", E_USER_WARNING);
        } elseif (strlen($gzdata) != $ISIZE) {
            trigger_error('gzdecode: stream size mismatch', E_USER_WARNING);
        } else {
            return ($gzdata);
        }
    }
}
