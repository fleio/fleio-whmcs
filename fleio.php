<?php 

require_once __DIR__ . '/api.php';

function fleio_ConfigOptions() {
    global $_LANG;
    $configarray = array(
    "username" => array (
        "FriendlyName" => "UserName",
        "Type" => "text", # Text Box
        "Size" => "25", # Defines the Field Width
        "Description" => "Textbox",
        "Default" => "Example",
    ),
    "password" => array (
        "FriendlyName" => "Password",
        "Type" => "password", # Password Field
        "Size" => "25", # Defines the Field Width
        "Description" => "Password",
        "Default" => "Example",
    ),
    "usessl" => array (
        "FriendlyName" => "Enable SSL",
        "Type" => "yesno", # Yes/No Checkbox
        "Description" => "Tick to use secure connections",
    ),
    );
    return $configarray;
}

function fleio_CreateAccount( $params ) {
    $fu = new Fleio( $params );
    try {
        $client = $fu->createClient();
        $fl_user = $fu->createUser($client["id"]);
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

function fleio_ServiceSingleSignOn( array $params ) {
    $return = array( 'success' => false, );
}

function fleio_TerminateAccount($params) {
    return "Not implemented";
}

class Fleio {
    private $SERVER;
    private $PROD_ID;
    private $USER_PREFIX='whmcs';

    public function __construct( $params ) {
        $this->SERVER = new stdClass;
        if( $params[ 'serversecure' ] == 'fake' ) {
            $this->SERVER->url = 'https://';
        }
        else {
            $this->SERVER->url = 'http://';
        }
        $this->SERVER->url .= empty( $params[ 'serverip' ] ) ? $params[ 'serverhostname' ] : $params[ 'serverip' ];
        $this->SERVER->token = $params[ 'serveraccesshash' ];
        $this->clientsdetails = $params['clientsdetails'];
        $this->PROD_ID = (string)$params['pid'];
        $this->flApi = new FLApi($this->SERVER->url, $this->SERVER->token);
    }

    public function createUser($client_id) {
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

    public static function generatePassword() {
        return substr( str_shuffle( '~!@$%^&*(){}|0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ' ), 0, 20 );
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
    // Determine the requested action and set service call parameters based on
    // the action.
    $requestedAction = isset($_REQUEST['customAction']) ? $_REQUEST['customAction'] : '';
    if ($requestedAction == 'manage') {
        $serviceAction = 'get_usage';
        $templateFile = 'templates/manage.tpl';
    } else {
        $serviceAction = 'get_stats';
        $templateFile = 'templates/overview.tpl';
    }
    try {
        // Call the service's function based on the request action, using the
        // values provided by WHMCS in `$params`.
        $response = array();
        $extraVariable1 = 'abc';
        $extraVariable2 = '123';
        return array(
            'tabOverviewReplacementTemplate' => $templateFile,
            'templateVariables' => array(
                'extraVariable1' => $extraVariable1,
                'extraVariable2' => $extraVariable2,
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

