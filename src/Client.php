<?php

namespace Deathkel\JsonRPC;

use Exception;
use BadFunctionCallException;
use InvalidArgumentException;
use RuntimeException;
use Log;

class ConnectionFailureException extends Exception {};
class ServerErrorException extends Exception {};

/**
 * json-rpc client class
 *
 * @package json-rpc
 * @author  Frederic Guillot
 */
class Client
{
    /**
     * curl instance
     */
    protected $curl;
    /**
     * URL of the server
     * URL=HOST+APP
     * like http://bs.iyz.com/test/s?app=Coupon
     * @access private
     * @var string
     */
    protected $url;


    protected $payload;
    /**
     * If the only argument passed to a function is an array
     * assume it contains named arguments
     *
     * @access public
     * @var boolean
     */
    public $named_arguments = true;

    /**
     * HTTP client timeout
     *
     * @access private
     * @var integer
     */
    private $timeout;

    /**
     * Username for authentication
     *
     * @access private
     * @var string
     */
    private $username;

    /**
     * Password for authentication
     *
     * @access private
     * @var string
     */
    private $password;

    /**
     * True for a batch request
     *
     * @access public
     * @var boolean
     */
    public $is_batch = false;

    /**
     * Batch payload
     *
     * @access public
     * @var array
     */
    public $batch = array();

    /**
     * Enable debug output to the php error log
     *
     * @access public
     * @var boolean
     */
    public $debug = false;

    /**
     * Default HTTP headers to send to the server
     *
     * @access private
     * @var array
     */
    private $headers = array(
        'User-Agent: JSON-RPC PHP Client',
        'Content-Type: application/json',
        'Accept: application/json',
        'Connection: close',
    );

    /**
     * SSL certificates verification
     *
     * @access public
     * @var boolean
     */
    public $ssl_verify_peer = true;

    /**
     * Constructor
     *
     * @access public
     * @param  string    $app         Server APP
     * @param  integer   $timeout     HTTP timeout
     * @param  array     $headers     Custom HTTP headers
     */
    public function __construct($url=null, $timeout = 3, $headers = array())
    {
        if($url){
            $this->url=$url;
        }
        $this->timeout = $timeout;
        $this->headers = array_merge($this->headers, $headers);
    }

    /**
     * Automatic mapping of procedures
     *
     * @access public
     * @param  string   $method   Procedure name
     * @param  array    $params   Procedure arguments
     * @return mixed
     */
    public function __call($method, array $params)
    {
        // Allow to pass an array and use named arguments
        if ($this->named_arguments && count($params) === 1 && is_array($params[0])) {
            $params = $params[0];
        }

        return $this->execute($method, $params);
    }

    public function setPayload($payload)
    {
        $this->payload = $payload;
    }
    /**
     * Set authentication parameters
     *
     * @access public
     * @param  string   $username   Username
     * @param  string   $password   Password
     * @return Client
     */
    public function authentication($username, $password)
    {
        $this->username = $username;
        $this->password = $password;

        return $this;
    }

    /**
     * Start a batch request 批量请求
     *
     * @access public
     * @return Client
     */
    public function batch()
    {
        $this->is_batch = true;
        $this->batch = array();

        return $this;
    }

    /**
     * Send a batch request 发送一个批量请求
     *
     * @access public
     * @return array
     */
    public function send()
    {
        $this->is_batch = false;

        return $this->parseResponse(
            $this->doRequest($this->batch)
        );
    }

    /**
     * Execute a procedure 执行一个程序
     *
     * @access public
     * @param  string   $procedure   Procedure name
     * @param  array    $params      Procedure arguments
     * @return mixed
     */
    public function execute($procedure, array $params = array())
    {
        if ($this->is_batch) {
            $this->batch[] = $this->prepareRequest($procedure, $params);
            return $this;
        }

        return $this->parseResponse(
            $this->doRequest($this->prepareRequest($procedure, $params))
        );
    }

    /**
     * Prepare the payload
     *
     * @access public
     * @param  string   $procedure   Procedure name
     * @param  array    $params      Procedure arguments
     * @return array
     */
    public function prepareRequest($procedure, array $params = array())
    {
        $payload = array(
            'jsonrpc' => '2.0',
            'method' => $procedure,
            'id' => mt_rand()
        );
        if(isset($this->payload)){
            $payload = array_merge($payload,$this->payload);
        }

        if (! empty($params)) {
            $payload['params'] = $params;
        }
        return $payload;
    }

    /**
     * Parse the response and return the procedure result
     *
     * @access public
     * @param  array     $payload
     * @return mixed
     */
    public function parseResponse(array $payload)
    {
        if ($this->isBatchResponse($payload)) {
//            dump('isBatchResponse');
            $results = array();

            foreach ($payload as $response) {
                $results[] = $this->getResult($response);
            }

            return $results;
        }
//        dump('isSingleRespone');
        return $this->getResult($payload);
    }

    /**
     * Throw an exception according the RPC error
     *
     * @access public
     * @param  array   $error
     * @throws BadFunctionCallException
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws ResponseException
     */
    public function handleRpcErrors(array $error)
    {
        switch ($error['code']) {
            case -32700:
                throw new RuntimeException('Parse error: '. $error['message']);
            case -32600:
                throw new RuntimeException('Invalid Request: '. $error['message']);
            case -32601:
                throw new BadFunctionCallException('Procedure not found: '. $error['message']);
            case -32602:
                throw new InvalidArgumentException('Invalid arguments: '. $error['message']);
            default:
                throw new ResponseException(
                    $error['message'],
                    $error['code'],
                    null,
                    isset($error['data']) ? $error['data'] : null
                );
        }
    }

    /**
     * Throw an exception according the HTTP response
     *
     * @access public
     * @param  array   $headers
     * @throws AccessDeniedException
     * @throws ServerErrorException
     */
    public function handleHttpErrors(array $headers)
    {
        $exceptions = array(
            '401' => 'json-rpc\AccessDeniedException',
            '403' => 'json-rpc\AccessDeniedException',
            '404' => 'json-rpc\ConnectionFailureException',
            '500' => 'json-rpc\ServerErrorException',
        );

        foreach ($headers as $header) {
            foreach ($exceptions as $code => $exception) {
                if (strpos($header, 'HTTP/1.0 '.$code) !== false || strpos($header, 'HTTP/1.1 '.$code) !== false) {
                    throw new $exception('Response: '.$header);
                }
            }
        }
    }

    /**
     * Do the HTTP request
     *
     * @access private
     * @param  array   $payload
     * @return array
     */
    private function doRequest(array $payload)
    {
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_POST, true); //Post query false
        curl_setopt($this->curl, CURLOPT_URL,$this->url); //Url for get method
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);  // Return transfer as string
//        curl_setopt($this->curl, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $this->getHeaders($payload));
        curl_setopt($this->curl,CURLOPT_POSTFIELDS,json_encode($payload));//set json content
        $response = curl_exec($this->curl);
        if (false === $response) {
            throw new ConnectionFailureException('Unable to establish a connection');
        }
        $response = json_decode($response, true);
        curl_close($this->curl);
        if ($this->debug) {
            Log::DEBUG('==> Request: '.PHP_EOL.json_encode($payload, JSON_PRETTY_PRINT));
            Log::DEBUG('==> Response: '.PHP_EOL.json_encode($response, JSON_PRETTY_PRINT));
            dump(['==> Request: ' => $payload]);
            dump(['==> Response: ' => $response]);
        }
        return is_array($response) ? $response : array();
    }

    //TODO delete
    private function doRequest_old(array $payload)
    {
        $stream = @fopen(trim($this->url), 'r', false, $this->getContext($payload));

        if (! is_resource($stream)) {
            throw new ConnectionFailureException('Unable to establish a connection');
        }

        $metadata = stream_get_meta_data($stream);
        $this->handleHttpErrors($metadata['wrapper_data']);

        $response = json_decode(stream_get_contents($stream), true);

        if ($this->debug) {
            error_log('==> Request: '.PHP_EOL.json_encode($payload, JSON_PRETTY_PRINT));
            error_log('==> Response: '.PHP_EOL.json_encode($response, JSON_PRETTY_PRINT));
        }

        return is_array($response) ? $response : array();
    }

    /**
     * prepare HTTP header
     *
     * @param array $payload
     * @return array
     */
    private function getHeaders(array $payload){
        $headers = $this->headers;

        if (! empty($this->username) && ! empty($this->password)) {
            $headers[] = 'Authorization: Basic '.base64_encode($this->username.':'.$this->password);
        }
        return $headers;
    }
    /**
     * Return true if we have a batch response
     *
     * @access public
     * @param  array    $payload
     * @return boolean
     */
    private function isBatchResponse(array $payload)
    {
        return array_keys($payload) === range(0, count($payload) - 1);
    }

    /**
     * Get a RPC call result
     *
     * @access private
     * @param  array    $payload
     * @return mixed
     */
//    private function getResult(array $payload)
//    {
//        if (isset($payload['error']['code'])) {
//            $this->handleRpcErrors($payload['error']);
//        }
//
//        return isset($payload['result']) ? $payload['result'] : null;
//    }
    private function getResult(array $payload)
    {
        if (isset($payload['error']['code'])) {
            $this->handleRpcErrors($payload['error']);
        }

        return isset($payload['result']) ? $payload['result'] : null;
    }
}
