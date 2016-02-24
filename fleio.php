<?php 

require_once __DIR__ . '/curl.php';

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
    logactivity('Params: ' . serialize($params));
    $fu = new FleioUser( $params );
    logactivity('CreateAccount: ' . serialize($fu->createAccount()));
}

function fleio_SuspendAccount($params) {
logactivity('Fleio suspend called');
}

function build_str_to_hash($args)
{
    $result = '';
    foreach ($args as $arg) {
        $s = (string)$arg;
        $result .= (string)(strlen($s)) . $s;
    }
    return $result;
}

function get_hash_val($hash_key, $args)
{
    $str = build_str_to_hash($args);
    $hash_val = hash_hmac('sha1', $str, $hash_key);
    return strtoupper($hash_val);
}


class FleioUser {
    private $server;

    public function __construct( $params ) {
        $this->server = new stdClass;
        if( $params[ 'serversecure' ] == 'fake' ) {
            $this->server->ip = 'https://';
        }
        else {
            $this->server->ip = 'http://';
        }
        $this->server->ip .= empty( $params[ 'serverip' ] ) ? $params[ 'serverhostname' ] : $params[ 'serverip' ];
        $this->server->user = $params[ 'serverusername' ];
        $this->server->pass = $params[ 'serverpassword' ];
        $this->clientsdetails = $params['clientsdetails'];
        $this->curl = new Curl($this->server->ip);
    }

    public function auth() {
        $url = '/staffapi/auth/login';
        $postfields = array('username' => $this->server->user, 'password' => $this->server->pass);
        $this->_curl_init();
        $response = $this->request($url, $postfields);
        return $response;
    }

    public function createAccount() {
        $this->auth();
        $user_url = '/staffapi/users';
        $postfields = array("username" => 'whmcs' . $this->clientsdetails['userid'],
            "email" => $this->clientsdetails['email'],
            "first_name" => $this->clientsdetails['firstname'],
            "last_name" => $this->clientsdetails['lastname'],
            "external_billing_id" => $this->clientsdetails['userid']
        );
        $response = $this->request($user_url, $postfields);
        logactivity(serialize(response));
        $url = '/staffapi/clients';
        $postfields = array('name' => 'Test WHMCS', 'first_name' => 'Cristi', 'last_name' => 'Tomo', 'address1' => 'Ionesco', 'city' => 'Cluj-Napoca', 'country' => 'RO', 'zip_code' => '515400', 'phone' => '12345', 'email' => 'ctomoiaga@gmail.com');
        //$this->auth();
        $response = $this->request($url, $postfields);
        return $response;
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

