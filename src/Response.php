<?php

namespace Garveen\FastCgi;

use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response as GuzzleResponse;

class Response extends GuzzleResponse implements ResponseInterface
{
    public $cookies = [];

    public function __construct(
        $status = 200,
        array $headers = [],
        $body = null,
        $version = '1.1',
        $reason = null
    ) {
        parent::__construct($status, $headers, $body, $version, $reason);
    }

    /**
     * @param $name
     * @param null $value
     * @param null $expire
     * @param string $path
     * @param null $domain
     * @param null $secure
     * @param null $httponly
     */
    public function setRawCookie($name, $value = null, $expire = null, $path = '/', $domain = null, $secure = null, $httponly = null)
    {
        $name = urlencode($name);
        $this->cookies[] = compact(['name', 'value', 'expire', 'path', 'domain', 'secure', 'httponly']);
    }

    public function setCookie($name, $value = null, $expire = null, $path = '/', $domain = null, $secure = null, $httponly = null)
    {
        $name = urlencode($name);
        $value = urlencode($value);
        $this->cookies[] = compact(['name', 'value', 'expire', 'path', 'domain', 'secure', 'httponly']);
    }

    public function getHeaders()
    {
        $headers = parent::getHeaders();
        $cookies = [];
        foreach ($this->cookies as $cookie) {

            if ($cookie['value'] == null) {
                $cookie['value'] = 'deleted';
            }
            $value = "{$cookie['name']}={$cookie['value']}";
            if ($cookie['expire']) {
                $value .= "; expires=" . date("D, d-M-Y H:i:s T", $cookie['expire']);
            }
            if ($cookie['path']) {
                $value .= "; path={$cookie['path']}";
            }
            if ($cookie['secure']) {
                $value .= "; secure";
            }
            if ($cookie['domain']) {
                $value .= "; domain={$cookie['domain']}";
            }
            if ($cookie['httponly']) {
                $value .= '; httponly';
            }
            $cookies[] = $value;
        }
        if ($cookies) {
            $headers['Set-Cookie'] = $cookies;
        }

        return $headers;
    }

    public static function getHeaderOutput(ResponseInterface $instance)
    {
        $out = 'Status: ' . $instance->getStatusCode() . ' ' . $instance->getReasonPhrase() . "\r\n";

        $headers = $instance->getHeaders();
        if (!isset($headers['Content-Length'])) {
            if ($size = $instance->getBody()->getSize()) {
                $headers['Content-Length'] = [$size];
            }
        }

        //Headers
        foreach ($headers as $key => $values) {
            foreach ($values as $value) {
                $out .= $key . ': ' . $value . "\r\n";
            }
        }
        //End
        $out .= "\r\n";
        return $out;
    }
}
