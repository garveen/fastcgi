<?php

namespace Garveen\FastCgi;

use Psr\Http\Message\ServerRequestInterface;
use GuzzleHttp\Psr7\ServerRequest as GuzzleServerRequest;

class ServerRequest extends GuzzleServerRequest implements ServerRequestInterface
{

    /**
     * @var array
     */
    public $cookieParams = [];

    /**
     * @var null|array|object
     */
    public $parsedBody;

    /**
     * @var array
     */
    public $queryParams = [];

    /**
     * @var array
     */
    protected $uploadedFiles = [];

    public function getCookieParams()
    {
        return $this->cookieParams;
    }

    public function getQueryParams()
    {
        return $this->queryParams;
    }

    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    public function getUploadedFiles()
    {
        return $this->uploadedFiles;
    }

    public function setUploadedFiles($files)
    {
    	return $this->uploadedFiles = static::normalizeFiles($files);
    }
}
