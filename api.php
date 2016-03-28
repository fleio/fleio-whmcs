<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

// see Laravel queries. WHMCS 6+ uses Capsule
use Illuminate\Database\Capsule\Manager as Capsule;

class FlApiException extends Exception {}
class FlApiCurlException extends FlApiException {}
class FlApiRequestException extends FlApiException {}


class Fleio {
    private $SERVER;
    private $USER_PREFIX='whmcs';

    public function __construct(stdClass $server, stdClass $clientsdetails) {
        $this->SERVER = $server;
        $this->clientsdetails = $clientsdetails;
        $this->flApi = new FlApi($this->SERVER->url, $this->SERVER->token);
    }

    public static function fromParams(array $params) {
        $server = new stdClass;
        $server->url = $params['configoption4'];
        $server->frontend_url = $params['configoption2'];
        $server->token = $params[ 'configoption1' ];
        $clientsdetails = (object) $params['clientsdetails'];
        return new self($server, $clientsdetails);
    }

    public static function fromProdId($prodid) {
        $prodid = (string) $prodid;
        if (!is_string($prodid) or empty($prodid)) { // empty treats "0" as empty. We assume a product id will never be 0.
            throw new FlApiException('Unable to initialize the fleio client.');
        }
        $clientsdetails = Capsule::table('tblclients')->join('tblhosting', 'tblhosting.userid', '=', 'tblclients.id')->where('tblhosting.id', '=', $prodid)->first();
        $dbserver = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->where('tblhosting.id', '=', $prodid)->first();
        $server = new stdClass;
        $server->url = $dbserver->configoption4;
        $server->frontend_url = $dbserver->configoption2;
        $server->token = $dbserver->configoption1;
        return new self($server, $clientsdetails);
    }

    public function createBillingClient($currencyCode) {
        $url = '/openstack/billing/create_billing_client';
        $user = array("username" => $this->USER_PREFIX . $this->clientsdetails->userid,
            "email" => $this->clientsdetails->email,
            "email_verified" => true,
            "first_name" => $this->clientsdetails->firstname,
            "last_name" => $this->clientsdetails->lastname,
            "external_billing_id" => $this->clientsdetails->userid);
        $client = array('first_name' => $this->clientsdetails->firstname,
             'last_name' => $this->clientsdetails->lastname,
             'company' => $this->clientsdetails->company,
             'address1' => $this->clientsdetails->address1,
             'address2' => $this->clientsdetails->address2,
             'city' => $this->clientsdetails->city,
             'state' => $this->clientsdetails->state,
             'country' => $this->clientsdetails->countrycode,
             'zip_code' => $this->clientsdetails->postcode,
             'phone' => $this->clientsdetails->phonenumber,
             'fax' => $this->clientsdetails->fax,
             'email' => $this->clientsdetails->email,
             'external_billing_id' => $this->clientsdetails->userid,
             'currency' => $currencyCode);
        $postfields = array("user" => $user, "client" => $client);
        return $this->flApi->post($url, $postfields);
    }    

    public function createUser() {
        $url = '/users';
        $postf = array("username" => $this->USER_PREFIX . $this->clientsdetails->userid,
            "email" => $this->clientsdetails->email,
            "email_verified" => true,
            "first_name" => $this->clientsdetails->firstname,
            "last_name" => $this->clientsdetails->lastname,
            "external_billing_id" => $this->clientsdetails->userid);
        return $this->flApi->post($url, $postf);
    }

    public function createClient() {
        $url = '/clients';
        $postfields = array('first_name' => $this->clientsdetails->firstname,
             'last_name' => $this->clientsdetails->lastname,
             'company' => $this->clientsdetails->company,
             'address1' => $this->clientsdetails->address1,
             'address2' => $this->clientsdetails->address2,
             'city' => $this->clientsdetails->city,
             'state' => $this->clientsdetails->state,
             'country' => $this->clientsdetails->countrycode,
             'zip_code' => $this->clientsdetails->postcode,
             'phone' => $this->clientsdetails->phonenumber,
             'fax' => $this->clientsdetails->fax,
             'email' => $this->clientsdetails->email,
             'external_billing_id' => $this->clientsdetails->userid);
        return $this->flApi->post($url, $postfields);
    }

    public function createOpenstackProject($clientid) {
        $url = '/openstack/projects';
        $postfields = array('client' => $clientid);
        return $this->flApi->post($url, $postfields);
    }

    private function getClientId() {
        /* Get the Fleio client id from the WHMCS user id */
        # TODO(tomo): throw if the clientId is not found
        $url = '/clients';
        $query_params = array('external_billing_id' => $this->clientsdetails->userid);
        $response = $this->flApi->get($url, $query_params);
        if ($response == null) {
            throw new FlApiRequestException("Unable to retrieve the Fleio client ID", 404);
        }
        $objects = $response['objects'];
        if (count($objects) > 1) {
            throw new FlApiRequestException("Unable to retrieve the Fleio client ID", 409);; // Multiple objects returned
        }
        return $objects[0]['id'];
    }

    public function getUserId() {
        $url = '/users';
        $query_params = array('external_billing_id' => $this->clientsdetails->userid);
        $response = $this->flApi->get($url, $query_params);
        if ($response == null) {
            return null;
        }
        $objects = $response['objects'];
        if (count($objects) > 1) {
            return null;
        }
        return $objects[0]['id'];
    }

    public function addUserToClient($client_id, $user_id) {
        $url = '/clients/' . $client_id . '/add_user';
        $postfields = array('user' => $user_id, 'client' => $client_id);
        return $this->flApi->post($url, $postfields);
    }

    private function getSSOSession() {
        $url = '/auth/get_sso_session';
        $params = array('euid' => $this->clientsdetails->userid);
        return $this->flApi->post($url, $params);
    }

    public function getSSOUrl() {
        $euid = $this->clientsdetails->userid;
        $url = $this->SERVER->frontend_url . '/sso?';
        $rsp = $this->getSSOSession();
        $params = array( 'euid', 'timestamp', 'hash_val' );
        $send_params = array_combine($params, explode(":", $rsp['hash_val']));
        $send_params = http_build_query($send_params);
        return  $url . $send_params;
    }

    public function getUsage() {
        $client_id = $this->getClientId();
        $url = '/clients/' . $client_id . '/usage';
        return $this->flApi->get($url);
    }

    public function getClientRamainingCredit() {
        # Return the client's remainig credit and currency code
        $client_id = $this->getClientId();
        $url = '/openstack/billing/' . $client_id . '/credit_balance';
        return $this->flApi->get($url);
    }

    public function getToken($user_id) {
        $url = '/users/' . $user_id . '/token';
        $response = $this->flApi->post($url);
        return $response['token'];
    }

    public function suspendOpenstack() {
        $fleio_client_id = $this->getClientId();
        $url = '/clients/' . $fleio_client_id . '/suspend';
        return $this->flApi->post($url);
    }

    public function resumeOpenstack() {
        $fleio_client_id = $this->getClientId();
        $url = '/clients/' . $fleio_client_id . '/resume';
        return $this->flApi->post($url);
    }

    public function terminateOpenstack() {
        $fleio_client_id = $this->getClientId();
        $url = '/clients/' . $fleio_client_id . '/terminate';
        return $this->flApi->post($url);
    }

    public function updateCredit($amount, $currencyCode, $currencyRate, $convertedAmount) {
        $fleio_client_id = $this->getClientId();
        $url = '/clients/' . $fleio_client_id . '/update_credit';
        $params = array('amount' => $amount,
                        'currency' => $currencyCode,
                        'rate' => $currencyRate,
                        'converted_amount' => $convertedAmount);
        return $this->flApi->post($url, $params); 
    }
}


class FlApi {
    private $SERVER_URL;
    private $SERVER_TOKEN;
    private $HEADERS=array('Content-Type: application/json');
    private $TEMP_HEADERS=array();

    public function __construct($server_url, $token) {
        if (!is_string($server_url) or empty($server_url) or (!is_string($token))) {
            exit('server url or accesshash not set');
        }
        $this->SERVER_URL = $server_url;
        $this->SERVER_TOKEN = $token;
        $this->HEADERS[] = 'Authorization: Token ' . $token;    
    }

    public function post( $url, $params ) {
        $ch = curl_init();
        if (is_array($params)) {
            $json_params = json_encode($params);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            $this->TEMP_HEADERS[] = 'Content-Length: ' . mb_strlen($json_params);
        }
        $response = $this->request($ch, 'POST', $url);
        curl_close($ch); 
        return $response;
    }

    public function get( $url, $params ) {
        $ch = curl_init();
        if (is_array($params)) {
            $getfields = http_build_query($params);
            str_replace('.', '%2E', $getfields);
            str_replace('-', '%2D', $getfields);
            $url .= '?'.$getfields;
        }
        $response = $this->request($ch, 'GET', $url);
        curl_close($ch);
        return $response;
    }

    private function request( $ch, $method, $url ) {
        curl_setopt($ch, CURLOPT_URL, $this->SERVER_URL . $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Set the connection timeout to 10 seconds.
        $headers = $this->HEADERS + $this->TEMP_HEADERS;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($result === false)  {
            throw new FlApiCurlException(curl_error($ch), curl_errno($ch));
        } else {
            if ($httpcode > 499) {
                throw new FlApiRequestException('An internal Fleio API error occurred', $httpcode);
            }
            if ($httpcode > 399) { 
                throw new FlApiRequestException($result, $httpcode);
            }
        }
        return json_decode($result, true);
    }
}
