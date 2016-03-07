<?php 

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/api.php';

function fleio_ConfigOptions() {
    global $_LANG;
    $configarray = array(
    "frontendurl" => array (
        "FriendlyName" => "FleioFrontend",
        "Type" => "text", # Text Box
        "Size" => "64", # Defines the Field Width
        "Description" => "Fleio frontend url",
        "Default" => "https://",
    ),
    );
    return $configarray;
}

function fleio_CreateAccount( $params ) {
    $fu = new Fleio( $params );
    try {
        $fl_user = $fu->createUser();
        $client = $fu->createClient();
        $utoc = $fu->addUserToClient($client['id'], $fl_user['id']);
    } catch (FLApiException $e) {
        return $e->getMessage();
    }
    return "success";
}

function fleio_SuspendAccount($params) {
    $fu = new Fleio( $params );
    try {
        $result = $fu->suspendOpenstack();
    } catch (FLApiException $e) {
        return $e->getMessage();
    }
}

function fleio_UnsuspendAccount($params) {
    $fu = new Fleio( $params );
    try {
        $result = $fu->resumeOpenstack();
    } catch (FLApiException $e) {
        return $e->getMessage();
    }
}

function fleio_TerminateAccount($params) {
    return "Not implemented";
}

function fleio_login($params) {
    $fu = new Fleio($params);
    try {
        $url = $fu->getSSOUrl();
        header("Location: " . $url);
        return "success";
    } catch (FLApiException $e) {
        //TODO(tomo): Handle the $e->getMessage() message
        return "Unable to retrieve a SSO session";
    }
}


function fleio_ServiceSingleSignOn($params) {
    $fu = new Fleio($params);
    try {
        $url = $fu->getSSOUrl();
        return array("success" => true, "redirectTo" => $url);
    } catch (FLApiException $e) {
        //TODO(tomo): Handle the $e->getMessage() message
        return array("success" => false, "errorMsg" => "Unable to retrieve a SSO session");
    }
}


function fleio_ClientAreaCustomButtonArray() {
    $buttonarray = array(
     "Login to Fleio" => "login",
    );
    return $buttonarray;
}


class Fleio {
    private $SERVER;
    private $PROD_ID;
    private $USER_PREFIX='whmcs';

    public function __construct($params) {
        $this->SERVER = new stdClass;
        if( $params[ 'serversecure' ] == 'fake' ) {
            $this->SERVER->url = 'https://';
        }
        else {
            $this->SERVER->url = 'http://';
        }
        $this->SERVER->frontend_url .= empty($params['configoption1']) ? $params['serverhostname'] : $params['configoption1'];
        $this->SERVER->url .= empty($params['serverip']) ? $params['serverhostname'] : $params['serverip'];
        $this->SERVER->token = $params[ 'serveraccesshash' ];
        $this->clientsdetails = $params['clientsdetails'];
        $this->PROD_ID = (string)$params['pid'];
        $this->flApi = new FLApi($this->SERVER->url, $this->SERVER->token);
    }

    public function createUser() {
        $url = '/staffapi/users';
        $postf = array("username" => $this->USER_PREFIX . $this->clientsdetails['userid'],
            "email" => $this->clientsdetails['email'],
            "email_verified" => true,
            "first_name" => $this->clientsdetails['firstname'],
            "last_name" => $this->clientsdetails['lastname'],
            "external_billing_id" => $this->clientsdetails['userid']);
        return $this->flApi->post($url, $postf);
    }

    public function createClient() {
        $url = '/staffapi/clients';
        $postfields = array('first_name' => $this->clientsdetails['firstname'],
             'last_name' => $this->clientsdetails['lastname'],
             'company' => $this->clientsdetails['company'],
             'address1' => $this->clientsdetails['address1'],
             'address2' => $this->clientsdetails['address2'],
             'city' => $this->clientsdetails['city'],
             'state' => $this->clientsdetails['state'],
             'country' => $this->clientsdetails['countrycode'],
             'zip_code' => $this->clientsdetails['postcode'],
             'phone' => $this->clientsdetails['phonenumber'],
             'fax' => $this->clientsdetails['fax'],
             'email' => $this->clientsdetails['email'],
             'external_billing_id' => $this->PROD_ID);
        return $this->flApi->post($url, $postfields);
    }

    private function getClientId() {
        $url = '/staffapi/clients';
        $query_params = array('external_billing_id' => $this->PROD_ID);
        $response = $this->flApi->get($url, $query_params);
        if ($response == null) {
            return null;
        }
        $objects = $response['objects'];
        if (count($objects) > 1) {
            return null; // Multiple objects returned
        }
        return $objects[0]['id'];
    }

    public function getUserId() {
        $url = '/staffapi/users';
        $query_params = array('external_billing_id' => $this->clientsdetails['userid']);
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
        $url = '/staffapi/clients/' . $client_id . '/add_user';
        $postfields = array('user' => $user_id, 'client' => $client_id);
        return $this->flApi->post($url, $postfields);
    }

    private function getSSOSession() {
        $url = '/staffapi/auth/get_sso_session';
        $params = array('euid' => $this->clientsdetails['userid']);
        return $this->flApi->post($url, $params);
    }

    public function getSSOUrl() {
        $euid = $this->clientsdetails['userid'];
        $url = $this->SERVER->frontend_url . '/sso?';
        $rsp = $this->getSSOSession();
        $params = array( 'euid', 'timestamp', 'hash_val' );
        $send_params = array_combine($params, explode(":", $rsp['hash_val']));
        $send_params = http_build_query($send_params);
        return  $url . $send_params;
    }

    public function getUsage() {
        $client_id = $this->getClientId();
        $url = '/staffapi/clients/' . $client_id . '/usage';
        return $this->flApi->get($url);
    }

    public function getToken($user_id) {
        $url = '/staffapi/users/' . $user_id . '/token';
        $response = $this->flApi->post($url);
        return $response['token'];
    }

    public function suspendOpenstack() {
        $fleio_client_id = $this->getClientId();
        if ($fleio_client_id != null) {
            $url = '/staffapi/clients/' . $fleio_client_id . '/suspend';
            return $this->flApi->post($url);
        } else {
            return "Unable to retrieve the Fleio client ID";
        }
    }

    public function resumeOpenstack() {
        $fleio_client_id = $this->getClientId();
        if ($fleio_client_id != null) {
            $url = '/staffapi/clients/' . $fleio_client_id . '/resume';
            return $this->flApi->post($url);
        } else {
            return "Unable to retrieve the Fleio client ID";
        }
    }
}

/**
 * Client area output logic handling.
 *
 * This function is used to define module specific client area output. It should
 * return an array consisting of a template file and optional additional
 * template variables to make available to that template.
 *
 * The template file you return can be one of two types:
 *
 * * tabOverviewModuleOutputTemplate - The output of the template provided here
 *   will be displayed as part of the default product/service client area
 *   product overview page.
 *
 * * tabOverviewReplacementTemplate - Alternatively using this option allows you
 *   to entirely take control of the product/service overview page within the
 *   client area.
 *
 * Whichever option you choose, extra template variables are defined in the same
 * way. This demonstrates the use of the full replacement.
 *
 * Please Note: Using tabOverviewReplacementTemplate means you should display
 * the standard information such as pricing and billing details in your custom
 * template or they will not be visible to the end user.
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return array
 */
function fleio_ClientArea(array $params)
{
    $min_amount = 10;
    $max_amount = 1000;
    $minamount = convertCurrency($min_amount, 1, $params['clientsdetails']['currency']);
    $maxamount = convertCurrency($max_amount, 1, $params['clientsdetails']['currency']);

    $requestedAction = isset($_REQUEST['customAction']) ? $_REQUEST['customAction'] : '';
    if ($requestedAction == 'manage') {
        $serviceAction = 'get_usage';
        $templateFile = 'templates/manage.tpl';
        $exv2 = 'manage';
    }
    if ($requestedAction == 'stats') {
        $serviceAction = 'get_stats';
        $templateFile = 'templates/overview.tpl';
        $exv2 = 'stats';
    }
    if ($requestedAction == 'addflfunds') {
        $serviceAction = 'addflfunds';
        $exv2 = 'addflfunds';
        $amount = $_REQUEST["amount"];
        try {
            $result = fleio_addFunds($amount, $minamount, $maxamount, $params);
        } catch (Exception $e) {
            $addfundserror = $e->getMessage();
        } 
        $templateFile = 'templates/overview.tpl';
    } else {
        $serviceAction = 'overview';
        $templateFile = 'templates/overview.tpl';
        $exv2 = 'overview';
    }
    try {
        // Call the service's function based on the request action, using the
        // values provided by WHMCS in `$params`.
        $response = array();
        $fl = new Fleio($params);
        $usage = $fl->getUsage();
        $fleioUsage = $usage;
        return array(
            'tabOverviewReplacementTemplate' => $templateFile,
            'templateVariables' => array(
                'fleioUsage' => $fleioUsage,
                'exv2' => $exv2,
                'minamount' => $minamount,
                'maxamount' => $maxamount,
                "addfundserror" => $addfundserror,
                "currency" => getCurrency($params['clientsdetails']['userid']),
            ),
        );
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'fleio',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        // In an error condition, display an error page.
        return array(
            'tabOverviewReplacementTemplate' => 'error.tpl',
            'templateVariables' => array(
                'usefulErrorHelper' => $e->getMessage(),
            ),
        );
    }
}


function fleio_validateAmount($original_amount, $min, $max) {
    // Validate amount
    // Using , instead of . (dot) ?
    $amount = str_replace(",", ".", $original_amount);
    if (!is_numeric($amount)) {
        throw new Exception("Please enter a valid amount.");
    } 
    if ($amount < $min) {
        $def_msg = isset($_LANG['addfundsminimumerror']) ? $_LANG['addfundsminimumerror'] : 'Amount must be equal or greated than'; 
        throw new Exception($def_msg." ".formatCurrency($min));
    }
    if ($amount > $max) {
        $def_msg = isset($_LANG['addfundsmaximumerror']) ? $_LANG['addfundsmaximumerror'] : 'Amount must be smaller than';
        throw new Exception($def_msg." ".formatCurrency($max));
    }
   return $amount;
}


function fleio_addFunds($original_amount, $min, $max, $params) {
    $amount = fleio_validateAmount($original_amount, $min, $max);
    $clientsdetails = $params['clientsdetails'];

    $command = "createinvoice";
    $values["userid"] = $clientsdetails['userid'];
    $values["date"] = toMySQLDate(getTodaysDate());
    $values["duedate"] = toMySQLDate(getTodaysDate());
    //$values["paymentmethod"] = $paymentmethod;
    $values["sendinvoice"] = false;
    $values["itemdescription1"] = 'Openstack cloud services';
    $values["itemamount1"] = $amount;
    $values["itemtaxed1"] = true;
    $values["notes"] = "fleio";

    $results = localAPI($command,$values,$ADMIN_USER);

    if ($results["result"] == "success") {
        # Invoice created.
        $log_msg = "User ID: ".$clientsdetails['userid']." adding ".formatCurrency($amount)." as Fleio credit. Invoice ID: ".$results["invoiceid"];
        logActivity($log_msg);
        //$table = "tblinvoiceitems";
        //$update = array("type"=>"fleio");
        //$where = array("invoiceid"=>$results["invoiceid"], "userid"=>$client->getID());
        //update_query($table,$update,$where);
        redir("id=".(int)$results["invoiceid"],"viewinvoice.php");
    } else {
        throw new Exception($results["message"]);
    }
}
