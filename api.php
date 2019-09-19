<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use Illuminate\Database\Capsule\Manager as Capsule;

class FlApiException extends Exception {}
class FlApiCurlException extends FlApiException {}
class FlApiRequestException extends FlApiException {}


class Fleio {
    private $SERVER;

    public function __construct(stdClass $server, stdClass $clientsdetails) {
        $this->SERVER = $server;
        $this->clientsdetails = $clientsdetails;
        $this->flApi = new FlApi($this->SERVER->url, $this->SERVER->token);
    }

    public static function fromParams(array $params) {
        $server = new stdClass;
        $server->url = $params['configoption4'];
        $server->frontend_url = $params['configoption2'];
        $server->token = $params['configoption1'];
        $server->userPrefix = !empty(trim($params['configoption9'])) ? trim($params['configoption9']) : 'whmcs';
        $server->invoiceClientsWithoutAgreement = trim($params['configoption10']) == 'on' ? True : False;
        $server->invoiceClientsWithAgreement = trim($params['configoption11']) == 'on' ? True : False;
        $server->ClientConfiguration = !empty(trim($params['configoption8'])) ? trim($params['configoption8']) : NULL;
        $clientsdetails = (object) $params['clientsdetails'];
        return new self($server, $clientsdetails);
    }

    public static function fromServiceId($prodid) {
        # NOTE(tomo): prodid is actually a tblhosting object ID, not the tblproducts ID
        $prodid = (string) $prodid;
        if (!is_string($prodid) or empty($prodid)) { // empty treats "0" as empty. We assume a product id will never be 0.
            throw new FlApiException('Unable to initialize the Fleio api client.');
        }
        $clientsdetails = Capsule::table('tblclients')->join('tblhosting', 'tblhosting.userid', '=', 'tblclients.id')->where('tblhosting.id', '=', $prodid)->first();
        $dbserver = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->select('tblproducts.configoption1', 'tblproducts.configoption2', 'tblproducts.configoption4', 'tblproducts.configoption8', 'tblproducts.configoption9', 'tblproducts.configoption10', 'tblproducts.configoption11')
            ->where('tblhosting.id', '=', $prodid)->first();
        $server = new stdClass;
        $server->url = $dbserver->configoption4;
        $server->frontend_url = $dbserver->configoption2;
        $server->token = $dbserver->configoption1;
        $server->userPrefix = !empty(trim($dbserver->configoption9)) ? trim($dbserver->configoption9) : 'whmcs';
        $server->invoiceClientsWithoutAgreement = trim($dbserver->configoption10) == 'on' ? True : False;
        $server->invoiceClientsWithAgreement = trim($dbserver->configoption11) == 'on' ? True : False;
        $server->ClientConfiguration = !empty(trim($dbserver->configoption8)) ? trim($dbserver->configoption8) : NULL;
        return new self($server, $clientsdetails);
    }

    private function generatePassword($size) {
        // We don't use the product password since it's stored in clear text in WHMCS
        $data = '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZabcefghijklmnopqrstuvwxyz&%#$@';
        return substr(str_shuffle($data), 0, $size);
    }

    public function getBillingPrice() {
        $fleio_client_id = $this->getClientId();
    	$url = '/clients/'. $fleio_client_id . '/billing_summary';
        $response = $this->flApi->get($url);
        if ($response == null) {
            throw new FlApiRequestException("Unable to retrieve billing summary", 404);
        }
        return $response['price'];
    }

    public function updateServiceExternalBillingId($newServiceExtBillingId, $clientUUID=NULL) {
        if ($clientUUID === NULL) {
            $clientUUID = $this->clientsdetails->uuid;
        }
        $url = '/billing/services';
        $response = $this->flApi->get($url, array("filtering"=>"client__external_billing_id:".$clientUUID."+product__product_type:openstack"));
        if ($response === null) {
            throw new FlApiRequestException("Unable to get service for client with uuid: " . $clientUUID, 404);
        }
        $responseObjects = $response["objects"];
        if (sizeof($responseObjects)) {
            $fleioServiceId = $responseObjects[0]["id"];
            $serviceUrl = '/billing/services/'.$fleioServiceId;
            try {
                $this->flApi->patch($serviceUrl, array("external_billing_id" => $newServiceExtBillingId));
            } catch (Exception $e) { 
                echo ''.$e->getMessage(); 
            }
        }
    }

    public function createBillingClient($groups, $serviceId=NULL) {
        try {
            $existentClient = $this->getClient();
        } catch (Exception $e) {
            $existentClient = NULL;
        }
        if ($existentClient === NULL) {
            // Create client with auto order service
            $url = '/clients';
            $currency = getCurrency();
            $clientUsername = $this->SERVER->userPrefix ? $this->SERVER->userPrefix . $this->clientsdetails->userid : 'whmcs' . $this->clientsdetails->userid;
            $user = array("username" => $clientUsername,
                          "email" => $this->clientsdetails->email,
                          "email_verified" => true,
                          "first_name" => $this->clientsdetails->firstname,
                          "last_name" => $this->clientsdetails->lastname,
                          "password" => $this->generatePassword(16),
                          "external_billing_id" => $this->clientsdetails->uuid);

            $client = array('first_name' => $this->clientsdetails->firstname,
                            'last_name' => $this->clientsdetails->lastname,
                            'company' => $this->clientsdetails->companyname,
                            'address1' => $this->clientsdetails->address1,
                            'address2' => $this->clientsdetails->address2,
                            'city' => $this->clientsdetails->city,
                            'state' => $this->clientsdetails->state,
                            'country' => $this->clientsdetails->countrycode,
                            'zip_code' => $this->clientsdetails->postcode,
                            'phone' => $this->clientsdetails->phonenumber,
                            'fax' => $this->clientsdetails->fax,
                            'email' => $this->clientsdetails->email,
                            'external_billing_id' => $this->clientsdetails->uuid,
                            'auto_order_service_external_billing_id' => $serviceId, // send service external billing id, available only from fleio version 2019.09
                            'currency' => $currency['code'],
                            'user' => $user,
                            'create_auto_order_service' => true);

            $cbset = $this->SERVER->ClientConfiguration;
    		if (!empty($cbset)) {
    			$client['configuration'] = $cbset;
    		};
            if (!empty($groups) && trim($groups) != '') {
                $client_groups = array_map('trim', explode(',', $groups, 10));
                $client['groups'] = $client_groups;
            };
            // Set the username to display in Products/Services in WHMCS Admin
            if ($serviceId) {
              try {
                Capsule::table( 'tblhosting' )
                      ->where( 'id', '=', $serviceId )
                      ->update(['username' => $clientUsername, 'password' => encrypt('set-in-fleio')]);
              } catch (Exception $e) { logActivity('Unable to set the Fleio username in WHMCS: '. $e->getMessage()); }
            }
            return $this->flApi->post($url, $client);
        } else {
            // Client already exists in fleio, update his fields and run activate on the targeted service, also resume client if necessary
            // (will run create on service module only if service is not active)
            $updatedDetails = array("first_name" => $this->clientsdetails->firstname,
                 "last_name" => $this->clientsdetails->lastname,
                 "address1" => $this->clientsdetails->address1,
                 "address2" => $this->clientsdetails->address2,
                 "city" => $this->clientsdetails->city,
                 "state" => $this->clientsdetails->state,
                 "email" => $this->clientsdetails->email,
                 "zip_code" => $this->clientsdetails->postcode,
                 "country" => $this->clientsdetails->country,
                 "company" => $this->clientsdetails->companyname,
                 "phone" => $this->clientsdetails->phonenumber);
            try {
                // update client details
                $this->updateFleioClient($updatedDetails);
            } catch (Exception $e) {
                logActivity('Could not update client ' . $this->clientdetails->uuid . ' details after running create on his existing service.');
            }
            // resume client if necessary
            if ($existentClient["status"] !== "active") {
                logActivity('Fleio: Resuming client ' . $this->clientdetails->uuid . ' before running create on his existing service.');
                $fleio_client_id = $this->getClientId();
                $url = '/clients/' . $fleio_client_id . '/resume';
                try {
                    $this->flApi->post($url);
                } catch (Exception $e) {
                    logActivity('Fleio: Could not resume client ' . $this->clientdetails->uuid . ' before running create on his existing service.');
                }
            }
            // TODO: update user if necessary
            // Resume user
            try {
                $relatedUser = $this->getUser();
            } catch (Exception $e) {
                $relatedUser = NULL;
            }
            if ($relatedUser !== NULL && !$relatedUser["is_active"]) {
                try {
                    $this->updateFleioUser($relatedUser["id"], array("is_active" => true));
                } catch (Exception $e) {
                    logActivity('Fleio: Could not re-activate user ' . $relatedUser["id"] . ' before running create on his existing service.');
                }
            }
            // Run create on existing targeted service
            $urlFleioService = '/billing/services';
            try {
                $filteringString = "external_billing_id:" . $serviceId . "%2Bclient__external_billing_id:" . (string)$this->clientsdetails->uuid;
                $response = $this->flApi->get($urlFleioService, array("filtering" => $filteringString));
            } catch (Exception $e) {
                throw new FlApiRequestException('Fleio: unable to find service ' . $serviceId . ' for activating it. Check if client and service are tied through external_billing_id.', 404);
            }
            if ($response === null) {
                throw new FlApiRequestException('Fleio: unable to find service ' . $serviceId . ' for activating it. Check if client and service are tied through external_billing_id.', 404);
            }
            $responseObjects = $response["objects"];
            if (sizeof($responseObjects)) {
                $fleioServiceId = $responseObjects[0]["id"];
                $url = '/billing/services/' . $fleioServiceId . '/activate';
                return $this->flApi->post($url);
            }
        }
    }

    public function updateFleioClient($details) {
        // Update the Fleio Client details
        $fleioClientId = $this->getClientId();
        $url = '/clients/'.$fleioClientId;
        return $this->flApi->post($url, $details);
    }

    public function updateFleioUser($fleioUserId, $details) {
        // Update the Fleio user details
        $url = '/users/'.$fleioUserId;
        return $this->flApi->patch($url, $details);
    }

    public function getUser() {
        $clientUsername = $this->SERVER->userPrefix ? $this->SERVER->userPrefix . $this->clientsdetails->userid : 'whmcs' . $this->clientsdetails->userid;
        $url = '/users';
        $query_params = array('username' => $clientUsername);
        $response = $this->flApi->get($url, $query_params);
        if ($response == null) {
            throw new FlApiRequestException("Unable to retrieve the Fleio user with username: " . $clientUsername, 400);
        }
        $objects = $response['objects'];
        if (count($objects) > 1) {
            throw new FlApiRequestException("Unable to retrieve the Fleio user with username: " . $clientUsername, 409); // Multiple objects returned
        }
        if (count($objects) == 0) {
           throw new FlApiRequestException("Unable to retrieve the Fleio user with username: " . $clientUsername, 404); // Not found
        }
        return $objects[0];
    }

    public function getClient() {
        /* Get the Fleio client id from the WHMCS user id */
        # TODO(tomo): throw if the clientId is not found
        $url = '/clients';
        $query_params = array('external_billing_id' => $this->clientsdetails->uuid);
        $response = $this->flApi->get($url, $query_params);
        if ($response == null) {
            throw new FlApiRequestException("Unable to retrieve the Fleio client with external billing id: " . (string)$this->clientsdetails->uuid, 400);
        }
        $objects = $response['objects'];
        if (count($objects) > 1) {
            throw new FlApiRequestException("Unable to retrieve the Fleio client with external billing id: " . (string)$this->clientsdetails->uuid, 409); // Multiple objects returned
        }
        if (count($objects) == 0) {
           throw new FlApiRequestException("Unable to retrieve the Fleio client with external billing id: " . (string)$this->clientsdetails->uuid, 404); // Not found
        }
        return $objects[0];
    }

	private function getClientId() {
        $client = $this->getClient();
        return $client['id'];
    }

    private function getSSOSession() {
        $url = '/get-sso-session';
        $params = array('euid' => $this->clientsdetails->uuid);
        return $this->flApi->post($url, $params);
    }

    public function getSSOUrl() {
        $euid = $this->clientsdetails->uuid;
        $url = $this->SERVER->frontend_url . '/sso?';
        $rsp = $this->getSSOSession();
        $params = array( 'euid', 'timestamp', 'hash_val' );
        $send_params = array_combine($params, explode(":", $rsp['hash_val']));
        $send_params = http_build_query($send_params);
        return  $url . $send_params;
    }

    public function suspendOpenstack($serviceId) {
        // get fleio service id and run suspend on fleio service
        $urlFleioService = '/billing/services';
        try {
            $filteringString = "external_billing_id:" . $serviceId . "%2Bclient__external_billing_id:" . (string)$this->clientsdetails->uuid;
            $response = $this->flApi->get($urlFleioService, array("filtering" => $filteringString));
        } catch (Exception $e) {
            throw new FlApiRequestException('Fleio: unable to find service ' . $serviceId . ' for suspension. Check if client and service are tied through external_billing_id', 404);
        }
        if ($response === null) {
            throw new FlApiRequestException('Fleio: unable to find service ' . $serviceId . ' for suspension. Check if client and service are tied through external_billing_id', 404);
        }
        $responseObjects = $response["objects"];
        if (sizeof($responseObjects)) {
            $fleioServiceId = $responseObjects[0]["id"];
            $url = '/billing/services/' . $fleioServiceId . '/suspend';
            $this->flApi->post($url);
        }
        // If client does not have any other active service, suspend client
        try {
            $otherServicesResponse = $this->flApi->get($urlFleioService, array(
                "filtering" => "client__external_billing_id:".$this->clientsdetails->uuid."+id__ne:".$fleioServiceId
            ));
        } catch (Exception $e) {
            $otherServicesResponse = NULL;
        }
        $otherActiveServicesCount = 0;
        if ($otherServicesResponse) {
            $otherServicesResponseObjects = $otherServicesResponse["objects"];
            if (sizeof($otherServicesResponseObjects)) {
                for($i = 0 ; $i < sizeof($otherServicesResponseObjects); $i++) {
                    if ($otherServicesResponseObjects[$i]["status"] !== "terminated" && $otherServicesResponseObjects[$i]["status"] !== "suspended") {
                        $otherActiveServicesCount = $otherActiveServicesCount + 1;
                    }
                }
            }
        }
        if ($otherActiveServicesCount === 0) {
            // suspend client
            $fleio_client_id = $this->getClientId();
            $url = '/clients/' . $fleio_client_id . '/suspend';
            try {
                $this->flApi->post($url);
            } catch (Exception $e) {
                // do nothing
            }
        }
    }

    public function resumeOpenstack($serviceId) {
        // get fleio service id and run resume on fleio service
        $urlFleioService = '/billing/services';
        try {
            $filteringString = "external_billing_id:" . $serviceId . "%2Bclient__external_billing_id:" . (string)$this->clientsdetails->uuid;
            $response = $this->flApi->get($urlFleioService, array("filtering" => $filteringString));
        } catch (Exception $e) {
            throw new FlApiRequestException('Fleio: unable to find service. ' . $serviceId . ' for resuming it. Check if client and service are tied through external_billing_id.', 404);
        }
        if ($response === null) {
            throw new FlApiRequestException('Fleio: unable to find service. ' . $serviceId . ' for resuming it. Check if client and service are tied through external_billing_id.', 404);
        }
        $responseObjects = $response["objects"];
        if (sizeof($responseObjects)) {
            $fleioServiceId = $responseObjects[0]["id"];
            $url = '/billing/services/' . $fleioServiceId . '/resume';
            $this->flApi->post($url);
        }
        // if client is suspended, also run resume on client
        try {
            $fleioClient = $this->getClient();
        } catch (Exception $e) {
            $fleioClient = NULL;
        }
        if ($fleioClient !== NULL) {
            if ($fleioClient["status"] === "suspended") {
                $url = '/clients/' . $fleioClient["id"] . '/resume';
                try {
                    $this->flApi->post($url);
                } catch (Exception $e) {
                    // do nothing
                }
            }
        }
    }

    public function terminateOpenstack($serviceId) {
        // get fleio service id
        $urlFleioService = '/billing/services';
        try {
            $filteringString = "external_billing_id:" . $serviceId . "%2Bclient__external_billing_id:" . (string)$this->clientsdetails->uuid;
            $response = $this->flApi->get($urlFleioService, array("filtering" => $filteringString));
        } catch (Exception $e) {
            throw new FlApiRequestException('Fleio: unable to find service ' . $serviceId . ' for termination. Check if client and service are tied through external_billing_id.', 404);
        }
        if ($response === null) {
            throw new FlApiRequestException('Fleio: unable to find service ' . $serviceId . ' for termination. Check if client and service are tied through external_billing_id.', 404);
        }
        $responseObjects = $response["objects"];
        if (sizeof($responseObjects)) {
            $fleioServiceId = $responseObjects[0]["id"];
            $url = '/billing/services/' . $fleioServiceId . '/terminate';
            $this->flApi->post($url);
        }
        // If client does not have any other active service, suspend client and de-activate fleio user
        try {
            $otherServicesResponse = $this->flApi->get($urlFleioService, array(
                "filtering" => "client__external_billing_id:".$this->clientsdetails->uuid."+id__ne:".$fleioServiceId
            ));
        } catch (Exception $e) {
            $otherServicesResponse = NULL;
        }
        $otherActiveServicesCount = 0;
        if ($otherServicesResponse) {
            $otherServicesResponseObjects = $otherServicesResponse["objects"];
            if (sizeof($otherServicesResponseObjects)) {
                for($i = 0 ; $i < sizeof($otherServicesResponseObjects); $i++) {
                    if ($otherServicesResponseObjects[$i]["status"] !== "terminated" && $otherServicesResponseObjects[$i]["status"] !== "suspended") {
                        $otherActiveServicesCount = $otherActiveServicesCount + 1;
                    }
                }
            }
        }
        if ($otherActiveServicesCount === 0) {
            // suspend client
            $fleio_client_id = $this->getClientId();
            $url = '/clients/' . $fleio_client_id . '/suspend';
            try {
                $this->flApi->post($url);
            } catch (Exception $e) {
                // do nothing
            }
            // de-activate user
            try {
                $relatedUser = $this->getUser();
            } catch (Exception $e) {
                $relatedUser = NULL;
            }
            if ($relatedUser !== NULL && $relatedUser["is_active"]) {
                try {
                    $this->updateFleioUser($relatedUser["id"], array("is_active" => false));
                } catch (Exception $e) {
                    // do nothing
                }
            }
        }
    }

    public function clientChangeCredit($addCredit, $amount, $currencyCode, $currencyRate, $clientAmount, $clientCurrency, $invoiceId='') {
        // originally this would have been: exchange_rate => $currencyrate; source_amount => $clientAmount; source_currency => $clientCurrency
        // but Fleio may not have all the currencies from WHMCS. Also, Fleio does not do the actual exchange either;  
    	try {
    	     $fleio_client_id = $this->getClientId();
    	     $url = '/clients/' . $fleio_client_id . '/change_credit';
    	     $params = array('amount' => $amount,
    	                     'currency' => $currencyCode,
    	                     'exchange_rate' => 1,
    	                     'source_amount' => $amount,
    	                     'source_currency' => $currencyCode,
                             'add_credit' => $addCredit);
    	     return $this->flApi->post($url, $params); 
   	        } catch (Exception $e) {
               if ($addCredit) {
   	               logActivity('Fleio unable to add credit in Fleio for User ID: ' . $this->clientsdetails->userid . ' with ' . (string)$clientAmount);
               } else {
                   logActivity('Fleio unable to withdraw credit from FLeio for User ID: ' . $this->clientsdetails->userid . ' with ' . (string)$clientAmount);
               }
   	           throw $e; 
   	        }
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

    public function post( $url, $params = NULL) {
        $ch = curl_init();
        if (is_array($params)) {
            $json_params = json_encode($params);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_params);
            $this->TEMP_HEADERS[] = 'Content-Length: ' . mb_strlen($json_params);
        }
        $response = $this->request($ch, 'POST', $url);
        curl_close($ch); 
        return $response;
    }

    public function patch( $url, $params ) {
        $ch = curl_init();
        if (is_array($params)) {
            $json_params = json_encode($params);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_params);
            $this->TEMP_HEADERS[] = 'Content-Length: ' . mb_strlen($json_params);
        }
        $response = $this->request($ch, 'PATCH', $url);
        curl_close($ch);
        return $response;
    }

    public function get( $url, $params ) {
        $ch = curl_init();
        $this->TEMP_HEADERS = array();
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

    public function delete( $url ) {
        $ch = curl_init();
        $response = $this->request($ch, 'DELETE', $url);
        curl_close($ch);
        return $response;
    }

    private function drf_get_details($inarray) {
        $response = '';
        if (is_array($inarray)) {
            foreach ($inarray as $key => $value) {
                if (is_array($value)) {
                    $response .= $key . ': ' . $this->drf_get_details($value);
                } else {
                    $response .= ' ' . $value;
                }
            }
        } else { $response .= ' ' . $inarray; }
        return $response;
    }

    private function parse_drf_error($drf_error, $httpcode) {
        // If the http status is bigger than 399, it signals an error, throw it
		$err_msg = 'Bad request with status ' . $httpcode;
        if ($httpcode > 499) { // throw for 500 and above
            throw new FlApiRequestException('An internal Fleio API error occurred', $httpcode);
        }
		if (is_array($drf_error)) {
			if (array_key_exists('detail', $drf_error)) {
				$err_msg = (string)$drf_error['detail'];
			} else {
                try {
                    $err_msg = $this->drf_get_details($drf_error);
                } catch (Exception $e) {
                    $err_msg = 'bad request: ' . $httpdoce . '; Unable to parse error message.';
                }
			}
		}
		throw new FlApiRequestException($err_msg, $httpcode);
    }

    private function request( $ch, $method, $url ) {
        curl_setopt($ch, CURLOPT_URL, $this->SERVER_URL . $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_POSTREDIR, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Set the connection timeout to 10 seconds.
        if ($method == 'GET') {
           curl_setopt($ch, CURLOPT_HTTPGET, 1); 
        }
        $headers = array();
        foreach ($this->HEADERS as $h) {
            array_push($headers, $h);
        }
        foreach ($this->TEMP_HEADERS as $th) {
            array_push($headers, $th);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $decoded_result = json_decode($result, true);  // We should always receive a JSON response. Decode and check for errors
        if (json_last_error() != JSON_ERROR_NONE) {
            $decoded_result = 'Invalid response from Fleio API with http code: '. $httpcode;
        }
        if ($result === false)  {  // If no result, a curl error may have occured
            throw new FlApiCurlException(curl_error($ch), curl_errno($ch));
        } 
        if ($httpcode > 399) {
            return $this->parse_drf_error($decoded_result, $httpcode);
        }
        return $decoded_result;
    }
}
