<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use Illuminate\Database\Capsule\Manager as Capsule;
use WHMCS\Module\Queue as ModuleQueue;

class FleioUtils {
    private static $gateway_name_prefix_to_gateway_map = array(
      'paypal' => 'paypalbilling',
      'stripe' => 'stripe'
    );

    public function __construct(){}

    public static function getWHMCSAdmin() {
        # Get the first WHMCS admin username (usually for localApi requests).
        try {
            return Capsule::table('tbladmins')->where([['roleid', '=', 1], ['disabled', '=', 0]])->limit(1)->value('username');
        } catch ( Exception $e) {
            logActivity('Fleio: unable to get an admin username: '. $e->getMessage());
            return;
        }
    }

    public static function addQueueTask($serviceId, $action, $errmsg='', $serviceType='service') {
        # Adds a task to Utilities -> Module Queue to allow admins to retry the operation
        return ModuleQueue::add($serviceType, $serviceId, 'fleio', $action, $errmsg);
    }

    public static function daysDiff($stringDate) {
        // Days diff between now and a date as strings: eg: '2019-02-14'
        try {
            $sndDate = date(strtotime($stringDate));
            $datediff = time() - $sndDate;
            return floor($datediff / (60 * 60 * 24));
        } catch (Exception $e) {
            logActivity('Fleio: unable to convert dates!');
            return NULL;
        }
    }

	public static function getServiceById($serviceId) {
        # Return a service including the product name amd description and some settings
        try {
            $prod = Capsule::table('tblhosting AS th')
                        ->join('tblproducts AS tp', 'th.packageid', '=', 'tp.id')
                        ->where('th.id', '=', $serviceId)
                        ->select('th.*', 'tp.name', 'tp.description', 'tp.servergroup', 'tp.tax', 'tp.servertype', 'tp.configoption8 AS configuration')
                        ->first();
        } catch (Exception $e) {
            logActivity('Fleio: unable to get the fleio product with ID: ' . $serviceId . '; ' . $e->getMessage());
            return NULL;
        }
        return $prod;
    }

    public static function getClientProduct($clientId, $packageId=NULL, $status='Active') {
        # Get the Fleio service for a client. A client has only one Fleio product.
        try {
            if (!is_null($packageId)) {
                $prod = Capsule::table('tblhosting AS th')
                ->join('tblproducts AS tp', 'th.packageid', '=', 'tp.id')
                ->where('th.userid', '=', $clientId)
                ->where('th.domainstatus', '=', $status)
                ->where('tp.servertype', '=', 'fleio')
                ->select('th.*')
                ->first();
            } else {
                $prod = Capsule::table('tblhosting AS th')
                ->join('tblproducts AS tp', 'th.packageid', '=', 'tp.id')
                ->where('th.userid', '=', $clientId)
                ->where('th.domainstatus', '=', $status)
                ->where('tp.id', '=', $packageId)
                ->where('tp.servertype', '=', 'fleio')
                ->select('th.*')
                ->first();
            }
        } catch (Exception $e) {
            return NULL;
        }
        return $prod;
    }

    public static function getUUIDClient($clientUUID) {
	    // Get the Fleio product for a WHMCS client specified by Client UUID
        return Capsule::table('tblclients AS tc')
                    ->where('tc.uuid', '=', $clientUUID)
                    ->select('tc.*')
                    ->first();
    }

    public static function getFleioProducts() {
        # Retrieve all the products of type Fleio
        try {
            return Capsule::table('tblproducts')->where('servertype', '=', 'fleio')->get();
        } catch (Exception $e) {
            logActivity('Fleio: unable to retrieve products: ' . $e->getMessage());
            return array();
        }
    }

    public static function createFleioInvoice($productId, $data, $type='Hosting') {
        # Helper to create an invoice for a Fleio product
        # Automatically calculate the date and duedate based on config
        global $CONFIG;
        $data['date'] = $data['date'] ? $data['date'] : date('Y-m-d');
        if (!$data['duedate']) {
            #TODO(tomo): $duedays = $CONFIG['CreateInvoiceDaysBeforeMonthly'] ? $CONFIG['CreateInvoiceDaysBeforeMonthly'] : $CONFIG['CreateInvoiceDaysBefore'];
            $duedays = $CONFIG['CreateInvoiceDaysBefore'];
            $dueDateTime = new DateTime($data['date']);
            $dueDateTime->modify('+' . $duedays . ' day');
            $data['duedate'] = $dueDateTime->format('Y-m-d');
        }
        // Get an admin username
        try {
            $adminUsername = self::getWHMCSAdmin();
        } catch (Exception $e) {
            logActivity('Fleio: ' . $e->getMessage());
            throw new Exception('Unable to create invoice'); // We do not throw the original message since it may contain sensitive data
        }
        $result = localAPI('CreateInvoice', $data, $adminUsername);
        if ($result["result"] == "success") {
            $invoice_id = $result['invoiceid'];
            Capsule::table('tblinvoiceitems')
                ->where('invoiceid', (string) $invoice_id)
                ->update(array("type"=>$type, "relid"=>$productId));
            return $invoice_id;
        } else {
            throw new Exception($result["message"]);
        }
    }

    public static function createOverdueClientInvoice($clientId, $amount, $fleioServiceId, $invoicePaymentMethod=NULL, $type='Hosting') {
        // Calculate date and due date of invoice
        $dueDays = 0;
        $today = date('Y-m-d');
        $dueDate = new DateTime($today);
        $dueDate->modify('+' . $dueDays . ' day');
        // Get an admin username, required to use local API
        try {
            $adminUsername = self::getWHMCSAdmin();
        } catch (Exception $e) {
            logActivity('Fleio: ' . $e->getMessage());
            throw new Exception('Unable to create invoice'); // We do not throw the original message since it may contain sensitive data
        }
        // Get the client Fleio product, to create an invoice for
        if (!$fleioServiceId) {
            throw new Exception('Fleio: unable to issue invoice for Client ID: ' . $clientId . ' since no OpenStack products found.');
        }
        $data = [
            "date" => $today,
            "duedate" => $dueDate->format('Y-m-d'),
            'userid' => $clientId,
            'sendinvoice' => '1',
            'itemdescription1' => 'Cloud services',
            'itemamount1' => $amount,
            'itemtaxed1' => true
        ];
        if (!is_null($invoicePaymentMethod)) {
            $data['paymentmethod'] = $invoicePaymentMethod;
        }
        $result = localAPI('CreateInvoice', $data, $adminUsername);
        if ($result["result"] == "success") {
            $invoice_id = $result['invoiceid'];
            Capsule::table('tblinvoiceitems')
                ->where('invoiceid', (string) $invoice_id)
                ->update(array("type"=>$type, "relid"=>$fleioServiceId));
            return $invoice_id;
        } else {
            throw new Exception($result["message"]);
        }
    }

    public static function clientHasPaidFleioRelatedInvoice($clientId) {
        $fleioServers = self::getFleioProducts();
        foreach($fleioServers as $server) {
            $clientProd = Capsule::table('tblhosting AS th')
                            ->join('tblproducts AS tp', 'th.packageid', '=', 'tp.id')
                            ->where('tp.id', '=', $server->id)
                            ->where('th.userid', '=', $clientId)
                            ->where('tp.servertype', '=', 'fleio')
                            ->select('th.*')
                            ->first();

            if (!$clientProd) {
                return false;
            }

            $invoiceItems = Capsule::table('tblinvoiceitems')
                                ->join('tblclients AS tc', 'tc.id', '=', 'tblinvoiceitems.userid')
                                ->join('tblhosting', 'tblinvoiceitems.relid', '=', 'tblhosting.id')
                                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                                ->where('tblinvoiceitems.userid', '=', $clientId)
                                ->where('tblinvoiceitems.relid', '=', $clientProd->id)
                                ->where('tblproducts.servertype', '=', 'fleio')
                                ->select('tblinvoiceitems.invoiceid')
                                ->get();

            foreach($invoiceItems AS $invoiceItem) {
                $invoice = Capsule::table('tblinvoices')
                            ->where('id', '=', $invoiceItem->invoiceid)
                            ->select('tblinvoices.status')
                            ->first();
                if ($invoice->status === 'Paid') {
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    public static function autoPayConsidered($gatewayName, $validNames) {
        $isConsidered = false;
        if (!$gatewayName || is_null($gatewayName) || empty($gatewayName)) {
            return $isConsidered;
        }
        if (trim($validNames) == '') {
            $isConsidered = true;
        } else {
            $includeGatewaysWithNameArr = explode(',', $validNames);
            foreach($includeGatewaysWithNameArr AS $validName) {
                $validName = trim($validName); // remove whitespaces if they exist
                if ($validName &&
                    strpos($gatewayName, $validName) === 0) {
                    $isConsidered = true;
                }
            }
        }
        return $isConsidered;
    }

    public static function clientHasBillingAgreement($client, $validGatewayNames) {
        // checks if client payment method is suitable for billing agreements based on its gateway name
        // also checks if client has a fleio related invoice with status of paid
        $clientId = $client->id;
        $hasAgreement = false;
        $gatewayNames = array();
        $results = localAPI('GetPayMethods', array('clientid' => $clientId));
        $relevantResponse = true;
        if ($results && $results['result'] == 'success') {
            if ($results['paymethods'] && sizeof($results['paymethods'])) {
                foreach($results['paymethods'] AS $payMethod) {
                    if ($payMethod['remote_token'] && !(is_null($payMethod['remote_token']))
                        && !(empty($payMethod['remote_token']))) {
                        $payMethodHasAgreement = self::autoPayConsidered(
                            $payMethod['gateway_name'],
                            $validGatewayNames
                        );
                        if ($payMethodHasAgreement) {
                            $hasAgreement = true;
                            array_push($gatewayNames, $payMethod['gateway_name']);
                        }
                    }
                }
            }
            if ($hasAgreement) {
                // check if client has a paid invoice related to fleio
                $hasAgreement = self::clientHasPaidFleioRelatedInvoice($clientId);
            }
            if ($hasAgreement) {
                // check on paymentmethod client field
                foreach(FleioUtils::$gateway_name_prefix_to_gateway_map AS $key => $gateway) {
                    foreach ($gatewayNames AS $gatewayWithAgreementName) {
                        if (substr($gatewayWithAgreementName, 0, strlen($key)) === $key) {
                            $hasAgreement = FleioUtils::$gateway_name_prefix_to_gateway_map[$key] === $client->paymentmethod;
                            break;
                        }
                    }
                };
            }
        } else {
            // cannot determine on agreement status if this fails
            $relevantResponse = false;
        }
        return array(
            'hasAgreement' => $hasAgreement,
            'gatewayNames' => $gatewayNames,
            'relevantResponse' => $relevantResponse
        );
    }

    public static function getFleioProductsInvoicedAmount($clientId, $fleioPackageId) {
        // Get the amount already invoiced and unpaid for Fleio active products related to this client
        // Return an array of: {"amount": .. "currency": .. "product": .. , "invoiced_since_days": ..}
        $fleioProduct = self::getClientProduct($clientId, $fleioPackageId);
        $clientCurrency = getCurrency($clientId);
        if (!$fleioProduct) {
            throw new Exception('Fleio: unable to issue invoice for Client ID: ' . $clientId . ' since no OpenStack products found.');
        }
        $items = Capsule::table('tblinvoiceitems')
                    ->join('tblclients AS tc', 'tc.id', '=', 'tblinvoiceitems.userid')
                    ->join('tblinvoices AS tinv', 'tinv.id', '=', 'tblinvoiceitems.invoiceid')
                    ->where([['tblinvoiceitems.userid', '=', $clientId], ['tblinvoiceitems.relid', '=', $fleioProduct->id]])
                    ->select('tblinvoiceitems.amount', 'tblinvoiceitems.userid', 'tc.currency', 'tinv.date', 'tinv.status')->get();
        $daysSinceLastInvoice = NULL;
        $amount = 0;
        foreach($items as $item) {
            if ($item->status == 'Unpaid') {
                // Count only unpaid invoices
                $amount += $item->amount;
            }
            $invIssuedDays = self::daysDiff($item->date);
            if ($daysSinceLastInvoice === NULL && $invIssuedDays !== NULL) {
                $daysSinceLastInvoice = $invIssuedDays;
            } else {
                if ($daysSinceLastInvoice > $invIssuedDays) {
                    $daysSinceLastInvoice = $invIssuedDays;
                }
            }
        }
        return array("amount" => $amount, "currency" => $clientCurrency, "product" => $fleioProduct, 'days_since_last_invoice' => $daysSinceLastInvoice);
    }

    public static function removeClientBillingAgreement($flApi, $clientExternalBillingId) {
        $agreements = [];
        $clientAgreement = array("uuid" => $clientExternalBillingId, "agreement" => false);
        array_push($agreements, $clientAgreement);
        if (sizeof($agreements)) {
            $url = '/clients/set_billing_agreements';
            try {
                $flApi->post($url, $agreements);
            } catch (Exception $e) {
                logActivity('Fleio update billing agreements FAIL: '. $e->getMessage());
            }
        }
    }
    
    public static function processUnpaidInvoices($fleioServer, $client, $retryChargesEveryXHours, $removeAgreementStatusAfterXFailedCharges) {
        // this has to start with the assumption client is on agreement
        // if unpaid invoices exists and settings says to remove agreement status after charge attempts, do so
        $hasAgreement = true;
        $clientProd = Capsule::table('tblhosting AS th')
                        ->join('tblproducts AS tp', 'th.packageid', '=', 'tp.id')
                        ->where('tp.id', '=', $fleioServer->id)
                        ->where('th.domainstatus', '=', 'Active')
                        ->where('th.userid', '=', $client->id)
                        ->where('tp.servertype', '=', 'fleio')
                        ->select('th.*')
                        ->first();
                 
        if ($clientProd) {
            $invoiceItems = Capsule::table('tblinvoiceitems')
                                ->join('tblclients AS tc', 'tc.id', '=', 'tblinvoiceitems.userid')
                                ->join('tblhosting', 'tblinvoiceitems.relid', '=', 'tblhosting.id')
                                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                                ->where('tblinvoiceitems.userid', '=', $client->id)
                                ->where('tblinvoiceitems.relid', '=', $clientProd->id)
                                ->where('tblproducts.servertype', '=', 'fleio')
                                ->select('tblinvoiceitems.invoiceid')
                                ->get();

            foreach($invoiceItems AS $invoiceItem) {
                $invoice = Capsule::table('tblinvoices')
                            ->where('id', '=', $invoiceItem->invoiceid)
                            ->select(
                                'tblinvoices.status',
                                'tblinvoices.id',
                                'tblinvoices.last_capture_attempt'
                            )
                            ->first();
                if ($invoice && $invoice->status === 'Unpaid') {
                    if ($invoice->last_capture_attempt === '0000-00-00 00:00:00') {
                        $captured = self::captureInvoicePayment($invoice->id);
                        if ($captured === false && $removeAgreementStatusAfterXFailedCharges === '1') {
                            $hasAgreement = false;
                            break;
                        }
                    } else {
                        if ($removeAgreementStatusAfterXFailedCharges === '1' ||
                            $invoice->last_capture_attempt === '2000-01-01 00:00:00') {
                            // if last capture attempt is set to '2000-01-01 00:00:00' it means
                            // this already failed the second time and agreement removal was set at 2
                            $hasAgreement = false;
                            break;
                        }
                        $secondsPassedSinceLastCaptureAttempt = time() - strtotime(
                            $invoice->last_capture_attempt
                        );
                        $hoursSinceLastCaptureAttempt = floor(
                            $secondsPassedSinceLastCaptureAttempt / 3600
                        );
                        if ($hoursSinceLastCaptureAttempt >= (int)$retryChargesEveryXHours) {
                            // Retry payment. If this fails again, set last_capture_attempt to now
                            // and retry in a future time if needed.
                            $captured = self::captureInvoicePayment($invoice->id);
                            if ($captured === false &&
                                $removeAgreementStatusAfterXFailedCharges === '2') {
                                $hasAgreement = false;
                                Capsule::table('tblinvoices')
                                    ->where('id', '=', $invoice->id)
                                    ->update(['last_capture_attempt' => date(
                                        "Y-m-d H:i:s", strtotime('2000-01-01 00:00:00')
                                    )]);
                                // set this date so we know it failed at least the second time
                                break;
                            }
                        }
                    }
                }
            }
        }
        return $hasAgreement;
    }

    public static function updateClientsBillingAgreement($flApi, $status='Active', $includeGatewaysWithName='', $retryChargesEveryXHours='0',
                                                         $removeAgreementStatusAfterXFailedCharges='0', $capturePaymentImmediately, $fleioServer) {
        logActivity('Fleio: update all clients billing agreements');
        try {
            $clients = Capsule::table('tblhosting AS th')
                        ->join('tblproducts AS tp', 'th.packageid', '=', 'tp.id')
                        ->join('tblclients as tc', 'tc.id', '=', 'th.userid')
                        ->where('th.domainstatus', '=', $status)
                        ->where('tp.servertype', '=', 'fleio')
                        ->select('tc.id', 'tc.uuid', 'th.paymentmethod')
                        ->get();
        } catch (Exception $e) {
            return NULL;
        }
        $agreements = [];
        foreach($clients AS $client) {
            $hasAgreementResponse = self::clientHasBillingAgreement($client, $includeGatewaysWithName);
            $hasAgreement = $hasAgreementResponse['hasAgreement'];
            $relevantResponse = $hasAgreementResponse['relevantResponse'];
            if ($relevantResponse === true && $hasAgreement === true && $capturePaymentImmediately) {
                // Further check to see if client really is on agreement. Retry charges for his unpaid invoices if this setting is active,
                // Remove agreement status if auto-pay failed x times (defined in module settings)
                if ($retryChargesEveryXHours !== NULL && $retryChargesEveryXHours !== '0' && $retryChargesEveryXHours !== '') {
                    $hasAgreement = self::processUnpaidInvoices(
                        $fleioServer,
                        $client,
                        $retryChargesEveryXHours,
                        $removeAgreementStatusAfterXFailedCharges
                    );
                }
            }
            if ($relevantResponse === true) {
                // update client agreement status only if we know that the check is relevant
                $clientAgreement = array("uuid" => $client->uuid, "agreement" => $hasAgreement);
                array_push($agreements, $clientAgreement);
            }
        };
        if (sizeof($agreements)) {
            $url = '/clients/set_billing_agreements';
            try {
                $flApi->post($url, $agreements);
            } catch (Exception $e) {
                logActivity('Fleio update billing agreements FAIL: '. $e->getMessage());
            }
        }
    }

    public static function captureInvoicePayment($invoiceId) {
        $data = array('invoiceid' => $invoiceId);
        $result = localAPI('CapturePayment', $data);
        if (is_array($result) && $result['result'] != 'success') {
            $captureMessage = $result['message'];
            try {
                // payment failed, set the last capture attempt for this invoice
                Capsule::table('tblinvoices')
                    ->where('id', '=', $invoiceId)
                    ->update(['last_capture_attempt' => date("Y-m-d H:i:s", time())]);
            } catch (Exception $e) {
                logActivity('Could not set last capture attempt for invoice ' . $invoiceId . '. Reason: ' . $e->getMessage());
            }
            logActivity('Fleio: capture Invoice ID: '. $invoiceId .' '.$captureMessage);
            return false;
        } else {
            $captureMessage = 'Captured successfully';
            logActivity('Fleio: capture Invoice ID: '. $invoiceId .' '.$captureMessage);
            return true;
        }
    }

    public static function invoiceClient($whmcsClient, $clientDetailsToProcess, $doNotInvoiceAmountBelow,
                                         $alreadyInvoicedAndUnpaid) {
        $fleioWhmcsService = $alreadyInvoicedAndUnpaid['product'];
        $fleioWhmcsServiceId = $fleioWhmcsService->id;
        $daysSinceLastInvoice = $alreadyInvoicedAndUnpaid['days_since_last_invoice'];
        $daysSinceLastInvoice = $daysSinceLastInvoice === NULL ? 999999 : $daysSinceLastInvoice;
        // $clientDetailsToProcess["currency"] is the Fleio received amount currency code
        if ($clientDetailsToProcess["currency"] === $alreadyInvoicedAndUnpaid["currency"]["code"]) {
            $upToDateCredit = $clientDetailsToProcess['uptodate_credit'];
        } else {
            // currencies differ, we need to do a conversion in order to add an invoice in whmcs with
            // correct amount & currency
            $initialUpToDateCreditWhmcsCurrency = Capsule::table('tblcurrencies')
                ->select('id', 'code')
                ->where('code', '=', $clientDetailsToProcess["currency"])
                ->first();
            if (!$initialUpToDateCreditWhmcsCurrency) {
                throw new FlApiException(
                    'Could not find Fleio received amount currency in whmcs in order to convert it to whmcs'.
                    ' amount currency.'
                );
            }
            $upToDateCredit = convertCurrency(
                $clientDetailsToProcess['uptodate_credit'],
                $initialUpToDateCreditWhmcsCurrency->id,
                $alreadyInvoicedAndUnpaid["currency"]["id"]
            );
        }
        $amountUsedAndUninvoiced = 0 - $upToDateCredit - $alreadyInvoicedAndUnpaid["amount"];
        // Check unsettled Fleio billing histories
        if ($amountUsedAndUninvoiced > 0) {
            $clientCurrency = getCurrency($whmcsClient->id);
            if ($doNotInvoiceAmountBelow === NULL) {
                $doNotInvoiceAmountBelow = "0";
            }
            $doNotInvoiceAmountBelow = (float)$doNotInvoiceAmountBelow;
            $defaultCurrency = getCurrency();
            $doNotInvoiceAmountBelowClientCurrency = convertCurrency(
                $doNotInvoiceAmountBelow, $defaultCurrency['id'], $clientCurrency['id']
            );
            if ($amountUsedAndUninvoiced >= $doNotInvoiceAmountBelowClientCurrency) {
                if (sizeof($clientDetailsToProcess['unsettled_periods']) > 0 && $daysSinceLastInvoice > 0) {
                    // Invoice if client is not over limit but has reached his billing
                    // cycle and no invoice was issued in the last 24h
                    $invoicePaymentMethod = $fleioWhmcsService->paymentmethod;
                    $invoiceId = self::createOverdueClientInvoice(
                        $whmcsClient->id,
                        $amountUsedAndUninvoiced,
                        $fleioWhmcsServiceId,
                        $invoicePaymentMethod
                    );
                    logActivity(
                        'Fleio: issued Invoice ID: '. $invoiceId .' for User ID: '.
                        $whmcsClient->id. ' due to end of cycle for '.
                        $amountUsedAndUninvoiced . ' ' .
                        $alreadyInvoicedAndUnpaid["currency"]["code"]
                    );
                    return $invoiceId;
                }

                if ($amountUsedAndUninvoiced >= (0 - $clientDetailsToProcess['effective_credit_limit']) &&
                    $daysSinceLastInvoice > 0) {
                    // Invoice if client is over limit and no unpaid invoice exists to cover it and
                    // no invoice was issued in the last 24h
                    $invoicePaymentMethod = $fleioWhmcsService->paymentmethod;
                    // Use the Client payment method instead of the service one
                    $invoiceId = self::createOverdueClientInvoice(
                        $whmcsClient->id,
                        $amountUsedAndUninvoiced,
                        $fleioWhmcsServiceId,
                        $invoicePaymentMethod
                    );
                    logActivity(
                        'Fleio: issued Invoice ID: '. $invoiceId .' for User ID: '.
                        $whmcsClient->id. ' for over credit limit of '.
                        $amountUsedAndUninvoiced . ' ' . $alreadyInvoicedAndUnpaid["currency"]["code"]
                    );
                    return $invoiceId;
                }
            }
        }
        return NULL;
    }

    public static function invoiceClientByAmount($whmcsClient, $amount, $currencyCode, $doNotInvoiceAmountBelow,
                                                 $alreadyInvoicedAndUnpaid) {
        // used for generating invoice for auto invoicing feature
        $fleioWhmcsService = $alreadyInvoicedAndUnpaid['product'];
        $fleioWhmcsServiceId = $fleioWhmcsService->id;
        // $currencyCode is the Fleio received amount currency code
        if ($currencyCode === $alreadyInvoicedAndUnpaid["currency"]["code"]) {
            $finalAmount = $amount;
        } else {
            // currencies differ, we need to do a conversion in order to add an invoice in whmcs with
            // correct amount & currency
            $initialAmountWhmcsCurrency = Capsule::table('tblcurrencies')
                ->select('id', 'code')
                ->where('code', '=', $currencyCode)
                ->first();
            if (!$initialAmountWhmcsCurrency) {
                throw new FlApiException(
                    'Could not find Fleio received amount currency in whmcs in order to convert it to whmcs'.
                    ' amount currency.'
                );
            }
            $finalAmount = convertCurrency(
                $amount,
                $initialAmountWhmcsCurrency->id,
                $alreadyInvoicedAndUnpaid["currency"]["id"]
            );
        }
        // do not invoice anything, or just some of the fleio received amount, 
        // if unpaid invoices already exist for that amount
        $finalAmount = $finalAmount - $alreadyInvoicedAndUnpaid["amount"];

        if ($finalAmount > 0) {
            $clientCurrency = getCurrency($whmcsClient->id);
            if ($doNotInvoiceAmountBelow === NULL) {
                $doNotInvoiceAmountBelow = "0";
            }
            $doNotInvoiceAmountBelow = (float)$doNotInvoiceAmountBelow;
            $defaultCurrency = getCurrency();
            $doNotInvoiceAmountBelowClientCurrency = convertCurrency(
                $doNotInvoiceAmountBelow, $defaultCurrency['id'], $clientCurrency['id']
            );
            if ($finalAmount >= $doNotInvoiceAmountBelowClientCurrency) {
                $invoicePaymentMethod = $fleioWhmcsService->paymentmethod;
                $invoiceId = self::createOverdueClientInvoice(
                    $whmcsClient->id,
                    $finalAmount,
                    $fleioWhmcsServiceId,
                    $invoicePaymentMethod
                );
                logActivity(
                    'Fleio: issued Invoice ID: '. $invoiceId .' for User ID: '.
                    $whmcsClient->id. ' due to auto invoicing feature for '.
                    $finalAmount . ' ' .
                    $alreadyInvoicedAndUnpaid["currency"]["code"]
                );
                return $invoiceId;
            }
        }
        
        return NULL;
    }

    public static function resetInvoicedPeriodsStatus($flApi, $clientToProcess) {
        $servicesToReset = array();
        foreach ($clientToProcess['unsettled_periods'] as $clientPeriod) {
            if (!array_key_exists($clientPeriod['related_service'], $servicesToReset)) {
                $servicesToReset[$clientPeriod['related_service']] = array();
            }
            array_push($servicesToReset[$clientPeriod['related_service']], $clientPeriod['id']);
        }
        if (sizeof($servicesToReset)) {
            foreach ($servicesToReset as $key => $value) {
                $url = sprintf('/billing/services/%s/reset_service_invoiced_periods', $key);
                try {
                    logActivity('Fleio: resetting invoiced periods (' . json_encode($value) . ') for service ' . $key);
                    $flApi->post($url, array('periods_ids' => $value));
                } catch (Exception $e) {
                    logActivity(
                        'Fleio: error when trying to re-set invoiced periods (' . json_encode($value)
                        . ') for service ' . $key . ':' . $e->getMessage()
                    );
                }
            }
        }
    }

    public static function markWhmcsActiveServices($serverDetails, $flApi) {
        // get suspended services in whmcs and resume them if they are active in fleio
        $suspendedServicesInWhmcs = Capsule::table('tblhosting')
                                        ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                                        ->where('tblproducts.servertype', '=', 'fleio')
                                        ->where('tblhosting.domainstatus', '=', 'Suspended')
                                        ->select('tblhosting.userid', 'tblhosting.id')
                                        ->get();
        foreach ($suspendedServicesInWhmcs as $whmcsSuspendedService) {
            $relatedClient = Capsule::table('tblclients')
                                ->join('tblhosting', 'tblhosting.userid', '=', 'tblclients.id')
                                ->where('tblclients.id', '=', $whmcsSuspendedService->userid)
                                ->select('tblclients.uuid', 'tblclients.id')
                                ->first();
            try {
                $servicesUrl = "/billing/services";
                $relatedServiceResponse = $flApi->get($servicesUrl, array("filtering"=>"client__external_billing_id:".$relatedClient->uuid."+external_billing_id:".$whmcsSuspendedService->id."+product__product_type:openstack"));
                if ($relatedServiceResponse['objects']) {
                    if ($relatedServiceResponse['objects'][0]['status'] === 'active') {
                        Capsule::table('tblhosting')
                            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                            ->where('tblproducts.servertype', '=', 'fleio')
                            ->where('tblhosting.userid', '=', $relatedClient->id)
                            ->where('tblhosting.id', '=', $whmcsSuspendedService->id)
                            ->update(array("domainstatus"=>"Active"));
                        logActivity(
                            'Fleio: marking service of client with id ' . $relatedClient->id .
                            ' as active to reflect Fleio OS service'
                        );
                    }
                }
            } catch (Exception $e) {
                logActivity(
                    'Fleio: unable to retrieve fleio services related to whmcs suspended services from '.
                    $serverDetails . ' (' . $e->getMessage() . ')'
                );
            }
        }
    }

    public static function markWhmcsSuspendedServices($serverDetails, $flApi) {
        // get suspended services in order to update their status in whmcs
        $servicesNext = true;
        $servicePage = 0;
        while ($servicesNext) {
            $servicePage = $servicePage + 1;
            try {
                $servicesUrl = "/billing/services?filtering=product__product_type:openstack%2Bstatus:suspended&page=" .
                               (string)$servicePage . "&";
                $suspendedServices = $flApi->get($servicesUrl, array());
                if (!$suspendedServices['next']) {
                    $servicesNext = false;
                }
                foreach ($suspendedServices['objects'] as $serviceToSuspend) {
                    if ($serviceToSuspend['client']['external_billing_id']) {
                        $clientFromUUID = self::getUUIDClient($serviceToSuspend['client']['external_billing_id']);
                        if ($clientFromUUID !== NULL && $serviceToSuspend['external_billing_id'] !== NULL) {
                            $whmcsService = Capsule::table('tblhosting')
                                                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                                                ->where('tblproducts.servertype', '=', 'fleio')
                                                ->where('tblhosting.userid', '=', $clientFromUUID->id)
                                                ->where('tblhosting.id', '=', $serviceToSuspend['external_billing_id'])
                                                ->select('tblhosting.domainstatus')
                                                ->first();
                            if ($whmcsService && $whmcsService->domainstatus !== 'Suspended') {
                                Capsule::table('tblhosting')
                                    ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                                    ->where('tblproducts.servertype', '=', 'fleio')
                                    ->where('tblhosting.userid', '=', $clientFromUUID->id)
                                    ->where('tblhosting.id', '=', $serviceToSuspend['external_billing_id'])
                                    ->update(array("domainstatus"=>'Suspended'));
                                logActivity(
                                    'Fleio: marking service of client with id ' . $clientFromUUID->id .
                                    ' as suspended to reflect Fleio OS service'
                                );
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                logActivity(
                    'Fleio: unable to retrieve suspended services from '. $serverDetails . ' (' .
                    $e->getMessage() . ')'
                );
                $servicesNext = false;
            }
        }
    }

    public static function trimApiUrlTrailingSlash($url) {
        return rtrim($url,"/");
    }

}
