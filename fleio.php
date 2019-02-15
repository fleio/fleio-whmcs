<?php 

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'api.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'utils.php';

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
    $default_currency_code = getCurrency()['code'];
    $configarray = array(
    "admintoken" => array (
        "FriendlyName" => "Fleio staff Token",
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
        "FriendlyName" => "Frontend staff URL",
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
        "FriendlyName" => "Minimum payment amount",
        "Type" => "text", # Text Box
        "Size" => "10", # Defines the Field Width
        "Description" => "". $default_currency_code,
        "Default" => "10",
    ),
    "maxamount" => array (
        "FriendlyName" => "Maximum payment amount",
        "Type" => "text", # Text Box
        "Size" => "10", # Defines the Field Width
        "Description" => "". $default_currency_code,
        "Default" => "1000",
    ),
    "clientgroup" => array (
        "FriendlyName" => "Place clients inside a Fleio client group",
        "Type" => "text", # Text Box
        "Size" => "64", # Defines the Field Width
        "Description" => "",
        "Default" => "",
    ),
    "configuration" => array (
        "FriendlyName" => "Fleio Configuration Name",
        "Type" => "text",
		"Size" => "32",
        "Description" => "Fleio configuration for new clients",
        "Default" => ""
    ),
    "userprefix" => array (
        "FriendlyName" => "Fleio username prefix",
        "Type" => "text",
        "Size" => "12",
        "Description" => "Leave blank for 'whmcs'",
        "Default" => "whmcs",
    ),
    "issueinvoice" => array (
        "FriendlyName" => "Invoice clients without billing agreement",
	"Type" => "yesno",
	"Description" => "Issue invoice at the end of billing cycle for clients without billing agreement",
    ),
    "issueInvWAgr" => array (
        "FriendlyName" => "Invoice clients with billing agreement",
	"Type" => "yesno",
	"Description" => "Issue invoice at the end of billing cycle for clients with billing agreement",
    ),
    );
    return $configarray;
}

function fleio_CreateAccount($params){
    $fl = Fleio::fromParams($params);
    try {
        $fl->createBillingClient($params['configoption7'], $params['serviceid']);
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
    return "success";
}

function fleio_UnsuspendAccount($params) {
    $fl = Fleio::fromParams( $params );
    try {
        $result = $fl->resumeOpenstack();
    } catch (FlApiException $e) {
        return $e->getMessage();
    }
    return "success";
}

function fleio_TerminateAccount($params) {
    $fl = Fleio::fromParams($params);
    try {
        $result = $fl->terminateOpenstack();
    } catch (FlApiException $e) {
        return ($e->getMessage());
    }
    return "success";
}

function fleio_login($params) {
    $fl = Fleio::fromParams($params);
    try {
        $url = $fl->getSSOUrl();
        header("Location: " . $url);
        exit;
    } catch (FlApiException $e) {
        logActivity('Fleio SSO login error: ' . $e->getMessage());
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
    $whmcsClientCurrencyId = $params['clientsdetails']['currency'];
    $minamount = convertCurrency($min_amount, 1, $whmcsClientCurrencyId);
    $maxamount = convertCurrency($max_amount, 1, $whmcsClientCurrencyId);
    $fl = Fleio::fromParams($params);
    try {
        $client = $fl->getClient();
    } catch (Exception $e) {
        logModuleCall(
            'fleio',
            __FUNCTION__,
            $params,
            $e->getMessage(),
            $e->getTraceAsString()
        );
        $client = False;
    };
    // Convert from Fleio client currency to the WHMCS client currency
    $tax1 = getTaxRate(1, $params['clientsdetails']['state'], $params['clientsdetails']['countrycode']);
    $tax2 = getTaxRate(2, $params['clientsdetails']['state'], $params['clientsdetails']['countrycode']);
    $taxexempt = $params['clientsdetails']['taxexempt'];
    if ($taxexempt) {
		$tax1_rate = 0;
		$tax2_rate = 0;
    } else {
		$tax1_rate = $tax1['rate'];
		$tax2_rate = $tax2['rate'];
	}

    $whmcsClientCurrency = getCurrency($params['clientsdetails']['userid']);
    return array('minamount' => $minamount,
                 'maxamount' => $maxamount,
                 'tax1_rate' => $tax1_rate,
				 'tax2_rate' => $tax2_rate,
                 'currency' => $whmcsClientCurrency);
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
    # Action used for the Add Credit functionality
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
 
    $service = FleioUtils::getServiceById($params['serviceid']);
    $clientsdetails = $params['clientsdetails'];
    $values["userid"] = $clientsdetails['userid'];
    $values["sendinvoice"] = true;
    $values["itemdescription1"] = $service->name;
    $values["itemamount1"] = $amount;
    $values["itemtaxed1"] = true;

	$invoice_id = FleioUtils::createFleioInvoice($params['serviceid'], $values);
	$log_msg = "Fleio: User ID: ".$clientsdetails['userid']." created credit Invoice ID: ".$invoice_id." with amount ".formatCurrency($amount);
	logActivity($log_msg);

	redir("id=".(int) $invoice_id,"viewinvoice.php");
}

