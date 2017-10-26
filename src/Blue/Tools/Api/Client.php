<?php
namespace Blue\Tools\Api;

use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use GuzzleHttp\Client as GuzzleClient;

class Client
{

    //--------------------
    // Constants
    //--------------------

    /** @var int */
    public static $VERSION = 2;

    /** @var string */
    public static $AUTH_TYPE = 'bsdtools_v2';

    //--------------------
    // Credentials
    //--------------------

    /** @var string */
    private $id;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $secret;

    //--------------------
    // Configuration
    //--------------------

    /** @var int */
    private $deferredResultMaxAttempts = 20;

    /** @var int */
    private $deferredResultInterval = 5;

    //--------------------
    // Other internals
    //--------------------

    /** @var LoggerInterface */
    private $logger;

    /** @var GuzzleClient */
    private $guzzleClient;


    /**
     * @param string $id
     * @param string $secret
     * @param string $url
     */
    public function __construct($id, $secret, $url, callable $handler = null)
    {
        $this->logger = new NullLogger();

        if (!strlen($id) || !strlen($secret)) {
            throw new InvalidArgumentException('api_id and api_secret must both be provided');
        }

        $validatedUrl = filter_var($url, FILTER_VALIDATE_URL);
        if (!$validatedUrl) {
            throw new InvalidArgumentException($url . ' is not a valid URL');
        }

        $this->id = $id;
        $this->secret = $secret;
        $this->baseUrl = $validatedUrl . '/page/api/';

        $handlerStack = HandlerStack::create();

        if($handler){
            $handlerStack->setHandler($handler);
        }

        $handlerStack->unshift(Middleware::mapRequest(
            function (RequestInterface $request) {

                $uri = Uri::withQueryValue($request->getUri(), 'api_id', $this->id);
                $uri = Uri::withQueryValue($uri, 'api_ts', time());
                $uri = Uri::withQueryValue($uri, 'api_ver', 2);
                parse_str($uri->getQuery(), $query);
                $hMac = $this->generateMac($uri, $query, $this->secret);
                $uri = Uri::withQueryValue($uri, 'api_mac', $hMac);
                return $request->withUri($uri);
            })
        );

        $this->guzzleClient = new GuzzleClient(['handler' => $handlerStack]);
    }


    /**
     * Execute a GET request against the API
     *
     * @param string $apiPath
     * @param array $queryParams
     * @return ResponseInterface
     */
    public function get($apiPath, $queryParams = [])
    {
        $response = $this->guzzleClient->get(
            $this->baseUrl . $apiPath,
            [
                'query' => $queryParams,
                'future' => false,
                'auth' => [
                    $this->id,
                    $this->secret,
                    self::$AUTH_TYPE
                ],
            ]
        );

        return $this->resolve($response);
    }


    /**
     * Execute a POST request against the API
     *
     * @param $apiPath
     * @param array $queryParams
     * @param string $data
     * @return ResponseInterface
     */
    public function post($apiPath, $queryParams = [], $data = '')
    {

        $response = $this->guzzleClient->post(
            $this->baseUrl . $apiPath,
            [
                'query' => $queryParams,
                'body' => $data,
                'future' => false,
                'auth' => [
                    $this->id,
                    $this->secret,
                    self::$AUTH_TYPE
                ],
            ]
        );

        return $this->resolve($response);
    }


    /**
     * @param ResponseInterface $response
     * @return FutureResponse|Response|\GuzzleHttp\Message\ResponseInterface|\GuzzleHttp\Ring\Future\FutureInterface|null
     */
    private function resolve(Response $response)
    {

        // An HTTP status of 202 indicates that this request was deferred
        if ($response->getStatusCode() == 202) {

            $key = $response->getBody()->getContents();

            $attempts = $this->deferredResultMaxAttempts;

            while ($attempts > 0) {
                /** @var ResponseInterface $deferredResponse */
                $deferredResponse = $this->guzzleClient->get(
                    $this->baseUrl . "get_deferred_results",
                    [
                        'auth' => [
                            $this->id,
                            $this->secret,
                            self::$AUTH_TYPE
                        ],
                        'future' => false,
                        'query' => [
                            'deferred_id' => $key
                        ]
                    ]
                );

                if ($deferredResponse->getStatusCode() != 202) {
                    return $deferredResponse;
                }

                sleep($this->deferredResultInterval);
                $attempts--;
            }

            throw new RuntimeException("Could not load deferred response after {$this->deferredResultMaxAttempts} attempts");
        }

        // If the request was not deferred, then return as-is
        return $response;
    }


    /**
     * @param int $deferredResultMaxAttempts
     */
    public function setDeferredResultMaxAttempts($deferredResultMaxAttempts)
    {
        $this->deferredResultMaxAttempts = $deferredResultMaxAttempts;
    }


    /**
     * @param int $deferredResultInterval
     */
    public function setDeferredResultInterval($deferredResultInterval)
    {
        $this->deferredResultInterval = $deferredResultInterval;
    }


    /**
     * @return GuzzleClient
     */
    public function getGuzzleClient()
    {
        return $this->guzzleClient;
    }


    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Returns the specified request option or all options if none specified
     * @param null $keyOrPath
     * @return array|mixed|null
     */
    public function getRequestOption($keyOrPath = null)
    {
        return $this->guzzleClient->getDefaultOption($keyOrPath);
    }

    /**
     * Sets a request option for future requests
     * @param $keyOrPath
     * @param $value
     * @return $this
     */
    public function setRequestOption($keyOrPath, $value)
    {
        $this->guzzleClient->setDefaultOption($keyOrPath, $value);
        return $this;
    }

    /**
     * Creates a hash based on request parameters
     *
     * @param string $url
     * @param array $query
     * @param string $secret
     * @return string
     */
    private function generateMac($url, $query, $secret)
    {

        // break URL into parts to get the path
        $urlParts = parse_url($url);

        // trim double slashes in the path
        if (substr($urlParts['path'], 0, 2) == '//') {
            $urlParts['path'] = substr($urlParts['path'], 1);
        }

        // combine strings to build the signing string
        $signingString = $query['api_id'] . "\n" .
            $query['api_ts'] . "\n" .
            $urlParts['path'] . "\n" .
            urldecode(http_build_query($query));

        return hash_hmac('sha1', $signingString, $secret);
    }

}
