<?php
namespace Garveen\FastCgi;

use Psr\Http\Message\ResponseInterface;
use Exception;

class FastCgi
{

    const TIMEOUT = 180;

    const FCGI_BEGIN_REQUEST = 1;
    const FCGI_ABORT_REQUEST = 2;
    const FCGI_END_REQUEST = 3;
    const FCGI_PARAMS = 4;
    const FCGI_STDIN = 5;
    const FCGI_STDOUT = 6;
    const FCGI_STDERR = 7;
    const FCGI_DATA = 8;
    const FCGI_GET_VALUES = 9;
    const FCGI_GET_VALUES_RESULT = 10;
    const FCGI_UNKNOWN_TYPE = 11;

    const FCGI_RESPONDER = 1;
    const FCGI_AUTHORIZER = 2;
    const FCGI_FILTER = 3;

    const FCGI_KEEP_CONN = 1;

    const STATE_HEADER = 0;
    const STATE_BODY = 1;
    const STATE_PADDING = 2;

    protected $connections = [];

    protected $logger;

    protected $requestCallback;
    protected $sendCallback;
    protected $closeCallback;

    public function __construct(callable $requestCallback, callable $sendCallback, callable $closeCallback, $logger = null)
    {
        $this->requestCallback = $requestCallback;
        $this->sendCallback = $sendCallback;
        $this->closeCallback = $closeCallback;
        $this->logger = $logger;
    }

    public function callback($name, callable $callback)
    {
        $this->{"{$name}Callback"} = $callback;
    }

    public function logger($logger)
    {
        $this->logger = $logger;
    }

    public function receive($fd, $data)
    {
        if (!isset($this->connections[$fd]['buff'])) {
            $this->connections[$fd]['buff'] = '';
        } else {
            $data = $this->connections[$fd]['buff'] . $data;
        }
        if (!isset($this->connections[$fd]['length'])) {
            $pack = substr($data, 4, 3);
            $info = unpack('ncontentLength/CpaddingLength', $pack);
            $this->connections[$fd]['length'] = 8 + $info['contentLength'] + $info['paddingLength'];
        }

        if ($this->connections[$fd]['length'] <= strlen($data)) {
            $result = $this->parseRecord($data);

            $this->connections[$fd]['buff'] = $result['remainder'];
            $this->connections[$fd]['length'] = null;
        } else {
            $this->connections[$fd]['buff'] = $data;
            return;
        }

        if (count($result['records']) == 0) {
            $this->log('error', "Bad Request. data length: " . strlen($data));
            $this->closeConnection($fd);
            return;
        }
        foreach ($result['records'] as $record) {
            $requestId = $record['requestId'];
            $type = $record['type'];

            if ($type == static::FCGI_BEGIN_REQUEST) {
                $request = new FastCgiRequest($requestId, $fd);
                $request->id = $requestId;
                $u = unpack('nrole/Cflags', $record['contentData']);
                if ($u['role'] > 3) {
                    $this->log('error', "Bad Request. Role: " . $u['role']);
                    $this->closeConnection($fd);
                    return;
                }
                $request->role = $u['role'];
                $request->flags = $u['flags'];
                $this->connections[$fd]['requests'][$requestId] = $request;
            } elseif (!isset($this->connections[$fd]['requests'][$requestId])) {
                $this->log('error', "Unexpected FastCGI record. fd: {$fd} requestId: {$requestId}");
                return;
            } else {
                $request = $this->connections[$fd]['requests'][$requestId];
            }

            if ($type == static::FCGI_ABORT_REQUEST) {
                $this->closeConnection($fd);
            } elseif ($type == static::FCGI_PARAMS) {
                if (!$record['contentLength']) {

                    $request->finishParams();
                } else {
                    $p = 0;
                    while ($p < $record['contentLength']) {
                        if (($nameLength = ord($record['contentData'][$p])) < 128) {
                            $p++;
                        } else {
                            $u = unpack('N', substr($record['contentData'], $p, 4));
                            $nameLength = $u[1] & 0x7FFFFFFF;
                            $p += 4;
                        }

                        if (($valueLength = ord($record['contentData'][$p])) < 128) {
                            $p++;
                        } else {
                            $u = unpack('N', substr($record['contentData'], $p, 4));
                            $valueLength = $u[1] & 0x7FFFFFFF;
                            $p += 4;
                        }

                        $request->serverParams[substr($record['contentData'], $p, $nameLength)] = substr($record['contentData'], $p + $nameLength, $valueLength);
                        $p += $nameLength + $valueLength;
                    }
                }
            } elseif ($type == static::FCGI_STDIN) {
                if ($record['contentLength']) {
                    $request->setRawContent($record['contentData']);
                    continue;
                } else {
                    $request->finishRawContent();
                }
            }

            if ($request->isReady()) {

                $request->parse();

                $method = isset($request->serverParams['REQUEST_METHOD']) ? $request->serverParams['REQUEST_METHOD'] : 'GET';
                $uri = isset($request->serverParams['REQUEST_URI']) ? $request->serverParams['REQUEST_URI'] : '/';
                $version = isset($request->serverParams['SERVER_PROTOCOL']) ? $request->serverParams['SERVER_PROTOCOL'] : 'HTTP/1.0';
                $version = substr($version, 5) ?: '1.0';
                $body = $request->getBody();
                $serverParams = $request->serverParams;
                $this->log('info', "[{$method}] {$uri} (#{$requestId})");

                try {

                    $psrRequest = new ServerRequest(
                        $method,
                        $uri,
                        $request->headers,
                        $body,
                        $version,
                        $serverParams
                    );
                    $psrRequest->cookieParams = $request->cookieParams;
                    $psrRequest->parsedBody = $request->parsedBody;
                    $psrRequest->queryParams = $request->queryParams;
                    $psrRequest->setUploadedFiles($request->uploadedFiles);
                    // php 5.4
                    $requestCallback = $this->requestCallback;
                    $response = $requestCallback($psrRequest);
                    $this->response($request, $response);

                } catch (Exception $e) {
                    $this->log('error', $e->getTraceAsString());
                }
            }

        }
    }

    protected function log($level, $info)
    {
        if ($this->logger) {
            $info = date('Y-m-d H:i:s e') . $info . "\n";
            if (is_callable($this->logger)) {
                call_user_func_array($this->logger, [$level, $info]);
            } else {
                $this->logger->$level($info);
            }
        }
    }

    protected function parseRecord($data)
    {
        $records = array();
        while ($len = strlen($data)) {
            if ($len < 8) {
                /**
                 * We don't have a full header
                 */
                break;
            }
            $header = substr($data, 0, 8);
            $record = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $header);
            $recordlength = 8 + $record['contentLength'] + $record['paddingLength'];
            $record['contentData'] = substr($data, 8, $record['contentLength']);

            if ($len < $recordlength) {
                /**
                 * We don't have a full record.
                 */
                break;
            }
            $records[] = $record;
            $data = substr($data, $recordlength);
        }
        return array('records' => $records, 'remainder' => $data);
    }

    /**
     * Handles the output from downstream requests.
     * @param object Request.
     * @param object Response.
     * @return boolean Success
     */
    protected function response($request, ResponseInterface $response)
    {
        $header = Response::getHeaderOutput($response);
        $headerLength = $length = strlen($header);
        $body = $response->getBody();
        if ($bodySize = $body->getSize() !== null) {
            $length += $bodySize;
        }

        $chunksize = 65520;
        do {
            for ($p = 0; $p + $chunksize < $headerLength; $p += $chunksize) {
                if ($this->sendChunk($request, substr($header, $p, $chunksize)) === false) {
                    $this->log('warning', "send response failed.");
                    break 2;
                }
            }
            $remainder = substr($header, $p);
            try {
                $body->rewind();
                $this->sendChunk($request, $remainder . $body->read($chunksize - strlen($remainder)));
                while (!$body->eof()) {
                    $this->sendChunk($request, $body->read($chunksize));
                }

            } catch (\RuntimeException $e) {
                $this->log('error', $e->getTraceAsString());
                break;
            }

        } while (false);

        $this->endRequest($request, 0, 0);

        return true;
    }

    /**
     * Sends a chunk
     * @param $request
     * @param $chunk
     * @return bool
     */
    protected function sendChunk($request, $chunk)
    {
        $paddingLength = 8 - strlen($chunk) % 8;
        $payload = "\x01" // protocol version
         . "\x06" // record type (STDOUT)
         . pack('nnC', $request->id, strlen($chunk), $paddingLength) // id, content length, padding length
         . "\x00" // reserved
         . $chunk // content
         . str_repeat("\0", $paddingLength);

        return $this->send($request->fd, $payload);
    }

    protected function send($fd, $payload)
    {
        $sendCallback = $this->sendCallback;
        $sendCallback($fd, $payload);
    }

    /**
     * Handles the output from downstream requests.
     * @param $request
     * @param $appStatus
     * @param $protoStatus
     * @return void
     */
    protected function endRequest($request, $appStatus = 0, $protoStatus = 0)
    {
        $content = pack('NC', $appStatus, $protoStatus) // app status, protocol status
         . "\x00\x00\x00";
        $paddingLength = 8 - strlen($content) % 8;

        $payload = "\x01" // protocol version
         . "\x03" // record type (END_REQUEST)
         . pack('nnC', $request->id, strlen($content), $paddingLength) // id, content length, padding length
         . "\x00" // reserved
         . $content // content
         . str_repeat("\0", $paddingLength);

        $this->send($request->fd, $payload);
        $request->destoryTempFiles();
        unset($this->connections[$request->fd]['request'][$request->id]);

        if ($protoStatus === -1 || !($request->flags & static::FCGI_KEEP_CONN)) {
            $this->closeConnection($request->fd);
        }
    }

    protected function closeConnection($fd)
    {
        if (isset($this->connections[$fd]['request'])) {
            foreach ($this->connections[$fd]['requests'] as $request) {
                $request->destoryTempFiles();
            }
        }
        $closeCallback = $this->closeCallback;
        $closeCallback($fd);
        unset($this->connections[$fd]);

    }

}
