# README

This library is an effective `FastCGI` to [`PSR-7`](http://www.php-fig.org/psr/psr-7/) adapter.

# Install

```bash
composer require garveen/fastcgi
```

# Usage

```php
use Garveen\FastCgi\FastCgi;
use Psr\Http\Message\ServerRequestInterface;

// First of all, define 3 callbacks

// When a request is ready, this library will call $requestCallback:
$requestCallback = function (ServerRequestInterface $serverRequest) {
	// Do something...
	// And the response must be instance of Psr\Http\Message\ResponseInterface
	// This library provides Garveen\FastCgi\Response
	return new Response;
};

// After this library got the response, $sendCallback will be called:
$sendCallback = function (int $fd, string $data) {
	// send $data to downstream
	fwrite($downstreams[$fd], $data);
};

// At the end, if keepalive is not set, there will be $closeCallback:
$closeCallback = function (int $fd) {
	fclose($downstreams[$fd]);
};

// The instance
$fastcgi = new FastCgi($requestCallback, $sendCallback, $closeCallback, $logger);

// Once you have recevied a FastCGI network-package, just pass it to the instance:
$fastcgi->receive(int $fd, string $data);
```

