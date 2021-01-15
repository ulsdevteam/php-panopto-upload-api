<?php

namespace PanoptoUpload;

require dirname(dirname(__FILE__)) . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\S3\ObjectUploader;
use Aws\S3\MultipartUploader;
use Aws\Exception\MultipartUploadException;
use GuzzleHttp\Client as HttpClient;

class ClientUnauthorizedException extends \Exception {}
class BadApiCallException extends \Exception {}

// Version 1.0.0

/**
 * Client class responsible for authenticating and creating sessions.
 */
class Client {
    
    /**
     * HttpClient for making REST api calls.
     */
    private $http;

    /**
     * OAuth Bearer token.
     */
    private $token;

    /**
     * Constructs a new Client.
     * @param string $host Base url of the Panopto server.
     */
    function __construct($host) {
        $this->http = new HttpClient(array(
            'base_uri' => $host,
        ));
    }
    
    private function checkResponseStatus(&$response, $expected_status) {
        if ($response->getStatusCode() !== $expected_status) {
            throw new BadApiCallException($response->getBody());
        }
    }

    /**
     * Authenticates using OAuth to obtain a bearer token.
     * @param string $client_id
     * @param string $client_secret
     * @param string $username
     * @param string $password
     * 
     * @throws BadApiCallException If there is an error with the Panopto service.
     */
    public function authenticate($client_id, $client_secret, $username, $password) {
        $response = $this->http->post('/Panopto/oauth2/connect/token', array(
            'auth' => [$client_id, $client_secret],
            'form_params' => array(
                'grant_type' => 'password',
                'username' => strtolower($username),
                'password' => $password,
                'scope' => 'api'
            )
        ));
        $this->checkResponseStatus($response, 200);
        $response_body = json_decode($response->getBody(), true);
        $this->token = $response_body['access_token'];
    }

    private function checkAuth() {
        if (!isset($this->token)) {
            throw new ClientUnauthorizedException('Client has not been authorized.');
        }
    }

    /**
     * Starts a new upload session.
     * @param string $folder_id The ID of the folder this session will upload to.
     * @return Session The newly created upload session.
     * 
     * @throws ClientUnauthorizedException If authenticate has not yet been called.
     * @throws BadApiCallException If there is an error with the Panopto service.
     */
    public function newSession($folder_id) {
        $this->checkAuth();
        $response = $this->http->post('/Panopto/PublicAPI/Rest/sessionUpload', array(
            'headers' => ['Authorization' => 'Bearer ' . $this->token],
            'json' => ['FolderId' => $folder_id]
        ));
        $this->checkResponseStatus($response, 201);
        $response_body = json_decode($response->getBody(), true);
        return new Session($response_body);
    }

    /**
     * Indicates to Panopto that all files have been uploaded and processing can begin.
     * @param Session $session The finished session.
     * 
     * @throws ClientUnauthorizedException If authenticate has not yet been called.
     * @throws BadApiCallException If there is an error with the Panopto service.
     */
    public function finishSession(&$session) {
        $this->checkAuth();
        $session_data = $session->sessionData();
        $session_data['State'] = 1;
        $url = '/Panopto/PublicAPI/Rest/sessionUpload/' . $session_data['ID'];
        $response = $this->http->put($url, array(
            'headers' => ['Authorization' => 'Bearer ' . $this->token],
            'json' => $session_data
        ));
        $this->checkResponseStatus($response, 200);
        $response_body = json_decode($response->getBody(), true);
        $session = new Session($response_body);
    }

    /**
     * Gets the current status of a session, and populates the SessionId.
     * @param Session $session The session to be queried.
     * @return int The state of the session, the meaning of which can be seen here: https://support.panopto.com/s/article/Upload-API
     * 
     * @throws ClientUnauthorizedException If authenticate has not yet been called.
     * @throws BadApiCallException If there is an error with the Panopto service.
     */
    public function getSessionStatus(&$session) {
        $this->checkAuth();
        $url = '/Panopto/PublicAPI/Rest/sessionUpload/' . $session->sessionData()['ID'];
        $response = $this->http->get($url, array(
            'headers' => ['Authorization' => 'Bearer ' . $this->token]
        ));
        $this->checkResponseStatus($response, 200);
        $response_body = json_decode($response->getBody(), true);
        $session = new Session($response_body);
        return $response_body['State'];        
    }

    /**
     * Deletes the session from Panopto.
     * @param string $session_id The SessionId of the session to be deleted.
     * 
     * @throws ClientUnauthorizedException If authenticate has not yet been called.
     * @throws BadApiCallException If there is an error with the Panopto service.
     */
    public function deleteSession($session_id) {
        $this->checkAuth();
        $url = '/Panopto/api/v1/sessions/' . $session_id;
        $response = $this->http->delete($url, array(
            'headers' => ['Authorization' => 'Bearer ' . $this->token]
        ));
        $this->checkResponseStatus($response, 200);
    }
}

/**
 * Session class manages an individual upload session.
 */
class Session {

    /**
     * Panopto SessionUpload model.
     * 
     * Has the following properties:
     *      ID
     *      FolderId
     *      SessionId
     *      UploadTarget
     *      State
     */
    private $session_data;

    /**
     * @param array $session_data The session data returned from the Panopto service.
     */
    function __construct($session_data) {
        $this->session_data = $session_data;
    }

    /**
     * @return string The Session Id.
     */
    public function sessionId() {
        return $this->session_data['SessionId'];
    }

    /**
     * @return array The Session data.
     */
    public function sessionData() {
        return $this->session_data;
    }

    /**
     * Uploads a file using the S3 protocol.
     * 
     * @param string $file_path The path to the file to be uploaded.
     */
    public function uploadFile($file_path) {
        $upload_target = $this->session_data['UploadTarget'];
        $element = explode('/', $upload_target);
        $prefix = array_pop($element);
        $service_endpoint = implode('/', $element);
        $bucket = array_pop($element);
        $file_name = basename($file_path);
        $object_key = $prefix.'/'.$file_name;        
        $s3Client = new S3Client(array(
            'endpoint' => $service_endpoint,
            'region'  => 'us-east-1',
            'version' => '2006-03-01',
            'credentials' => [
              'key'    => 'dummy',
              'secret' => 'dummy'
            ]
        ));
        $source = fopen($file_path, 'rb');
        $uploader = new ObjectUploader(
            $s3Client,
            $bucket,
            $object_key,
            $source
        );

        do {
            try {
                $result = $uploader->upload();
            } catch (MultipartUploadException $e) {
                rewind($source);
                $uploader = new MultipartUploader($s3Client, $source, [
                    'state' => $e->getState()
                ]);
            }
        } while (!isset($result));
    }

}
