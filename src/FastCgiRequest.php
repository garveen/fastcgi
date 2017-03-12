<?php

namespace Garveen\FastCgi;

class FastCgiRequest
{
    public $id;
    public $fd;
    public $headers = [];
    public $serverParams = [];
    public $uploadedFiles = [];
    public $queryParams = [];
    public $cookieParams = [];
    public $parsedBody = [];

    protected $inputReady = false;
    protected $paramsReady = false;

    protected $fp = null;

    protected $rawContent = '';
    protected $tmpFile;

    protected $post_len = 0;
    protected $post_max_size = 0;
    protected $tmpDir = 0;

    public function __construct($id, $fd)
    {
        $this->id = $id;
        $this->fd = $fd;
        $this->post_max_size = $this->return_bytes(ini_get('post_max_size'));

    }
    public function __destruct()
    {
        $this->destoryTempFiles();
    }

    public function finishParams()
    {
        if (!isset($this->serverParams['REQUEST_TIME'])) {
            $this->serverParams['REQUEST_TIME'] = time();
        }
        if (!isset($this->serverParams['REQUEST_TIME_FLOAT'])) {
            $req->serverParams['REQUEST_TIME_FLOAT'] = microtime(true);
        }
        $this->paramsReady = true;
    }

    public function setRawContent($content)
    {
        if ($this->inputReady) {
            return;
        }
        $this->post_len += strlen($content);
        if ($this->post_len > $this->post_max_size) {
            if ($this->fp) {
                fclose($this->fp);
                $this->fp = null;
            }
            $this->inputReady = true;
            return;
        }
        if ($this->fp) {
            fwrite($this->fp, $content);
            return;
        }
        $this->rawContent .= $content;

        // write to file when post > 2M
        if (strlen($this->rawContent) > 2097152) {
            $this->tmpFile = tempnam($this->getTempDir(), 'laravoole_');
            $this->fp = fopen($this->tmpFile, 'w+');
            fwrite($this->fp, $this->rawContent);
        }
    }

    public function finishRawContent()
    {
        $this->inputReady = true;
    }

    public function getRawContent()
    {
        if ($this->tmpFile) {
            return file_get_contents($this->tmpFile);
        } else {
            return $this->rawContent;
        }
    }

    public function getBody()
    {
        if ($this->fp) {
            fseek($this->fp, 0);
            return $this->fp;
        }
        return $this->rawContent;
    }

    public function destoryTempFiles()
    {
        if ($this->fp) {
            fclose($this->fp);
            $this->fp = null;
        }
        if ($this->tmpFile && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
            $this->tmpFile = null;
        }
        foreach ($this->uploadedFiles as $file) {
            if (isset($file['tmp_name'])) {
                $name = $file['tmp_name'];
                if (file_exists($name)) {
                    unlink($name);
                }
            }
        }
    }

    public function isReady()
    {
        return $this->inputReady && $this->paramsReady;
    }

    public function parse()
    {
    	$this->parseHeader();
        $this->parseQueryString();
        $this->parseCookie();
        $this->parseBody();
    }

    public function parseHeader()
    {

    	foreach ($this->serverParams as $k => $v) {
    	    if (strncmp($k, 'HTTP_', 5) === 0) {
    	        $this->headers[strtr(ucwords(strtolower(substr($k, 5)), '_'), '_', '-')] = $v;
    	    }
    	}
    }

    public function parseHeaderLine($headerLines)
    {
        if (is_string($headerLines)) {
            $headerLines = strtr($headerLines, "\r", "");
            $headerLines = explode("\n", $headerLines);
        }
        $header = array();
        foreach ($headerLines as $_h) {
            $_h = trim($_h);
            if (empty($_h)) {
                continue;
            }

            $_r = explode(':', $_h, 2);
            $key = $_r[0];
            $value = isset($_r[1]) ? $_r[1] : '';
            $header[trim($key)] = trim($value);
        }
        return $header;
    }

    public function parseParams($str)
    {
        $params = array();
        $blocks = explode(";", $str);
        foreach ($blocks as $b) {
            $_r = explode("=", $b, 2);
            if (count($_r) == 2) {
                list($key, $value) = $_r;
                $params[trim($key)] = trim($value, "\r\n \t\"");
            } else {
                $params[$_r[0]] = '';
            }
        }
        return $params;
    }

    public function parseQueryString()
    {
        if (!isset($this->serverParams['QUERY_STRING'])) {
            return;
        }
        parse_str($this->serverParams['QUERY_STRING'], $this->queryParams);
    }

    public function parseBody()
    {
        if (!isset($this->headers['Content-Type'])) {
            return;
        }
        if (strpos($this->headers['Content-Type'], 'multipart/form-data') !== false) {
            $cd = strstr($this->headers['Content-Type'], 'boundary');
            if ($cd !== false) {
                $this->parseFormData($cd);
                return;
            }
        }
        if (substr($this->headers['Content-Type'], 0, 33) == 'application/x-www-form-urlencoded') {
            parse_str($this->getRawContent(), $this->parsedBody);
        }
    }

    public function parseCookie()
    {
        if (!isset($this->headers['Cookie'])) {
            return;
        }
        $this->cookieParams = $this->parseParams($this->headers['Cookie']);
        foreach ($this->cookieParams as &$v) {
            $v = urldecode($v);
        }
    }

    public function parseFormData($orig_boundary)
    {
        $orig_boundary = str_replace('boundary=', '', $orig_boundary);

        $boundary_next = "\n--$orig_boundary";

        $boundary_next_len = strlen($boundary_next);

        $boundary = "--$orig_boundary";

        $rawContent = $this->getRawContent();

        $rawContent_len = strlen($rawContent);

        $current = strpos($rawContent, $boundary) + strlen($boundary);

        do {
            $boundary_start = $current;
            if ($boundary_start > $rawContent_len) {
                break;
            }

            $chr = $rawContent[$boundary_start];

            if ($chr == '-' && $rawContent[$boundary_start + 1] == '-') {
                break;
            }

            while ($chr == "\n" || $chr == "\r") {
                $boundary_start++;
                if ($boundary_start > $rawContent_len) {
                    break 2;
                }

                $chr = $rawContent[$boundary_start];
            }

            // $boundary_start pointed at the first column of meta

            $current = $boundary_start;

            do {
                $current++;
                if ($current > $rawContent_len) {
                    break 2;
                }

            } while ($rawContent[$current] != "\n" || ($rawContent[$current + 1] != "\r" && $rawContent[$current] + 1 != "\n"));

            if ($rawContent[$current - 1] == "\r") {
                $len = $current - $boundary_start - 1;
            } else {
                $len = $current - $boundary_start;
            }
            // $current pointed at \n

            $line = substr($rawContent, $boundary_start, $len);

            $head = $this->parseHeaderLine($line);

            $meta = $this->parseParams($head['Content-Disposition']);
            $meta = array_change_key_case($meta);

            do {
                $current++;
                if ($current > $rawContent_len) {
                    break 2;
                }

            } while ($rawContent[$current] != "\n");

            $current++;
            // $current pointed at the beginning of value
            $uploading = isset($meta['filename']);
            if (!$uploading) {

                $boundary_end = strpos($rawContent, $boundary_next, $current);

                if ($rawContent[$boundary_end - 1] == "\r") {
                    $len = $boundary_end - $current - 1;
                } else {
                    $len = $boundary_end - $current;
                }

                $value = substr($rawContent, $current, $len);
                $current = $boundary_end;
                $item = &$this->getVariableRegisterTarget($arr, $meta);
                $item = $value;
                unset($item);

                $this->parsedBody += $arr;

            } else {
                // upload file
                $tempdir = $this->getTempDir();
                $filename = tempnam($tempdir, 'laravoole_upload_');
                $fp = fopen($filename, 'w');
                $file_start = $current;
                $file_status = UPLOAD_ERR_EXTENSION;
                do {
                    $buf = substr($rawContent, $current, 8192);
                    if (!$buf) {
                        break;
                    }
                    $found = strpos($buf, $boundary_next);
                    if ($found !== false) {
                        if ($buf[$found - 1] == "\r") {
                            $len = $found - 1;
                        } else {
                            $len = $found;
                        }
                        $buf = substr($buf, 0, $len);
                        $current += $found;
                        $file_status = UPLOAD_ERR_OK;
                        fwrite($fp, $buf);
                        break;
                    } else {
                        $current += 8192;
                        fwrite($fp, $buf);
                    }
                } while ($found === false);
                fclose($fp);

                $value = [
                    'name' => $meta['filename'],
                    'type' => $head['Content-Type'],
                    'size' => $current - $file_start,
                    'error' => $file_status,
                    'tmp_name' => $filename,
                ];
                $arr = '';
                $item = &$this->getVariableRegisterTarget($arr, $meta);
                $item = $value;
                unset($item);
                $this->uploadedFiles += $arr;

                $item = &$this->getVariableRegisterTarget($arr, $meta);
                $item = $meta['filename'];
                unset($item);
                $this->parsedBody += $arr;
            }

            $current += $boundary_next_len;
        } while (1);

    }


    public function getTempDir()
    {
        if (!$this->tmpDir) {
            $this->tmpDir = ini_get('upload_tmpDir') ?: sys_get_temp_dir();
        }
        return $this->tmpDir;
    }

    public function &getVariableRegisterTarget(&$arr, $meta)
    {
        parse_str($meta['name'], $arr);

        $arr0 = &$arr;
        $i = 0;

        while (is_array($item = &${"arr$i"}[array_keys(${"arr$i"})[0]])) {
            $i++;
            ${"arr$i"} = &$item;
            unset(${'arr' . ($i - 1)});
        }
        unset(${"arr$i"});
        return $item;
    }

    protected function return_bytes($val)
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $val = (float) $val;
        switch ($last) {
            // The 'G' modifier is available since PHP 5.1.0
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }

        return $val;
    }
}
