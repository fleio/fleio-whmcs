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
        $server->userPrefix = $params['configoption9'] ? $params['configoption9'] : 'whmcs';
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
            ->select('tblproducts.configoption1', 'tblproducts.configoption2', 'tblproducts.configoption4')
            ->where('tblhosting.id', '=', $prodid)->first();
        $server = new stdClass;
        $server->url = $dbserver->configoption4;
        $server->frontend_url = $dbserver->configoption2;
        $server->token = $dbserver->configoption1;
        $server->userPrefix = $dbserver->configoption9 ? $dbserver->configoption9 : 'whmcs';
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

    public function createBillingClient($groups, $serviceId=NULL) {
        $url = '/clients';
        $currency = getCurrency();
        $clientUsername = $this->server->userPrefix ? $this->server->userPrefix . $this->clientsdetails->userid : 'whmcs' . $this->clientsdetails->userid;
        $user = array("username" => $clientUsername,
                      "email" => $this->clientsdetails->email,
                      "email_verified" => true,
                      "first_name" => $this->clientsdetails->firstname,
                      "last_name" => $this->clientsdetails->lastname,
                      "password" => $this->generatePassword(16),
                      "external_billing_id" => $this->clientsdetails->uuid);

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
                        'external_billing_id' => $this->clientsdetails->uuid,
                        'currency' => $currency['code'],
                        'user' => $user,
                        'create_openstack_project' => true);

        if (isset($groups) && trim($groups) != '') {
            $client_groups = array_map('trim', explode(',', $groups, 10));
            $client['groups'] = $client_groups;
        };
        # Set the username to display in Products/Services in WHMCS Admin
        if ($serviceId) {
          try {
            Capsule::table( 'tblhosting' )
                  ->where( 'id', '=', $serviceId )
                  ->update(['username' => $clientUsername, 'password' => encrypt('set-in-fleio')]);
          } catch (Exception $e) { logActivity('Unable to set the Fleio username in WHMCS: '. $e->getMessage()); }
        }
        return $this->flApi->post($url, $client);
    }

    private function getClientId() {
        /* Get the Fleio client id from the WHMCS user id */
        # TODO(tomo): throw if the clientId is not found
        $url = '/clients';
        $query_params = array('external_billing_id' => $this->clientsdetails->uuid);
        $response = $this->flApi->get($url, $query_params);
        if ($response == null) {
            throw new FlApiRequestException("Unable to retrieve the Fleio client ID", 404);
        }
        $objects = $response['objects'];
        if (count($objects) > 1) {
            throw new FlApiRequestException("Unable to retrieve the Fleio client ID", 409); // Multiple objects returned
        }
        if (count($objects) == 0) {
           throw new FlApiRequestException("Unable to retrieve the Fleio client ID", 404);
	}
        return $objects[0]['id'];
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

    public function getClientSummary() {
        $client_id = $this->getClientId();
        $url = '/clients/' . $client_id . '/usage_summary';
        return $this->flApi->get($url);
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
    
    public function updateCredit($amount, $currencyCode, $currencyRate, $convertedAmount, $invoiceId='') {
        try {
          $fleio_client_id = $this->getClientId();
          $url = '/clients/' . $fleio_client_id . '/update_credit';
          $params = array('amount' => $amount,
                          'currency' => $currencyCode,
                          'rate' => $currencyRate,
                          'converted_amount' => $convertedAmount);        
          return $this->flApi->post($url, $params); 

        } catch (Exception $e) {
            $postData = ['clientid' => $this->clientsdetails->userid,
                         'description' => 'Fleio unable to set credit from Invoice #' . $invoiceId,
                         'amount' => $convertedAmount];
            try {
              localAPI('AddCredit', $postData);
            } catch (Exception $e) {
              logActivity('Fleio unable to update credit for Client ID: ' . $this->clientsdetails->userid . ' with ' . (string)$convertedAmount);
            }
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

    public function post( $url, $params ) {
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
