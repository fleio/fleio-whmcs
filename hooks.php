<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'api.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'utils.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use WHMCS\View\Menu\Item as MenuItem;

add_hook("InvoicePaid", 99, "openstack_add_funds_hook", "");
# add_hook("InvoiceUnpaid", 99, "openstack_del_credit_hook");  # disabled for now. Invoice can be Cancelled and marked Unpaid afterwards...
# add_hook("InvoiceRefunded", 99, "openstack_del_credit_hook"); # disabled for now. Manual funds removal is necessary in Fleio.
add_hook("ClientAreaPrimarySidebar", 99, "fleio_ClientAreaPrimarySidebar");
add_hook("ClientAreaPrimaryNavbar", 99, "fleio_ClientAreaPrimaryNavbar");
add_hook("InvoiceCreation", 99, "fleio_update_invoice_hook");
//add_hook("UpdateInvoiceTotal", 99, "fleio_test");
//add_hook("DailyCronJob", 99, "fleio_cronjob"); // NOTE(tomo): Automatically creates invoices in WHMCS for clients
add_hook("ClientEdit", 99, "fleio_client_edit");
//add_hook("DailyCronJob", 99, "fleio_PostCronjob");
add_hook("AfterCronJob", 99, "fleio_PostCronjob");

function fleio_PostCronjob() {
    // Retrieve a list of all Fleio Clients that have external billing set and have reached their credit limit or have 
    // unsettled billing histories
    $fleioServers = FleioUtils::getFleioProducts();  // get all WHMCS products associated with the Fleio module
    foreach($fleioServers as $server) {
      $invoiceWithAgreement = $server->configoption11 == 'on' ? true : false; // invoice clients with billing agreement
      $invoiceWithoutAgreement = $server->configoption10 == 'on' ? true : false;  // invoice clients without billing agreement
      $capturePaymentImmediately = $server->configoption12 == 'on' ? true : false; // Attempt to capture payment immediately
      if ($invoiceWithAgreement && $invoiceWithoutAgreement) {
        logActivity('Fleio: Looking at clients with and without a billing agreement');
      };
      $flApi = new FlApi($server->configoption4, $server->configoption1);
      FleioUtils::updateClientsBillingAgreement($flApi, 'Active', $server->configoption13);
      logActivity('Fleio: retrieving all overdue clients');
      $url = "/clients/over_credit_limit";
      $urlParams = array(
        "has_external_billing" => 'True', // only clients with external billing set and credit less than 0
        "uptodate_credit_max" => 0
      );
      if ($invoiceWithAgreement && !$invoiceWithoutAgreement) {
        // Filter only Clients with billing agreement
        $urlParams['has_billing_agreement'] = 'True';
        logActivity('Fleio: Looking at clients with billing agreements only');
      };
      if (!$invoiceWithAgreement && $invoiceWithoutAgreement) {
        // Filter only Clients without billing agreement
        $urlParams['has_billing_agreement'] = 'False';
        logActivity('Fleio: Looking at clients without a billing agreement only');
      };
      try {
        logActivity('Fleio: using server ' . $server->configoption4);
        $clientsOverLimit = $flApi->get($url, $urlParams);
        $numInvoicedClients = 0;
        foreach($clientsOverLimit as $clientOl) {
            try {
                $clientFromUUID = FleioUtils::getUUIDClient($clientOl['external_billing_id']);
                if ($clientFromUUID != NULL) {
                    $clientHasBillingAgreement = FleioUtils::clientHasBillingAgreement($clientFromUUID->gatewayid, $server->configoption13);
                    $alreadyInvoicedAndUnpaid = FleioUtils::getFleioProductsInvoicedAmount($clientFromUUID->id, $server->id);
                    $fleioWhmcsService = $alreadyInvoicedAndUnpaid['product'];
                    $fleioWhmcsServiceId = $fleioWhmcsService->id;
                    $daysSinceLastInvoice = $alreadyInvoicedAndUnpaid['days_since_last_invoice'];
                    $daysSinceLastInvoice = $daysSinceLastInvoice === NULL ? 999999 : $daysSinceLastInvoice; // set a large enough value in case of NULL
                    $amountInvoiced = $alreadyInvoicedAndUnpaid["amount"];
                    $amountUsedAndUninvoiced = 0 - $clientOl['uptodate_credit'] - $amountInvoiced;
                    // Check unsettled Fleio billing histories
                    if ($amountUsedAndUninvoiced > 0 && sizeof($clientOl['unsettled_periods']) > 0 && $daysSinceLastInvoice > 0) {
                        // Invoice if client is not over limit but has reached his billing cycle and no invoice was issued in the previous day
                        $invoicePaymentMethod = $fleioWhmcsService->paymentmethod;
                        // Use the client payment method instead of the Service one
                        // $invoicePaymentMethod = FleioUtils::getClientPaymentMethod($clientFromUUID->id);
                        $invoiceId = FleioUtils::createOverdueClientInvoice($clientFromUUID->id, $amountUsedAndUninvoiced, $fleioWhmcsServiceId, $invoicePaymentMethod);
                        logActivity('Fleio: issued Invoice ID: '. $invoiceId .' for User ID: '. $clientFromUUID->id. ' due to end of cycle for '. $amountUsedAndUninvoiced . ' ' . $alreadyInvoicedAndUnpaid["currency"]["code"]);
                        $amountInvoiced += $amountUsedAndUninvoiced;
                        $amountUsedAndUninvoiced = 0;
                        $numInvoicedClients += 1;
                        FleioUtils::markBillingHistoriesAsInvoiced($flApi, $clientOl['external_billing_id']);
                        if ($capturePaymentImmediately && $clientHasBillingAgreement) {
                            FleioUtils::captureInvoicePayment($invoiceId);
                        }
                        continue;
                    }
                    
                    if ($amountUsedAndUninvoiced > 0 && 
                        $amountUsedAndUninvoiced >= (0 - $clientOl['effective_credit_limit']) &&
                        $daysSinceLastInvoice > 0) 
                    {
                        // Invoice if client is over limit and no unpaid invoice exists to cover it and no invoice was issued in the last few days
                        $invoicePaymentMethod = $fleioWhmcsService->paymentmethod;
                        // Use the Client payment method instead of the service one
                        // $invoicePaymentMethod = FleioUtils::getClientPaymentMethod($clientFromUUID->id);
                        $invoiceId = FleioUtils::createOverdueClientInvoice($clientFromUUID->id, $amountUsedAndUninvoiced, $fleioWhmcsServiceId, $invoicePaymentMethod);
                        logActivity('Fleio: issued Invoice ID: '. $invoiceId .' for User ID: '. $clientFromUUID->id. ' for over credit limit of '. $amountUsedAndUninvoiced . ' ' . $alreadyInvoicedAndUnpaid["currency"]["code"]);
                        $numInvoicedClients += 1;
                        if ($capturePaymentImmediately && $clientHasBillingAgreement) {
                            FleioUtils::captureInvoicePayment($invoiceId);
                        }
                    }
                } else {
                    logActivity('Fleio: unable to retrieve WHMCS client with UUID: '. $clientOl['external_billing_id']);
                }
	       } catch ( Exception $e ) {
                logActivity($e->getMessage());
                continue;
           }
      }
    if ($numInvoicedClients > 0) {
        logActivity('Fleio: invoiced ' . $numInvoicedClients . ' overdue clients' );
    } else {
        logActivity('Fleio: no overdue clients to invoice found on ' . $server->configoption4);
    }
   } catch ( Exception $e ) {
      logActivity('Fleio: unable to retrieve over credit clients from '. $server->configoption4 . ' (' . $e->getMessage() . ')');
      continue;
   }

   // get suspended services in order to update their status in whmcs
   $servicesNext = true;
   $servicePage = 0;
   while($servicesNext) {
       $servicePage = $servicePage + 1;
       try {
            logActivity('Fleio: using server ' . $server->configoption4);
            $servicesUrl = "/billing/services?filtering=product__product_type:openstack%2Bstatus:suspended&page=". (string)$servicePage . "&";
            $suspendedServices = $flApi->get($servicesUrl, array());
            if (!$suspendedServices['next']) {
                $servicesNext = false;
            }
            foreach ($suspendedServices['objects'] as $serviceToSuspend) {
                if ($serviceToSuspend['client']['external_billing_id']) {
                    $clientFromUUID = FleioUtils::getUUIDClient($serviceToSuspend['client']['external_billing_id']);
                    if ($clientFromUUID !== NULL) {
                        $whmcsService = Capsule::table('tblhosting')
                        ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                        ->where('tblproducts.servertype', '=', 'fleio')
                        ->where('tblhosting.userid', '=', $clientFromUUID->id)
                        ->select('tblhosting.domainstatus')
                        ->first();
                        if ($whmcsService->domainstatus !== 'Suspended') {
                            Capsule::table('tblhosting')
                            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                            ->where('tblproducts.servertype', '=', 'fleio')
                            ->where('tblhosting.userid', '=', $clientFromUUID->id)
                            ->update(array("domainstatus"=>'Suspended'));
                            logActivity('Fleio: marking service of client with id ' . $clientFromUUID->id . ' as suspended to reflect Fleio OS service');
                        }
                    }
                }
            }
        } catch (Exception $e) {
            logActivity('Fleio: unable to retrieve suspended services from '. $server->configoption4 . ' (' . $e->getMessage() . ')');
            $servicesNext = false;
            continue;
        }
    }

    // get suspended services in whmcs and resume them if they are active in fleio
    $suspendedServicesInWhmcs = Capsule::table('tblhosting')
    ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
    ->where('tblproducts.servertype', '=', 'fleio')
    ->where('tblhosting.domainstatus', '=', 'Suspended')->get();
    foreach ($suspendedServicesInWhmcs as $whmcsSuspendedService) {
        $relatedClient = Capsule::table('tblclients')
        ->join('tblhosting', 'tblhosting.userid', '=', 'tblclients.id')
        ->where('tblclients.id', '=', $whmcsSuspendedService->userid)
        ->select('tblclients.uuid', 'tblclients.id')
        ->first();
        try {
            $relatedServiceUrl = "/billing/services?filtering=client__external_billing_id:" . $relatedClient->uuid . "&";
            $relatedServiceResponse = $flApi->get($relatedServiceUrl, array());
            if ($relatedServiceResponse['objects']) {
                if ($relatedServiceResponse['objects'][0]['status'] === 'active') {
                    Capsule::table('tblhosting')
                    ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                    ->where('tblproducts.servertype', '=', 'fleio')
                    ->where('tblhosting.userid', '=', $relatedClient->id)
                    ->update(array("domainstatus"=>"Active"));
                    logActivity('Fleio: marking service of client with id ' . $relatedClient->id . ' as active to reflect Fleio OS service');
                }
            }
        } catch (Exception $e) {
            logActivity('Fleio: unable to retrieve fleio services related to whmcs suspended services from '. 
                $server->configoption4 . ' (' . $e->getMessage() . ')');
            continue;
        }
    }

 }
}

function fleio_cronjob($vars) {
    /*
    Function that is executed each time the WHMCS daily cron runs.
    This should check all Fleio products and create invoices for them.
    */
    logActivity('Fleio: cron start');
    $fleioServers = FleioUtils::getFleioProducts();
    foreach($fleioServers as $server) {
        $flApi = new FlApi($server->configoption4, $server->configoption1);
        try {
            $bhistories = FleioUtils::getBillingHistory($flApi, date('Y-m-d'));
        } catch ( Exception $e ) {
            logActivity('Fleio: unable to retrieve billing history. ' . $e->getMessage());
            continue;
        }
        foreach($bhistories as $bhist) {
            $client_uuid = $bhist['client']['external_billing_id'];
            if (!$client_uuid) { continue; }
            $price = $bhist['price']; # FIXME(tomo): Convert to client's currency (make sure it's the same)
            try {
              $client = Capsule::table('tblclients')->where('uuid', '=', $client_uuid)->first();
            } catch (Exception $e) {
              logActivity('Fleio: unable to retrieve client with uuid ' . $client_uuid);
              continue;
            }
            if (!$client) { 
              logActivity('Fleio: no user with uuid: ' . $client_uuid);
              continue; 
            }
            $product = FleioUtils::getClientProduct($client->id, $server->id);
            if (!$product) { 
              logActivity('Fleio: no active products for User ID: ' . $client->id); 
              continue; 
            }
            # Create the invoice
            $postData = [
              'userid' => $client->id,
              'sendinvoice' => '1',
              'itemdescription1' => $product->name,
              'itemamount1' => $price,
              'itemtaxed1' => true];
            $invoice_id = FleioUtils::createFleioInvoice($product->id, $postData);
            logActivity('Fleio: Invoice ID: ' . $invoice_id . ' created for Service ID: ' . $product->id);
        }
    }
}

function fleio_update_invoice_hook($vars) {
    if ($vars['source'] != 'autogen') {
    # created manually in admin or client area or through localAPI (source = 'api'), skip ?
    return;
    }
    $invoice = Capsule::table('tblinvoices')->where('id', '=', $vars["invoiceid"])->first();
    # NOTE(tomo): Select only Hosting type items. Otherwise we will end up with domains and other types being paid for.
    $items = Capsule::table('tblinvoiceitems')->where('invoiceid', '=', $vars["invoiceid"])->get();
    $tax = 0.0;
    $tax2 = 0.0;
    $subtotal_price = 0.0;
    $cost_by_service = array();
    $product_prices = array();
    foreach($items as $item) {
        # NOTE(tomo): Check if relid is set and not an empty string
        if (($item->relid == '') || !(isset($item->relid))) {
           continue;
        }
        if (isset($cost_by_service[$item->relid])) {
            $cost_by_service[$item->relid] += $item->amount;
        } else {
            $cost_by_service[$item->relid] = $item->amount;
        }
    }

    foreach($items as $item) {
        if (($item->type != 'Hosting') || !isset($cost_by_service[$item->relid])) {
           continue;
        }
        $product = Capsule::table('tblinvoiceitems')
                           ->join('tblhosting', 'tblinvoiceitems.relid', '=', 'tblhosting.id')
                           ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                           ->where([['tblinvoiceitems.relid', '=', $item->relid], ['tblproducts.servertype', '=', 'fleio'], ['tblhosting.domainstatus', '<>', 'Pending'], ['tblinvoiceitems.type', '=', 'Hosting']])
                           ->select('tblproducts.servertype', 'tblhosting.domainstatus')->first();
        if ($product === null) {
            continue;
        }
        try {
            $fl = Fleio::fromServiceId($item->relid);
        } catch (Exception $e) {
            logActivity('Fleio: unable to initialize the Fleio API module: ' . $e->getMessage());
            continue;
        }
        # If the product is active, try to get the price from Fleio billing
        try {
            $price = $fl->getBillingPrice();
        } catch (FlApiException $e) {
            logActivity('Fleio: unable to get the billing price for Service ID: ' . $item->relid . ' : ' . $e->getMessage());
            # NOTE(tomo): Deleting the item will cause a retry on the next run, which we want but there
            # are other complications.
            # Also note that an invoice may contain multiple entries with the same relid (eg: Setup and Hosting types costs for a product).
            #Capsule::table('tblinvoiceitems')->where('id', '=', $item->id)->delete();
            #logActivity('Fleio: deleted service ID: ' . $item->relid . ' from Invoice ID: ' . $invoice->id);
            continue;
        }
        # NOTE(tomo): The price of a Fleio item is usually 0, until we set it from Fleio.
        Capsule::table('tblinvoiceitems')
               ->where([['id', (string) $item->id], ['type', '=', 'Hosting']])
               ->increment('amount', $price);
        if ($item->taxed) {
            $tax += $price * $invoice->taxrate / 100;
            $tax2 += $price * $invoice->taxrate2 / 100;
        }
        $subtotal_price += $price;
    }
    $total_price = $subtotal_price + $tax + $tax2;
    if ($total_price > 0) {
        # Capsule::table('tblinvoices')->where('id', '=', $vars['invoiceid'])->update(array("subtotal"=>$total_price, "tax"=>$tax, "tax2"=>$tax2, "total"=>$total_price));
        logActivity('Fleio: incrementing price of Invoice ID: '. $vars['invoiceid'] . ' with ' . $total_price . ' and tax with ' . $tax . ' and tax2 with ' . $tax2);
        Capsule::table('tblinvoices')->where('id', '=', $vars['invoiceid'])->increment('subtotal', $subtotal_price);
        Capsule::table('tblinvoices')->where('id', '=', $vars['invoiceid'])->increment('total', $total_price);
        Capsule::table('tblinvoices')->where('id', '=', $vars['invoiceid'])->increment('tax', $tax);
        Capsule::table('tblinvoices')->where('id', '=', $vars['invoiceid'])->increment('tax2', $tax2);
        logActivity('Fleio: prices and taxes updated for Invoice ID: ' . $vars['invoiceid']);
    }
}

function openstack_change_funds($invoiceid, $substract=False) {
    /*
    Check all invoice items and for each Fleio product, either add or
    remove credit based on the total item costs and the action performed.
    */
    $items = Capsule::table('tblinvoiceitems')->where('invoiceid', '=', $invoiceid)->get();

    // Retrieve the invoice total paid.
    // If this invoice was not fully paid, we do not substract from Fleio.
    // There is no other way to prevent subtracting from Fleio when cancelling an invoice and marking it unpaid afterwards
    try {
        $balance = Capsule::table('tblaccounts as ta')
                        ->where('ta.invoiceid', '=', $invoiceid)
                        ->join('tblinvoices as ti', 'ta.invoiceid', '=', 'ti.id')
                        ->select(Capsule::raw('SUM(ta.amountin)-SUM(ta.amountout)-ti.total as balance'))
                        ->value('balance');
    } catch (Exception $e) {
        logActivity($e->getMessage());
        $balance = false;
    }  
    $cost_by_service = array();
    $promo_by_service = array();
    foreach($items as $item) {
        # NOTE(tomo): Check if relid is set and not an empty string
        if (($item->relid == '') || !isset($item->relid)) {
           continue;
        }
    if ($item->type == 'PromoHosting') {
            # NOTE(tomo): Do nothing with $promo_by_service. Just don't add it
            #             to the final amount to avoid incorrect credit addition in Fleio
            if (isset($promo_by_service[$item->relid])) {
            $promo_by_service[$item->relid] += $item->amount;
            } else {
                $promo_by_service[$item->relid] = $item->amount;
            }
        } else {
            if (isset($cost_by_service[$item->relid])) {
                $cost_by_service[$item->relid] += $item->amount;
            } else {
                $cost_by_service[$item->relid] = $item->amount;
            }
        }
    }

    foreach($items as $item) {
        if (($item->type != 'Hosting') || !isset($cost_by_service[$item->relid])) {
           continue;
        }
        # We now know that relid is a Hosting package (not a Domain for example)
        $service = FleioUtils::getServiceById($item->relid);
        if ($service->servertype == 'fleio') {
            # NOTE(tomo): Make sure the service is active. If it's not active and we don't handle this, the credit is lost.
            # NOTE(tomo): We currently handle this in the add/remove credit methods.
            $clientCurrency = getCurrency($item->userid);
            $defaultCurrency = getCurrency();
            $clientAmount = $cost_by_service[$item->relid]; // Amount + Setup and/or other related prices in client's currency
            # NOTE(tomo): Fleio needs to use the WHMCS default currency
            $amount = convertCurrency($clientAmount, 1, $clientCurrency['id']);  // Amount in default currency.
            if ($amount == 0) {
               logActivity('Fleio: ignoring Service ID: '. $item->relid . ' with cost equal to 0 from Invoice ID: ' . $invoiceid);
               continue;
            }
            if ($substract && $balance != false && $balance >= 0) {
               logActivity('Fleio: ignoring Service ID: '. $item->relid .' from Invoice ID: ' . $invoiceid . ' with status Unpaid but fully paid');
               continue;
            }
            $fl = Fleio::fromServiceId($item->relid);
            if ($substract) {
                $msg_format = "Fleio: removing credit for WHMCS User ID: %s with %.02f %s (%.02f %s from Invoice ID: %s)";
            } else {
                $msg_format = "Fleio: adding credit for WHMCS User ID: %s with %.02f %s (%.02f %s from Invoice ID: %s)";
            }
            $msg = sprintf($msg_format, $item->userid, $amount, $defaultCurrency["code"], $clientAmount, $clientCurrency["code"], $invoiceid);
            logActivity($msg);
            # TODO(tomo): We use the userid which can be a contact ?
            try {
                $addCredit = (!$subtract);  // Add credit or subtract, boolean
                $response = $fl->clientChangeCredit($addCredit, $amount, $defaultCurrency["code"], $clientCurrency["rate"], $clientAmount, $clientCurrency["code"], $invoiceid);
            } catch (FlApiException $e) {
                logActivity("Unable to update the client credit in Fleio: " . $e->getMessage()); 
                return;
            }
            logActivity("Fleio: successfully changed client credit with ".$amount." ".$defaultCurrency["code"]. " for Fleio client id: ".$response['client'].". New Fleio balance: ".$response['credit_balance']." ".$defaultCurrency["code"]); 
        }
    }
}


function openstack_add_funds_hook($vars) { openstack_change_funds($vars["invoiceid"]); }

function openstack_del_credit_hook($vars) { openstack_change_funds($vars["invoiceid"], True); }

function fleio_ClientAreaPrimarySidebar(MenuItem $pn) {
    $actionsNav = $pn->getChild("Service Details Actions");
    if (is_null($actionsNav)) {
        return;
    }
    $navItem = $actionsNav->getChild('Custom Module Button Login to Fleio');
    if (!is_null($navItem)) {
        $navItem->setAttribute("target", '_blank');
    }
}

function fleio_client_edit($vars) {
    try {
        $product = FleioUtils::getClientProduct($vars["userid"]);
    } catch (Exception $e) {
        return;
    }
    // if this client has no OpenStack products, return
    if (!$product) { return; }
    else {
        $details = array("first_name" => $vars["firstname"],
                         "last_name" => $vars["lastname"],
                         "address1" => $vars["address1"],
                         "address2" => $vars["address2"],
                         "city" => $vars["city"],
                         "state" => $vars["state"],
                         "email" => $vars["email"],
                         "zip_code" => $vars["postcode"],
                         "country" => $vars["country"],
                         "company" => $vars["companyname"],
                         "phone" => $vars["phonenumber"]);
    }
    try {
        $fl = Fleio::fromServiceId($product->id);
        return $fl->updateFleioClient($details);
    } catch (Exception $e) {
        // FIXME(tomo): Catch all just in case...
        return;
    }
}

