<?php 

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/api.php';
use Illuminate\Database\Capsule\Manager as Capsule;

function fleio_MetaData()
{
    return array(
        'DisplayName' => 'Fleio',
        'APIVersion' => '1.1',
        'RequiresServer' => false, // Set true if module requires a server to work
        'DefaultNonSSLPort' => '80',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => 'Login to Fleio',
        'AdminSingleSignOnLabel' => 'Login to Fleio',
    );
}

function fleio_ConfigOptions() {
    global $_LANG;
    $configarray = array(
    "admintoken" => array (
        "FriendlyName" => "Fleio Token",
        "Type" => "password", # Password Field
        "Size" => "64", # Defines the Field Width
        "Default" => "",
    ),
    "frontendurl" => array (
        "FriendlyName" => "Frontend URL",
        "Type" => "text", # Text Box
        "Size" => "64", # Defines the Field Width
        "Description" => "",
        "Default" => "https://",
    ),
    "frontendadminurl" => array (
        "FriendlyName" => "Frontend admin URL",
        "Type" => "text", # Text Box
        "Size" => "64", # Defines the Field Width
        "Description" => "",
        "Default" => "https://",
    ),
    "backendadminurl" => array (
        "FriendlyName" => "Backend admin URL",
        "Type" => "text", # Text Box
        "Size" => "64", # Defines the Field Width
        "Description" => "",
        "Default" => "https://",
    ),
    "minamount" => array (
        "FriendlyName" => "Minimum amount",
        "Type" => "text", # Text Box
        "Size" => "10", # Defines the Field Width
        "Description" => "",
        "Default" => "10",
    ),
    "maxamount" => array (
        "FriendlyName" => "Maximum amount",
        "Type" => "text", # Text Box
        "Size" => "10", # Defines the Field Width
        "Description" => "",
        "Default" => "1000",
    ),
    );
    return $configarray;
}

function fleio_CreateAccount( $params ) {
    $fl = Fleio::fromParams( $params );
    try {
        $fl_user = $fl->createUser();
        $client = $fl->createClient();
        $utoc = $fl->addUserToClient($client['id'], $fl_user['id']);
        $project = $fl->createOpenstackProject($client['id']);
    } catch (FlApiException $e) {
        return $e->getMessage();
    }
    return "success";
}

function fleio_SuspendAccount($params) {
    $fl = Fleio::fromParams( $params );
    try {
        $result = $fl->suspendOpenstack();
    } catch (FlApiException $e) {
        return $e->getMessage();
    }
}

function fleio_UnsuspendAccount($params) {
    $fl = Fleio::fromParams( $params );
    try {
        $result = $fl->resumeOpenstack();
    } catch (FlApiException $e) {
        return $e->getMessage();
    }
}

function fleio_TerminateAccount($params) {
    $fl = Fleio::fromParams($params);
    try {
        $result = $fl->terminateOpenstack();
    } catch (FlApiException $e) {
        logactivity($e->getMessage());
        return "Unable to terminate the account. See the activity logs for details.";
    }
    return "success";
}

function fleio_login($params) {
    $fl = Fleio::fromParams($params);
    try {
        $url = $fl->getSSOUrl();
        header("Location: " . $url);
        return "success";
    } catch (FlApiException $e) {
        //TODO(tomo): Handle the $e->getMessage() message
        return "Unable to retrieve a SSO session";
    }
}

function fleio_ClientAreaCustomButtonArray() {
    $buttonarray = array(
     "Login to Fleio" => "login",
    );
    return $buttonarray;
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
    // Min/Max in base currency
    $min_amount = 10;
    $max_amount = 1000;
    // Min/Max in client's currency
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
    if ($requestedAction == 'createflinvoice') {
        $serviceAction = 'actionCreateInvoice';
        $templateFile = 'templates/overview.tpl';
    } else {
        $serviceAction = 'actionOverview';
        $templateFile = 'templates/overview.tpl';
    }
    try {
        // Call the service's function based on the request action, using the
        // values provided by WHMCS in `$params`.
        return array(
            'tabOverviewReplacementTemplate' => $templateFile,
            'templateVariables' => $serviceAction($params, $_REQUEST),
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
            'tabOverviewReplacementTemplate' => 'templates/error.tpl',
            'templateVariables' => array(
                'usefulErrorHelper' => $e->getMessage(),
            ),
        );
    }
}


function actionOverview($params, $request) {
    $min_amount = $params['configoption5'];
    $max_amount = $params['configoption6'];
    // Min/Max in client's currency
    $minamount = convertCurrency($min_amount, 1, $params['clientsdetails']['currency']);
    $maxamount = convertCurrency($max_amount, 1, $params['clientsdetails']['currency']);

    $fl = Fleio::fromParams($params);
    $usage = $fl->getUsage();
    return array('fleioUsage' => $usage,
                 'minamount' => $minamount,
                 'maxamount' => $maxamount,
                 'currency' => getCurrency($params['clientsdetails']['userid']));
}


function validateAmount($original_amount, $min, $max) {
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


function actionCreateInvoice($params, $request) {
    $min_amount = $params['configoption5'];
    $max_amount = $params['configoption6'];
    // Min/Max in client's currency
    $minamount = convertCurrency($min_amount, 1, $params['clientsdetails']['currency']);
    $maxamount = convertCurrency($max_amount, 1, $params['clientsdetails']['currency']);
    
    $original_amount = $request["amount"];
    try { 
        $amount = validateAmount($original_amount, $minamount, $maxamount);
    } catch (Exception $e) {
        $overview_vars = actionOverview($params, $request);
        return array_merge($overview_vars, array('validateAmountError' => $e->getMessage(),));
    }
    $clientsdetails = $params['clientsdetails'];

    $command = "createinvoice";
    $values["userid"] = $clientsdetails['userid'];
    $values["date"] = toMySQLDate(getTodaysDate());
    $values["duedate"] = toMySQLDate(getTodaysDate());
    $values["sendinvoice"] = false;
    $values["itemdescription1"] = 'Fleio cloud services';
    $values["itemamount1"] = $amount;
    $values["itemtaxed1"] = true;

    $results = localAPI($command,$values,$ADMIN_USER);

    if ($results["result"] == "success") {
        # Invoice created.
        $log_msg = "User ID: ".$clientsdetails['userid']." adding ".formatCurrency($amount)." as Fleio credit. Invoice ID: ".$results["invoiceid"];
        logActivity($log_msg);
        Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $results['invoiceid'])
            ->where('userid', $clientsdetails['userid'])
            ->update(array("type"=>"fleio", "relid"=>$params['serviceid']));
        redir("id=".(int)$results["invoiceid"],"viewinvoice.php");
    } else {
        throw new Exception($results["message"]);
    }
}
