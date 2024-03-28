<?php

namespace Jsor\HalClient;

use function count;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

class RecordingHttpClient implements \Psr\Http\Client\ClientInterface
{
    public $requests = [];

    public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $this->requests[] = $request;

        return new Response(200, ['Content-Type' => 'application/hal+json']);
    }

    /**
     * @return RequestInterface
     */
    public function getLastRequest()
    {
        return $this->requests[count($this->requests) - 1];
    }
}
