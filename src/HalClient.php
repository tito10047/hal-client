<?php

namespace Jsor\HalClient;

use GuzzleHttp\Psr7 as GuzzlePsr7;
use GuzzleHttp\Psr7\Utils;

use function is_array;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\HttplugClient;
use Throwable;

final class HalClient implements HalClientInterface
{
    private \Psr\Http\Client\ClientInterface $httpClient;
    private Internal\HalResourceFactory $factory;

    private static $validContentTypes = [
        'application/hal+json',
        'application/json',
        'application/vnd.error+json',
    ];
    private RequestInterface $defaultRequest;

    public function __construct($rootUrl, \Psr\Http\Client\ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?: self::createDefaultHttpClient();

        $this->factory = new Internal\HalResourceFactory(self::$validContentTypes);

        $this->defaultRequest = new GuzzlePsr7\Request('GET', $rootUrl, [
            'User-Agent' => static::class,
            'Accept'     => implode(', ', self::$validContentTypes),
        ]);
    }

    public function __clone()
    {
        $this->httpClient     = clone $this->httpClient;
        $this->defaultRequest = clone $this->defaultRequest;
    }

    public function getRootUrl(): UriInterface
    {
        return $this->defaultRequest->getUri();
    }

    public function withRootUrl($rootUrl): HalClientInterface
    {
        $instance = clone $this;

        $instance->defaultRequest = $instance->defaultRequest->withUri(
            new GuzzlePsr7\Uri($rootUrl)
        );

        return $instance;
    }

    public function getHeader($name): array
    {
        return $this->defaultRequest->getHeader($name);
    }

    public function withHeader($name, $value): HalClientInterface
    {
        $instance = clone $this;

        $instance->defaultRequest = $instance->defaultRequest->withHeader(
            $name,
            $value
        );

        return $instance;
    }

    public function root(array $options = [])
    {
        return $this->request('GET', '', $options);
    }

    public function get($uri, array $options = [])
    {
        return $this->request('GET', $uri, $options);
    }

    public function post($uri, array $options = [])
    {
        return $this->request('POST', $uri, $options);
    }

    public function put($uri, array $options = [])
    {
        return $this->request('PUT', $uri, $options);
    }

    public function delete($uri, array $options = [])
    {
        return $this->request('DELETE', $uri, $options);
    }

    public function request(
        $method,
        $uri,
        array $options = []
    ) {
        $request = $this->createRequest($method, $uri, $options);

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (Throwable $e) {
            throw Exception\HttpClientException::create($request, $e);
        }

        return $this->handleResponse($request, $response, $options);
    }

    public function createRequest(
        $method,
        $uri,
        array $options = []
    ) {
        /** @var \Psr\Http\Message\RequestInterface $request */
        $request = clone $this->defaultRequest;

        $request = $request->withMethod($method);

        $request = $request->withUri(
            self::resolveUri($request->getUri(), $uri)
        );

        $request = $this->applyOptions($request, $options);

        return $request;
    }

    private function applyOptions(RequestInterface $request, array $options)
    {
        if (isset($options['version'])) {
            $request = $request->withProtocolVersion($options['version']);
        }

        if (isset($options['query'])) {
            $request = $this->applyQuery($request, $options['query']);
        }

        if (isset($options['headers'])) {
            foreach ($options['headers'] as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
        }

        if (isset($options['body'])) {
            $request = $this->applyBody($request, $options['body']);
        }

        return $request;
    }

    private function applyQuery(RequestInterface $request, string|array $query)
    {
        $uri = $request->getUri();

        if (!is_array($query)) {
            parse_str($query, $query2);
            $query = $query2;
        }

        parse_str($uri->getQuery(), $query3);
        $newQuery = array_merge(
            $query3,
            $query
        );

        return $request->withUri(
            $uri->withQuery(http_build_query($newQuery, null, '&'))
        );
    }

    private function applyBody(RequestInterface $request, $body)
    {
        if (is_array($body)) {
            $body = json_encode($body);

            if (!$request->hasHeader('Content-Type')) {
                $request = $request->withHeader(
                    'Content-Type',
                    'application/json'
                );
            }
        }

        return $request->withBody(Utils::streamFor($body));
    }

    private function handleResponse(
        RequestInterface $request,
        ResponseInterface $response,
        array $options
    ) {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            if (
                isset($options['return_raw_response']) &&
                true === $options['return_raw_response']
            ) {
                return $response;
            }

            return $this->factory->createResource($this, $request, $response);
        }

        throw Exception\BadResponseException::create(
            $request,
            $response,
            $this->factory->createResource($this, $request, $response, true)
        );
    }

    private static function createDefaultHttpClient(): \Psr\Http\Client\ClientInterface
    {
        // @codeCoverageIgnoreStart
        if (!class_exists('Symfony\Component\HttpClient\HttpClient')) {
            throw new RuntimeException(
                'Cannot create default HttpClient because symfony/http-client is not installed.' .
                'Install with `composer require symfony/http-client`.'
            );
        }
        $client = HttpClient::create();
        $client = new HttplugClient($client);

        return $client;
    }

    private static function resolveUri($base, $rel)
    {
        static $resolver, $castRel;

        if (!$resolver) {
            if (class_exists('GuzzleHttp\Psr7\UriResolver')) {
                $resolver = ['GuzzleHttp\Psr7\UriResolver', 'resolve'];
                $castRel  = true;
            } else {
                $resolver = ['GuzzleHttp\Psr7\Uri', 'resolve'];
                $castRel  = false;
            }
        }

        if ($castRel && !($rel instanceof UriInterface)) {
            $rel = new GuzzlePsr7\Uri($rel);
        }

        return $resolver($base, $rel);
    }
}
