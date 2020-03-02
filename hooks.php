<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'api.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'utils.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use WHMCS\View\Menu\Item as MenuItem;

add_hook("InvoicePaid", 99, "openstack_add_funds_hook", "");
add_hook("InvoiceRefunded", 99, "openstack_del_credit_hook");
add_hook("ClientAreaPrimarySidebar", 99, "fleio_ClientAreaPrimarySidebar");
add_hook("ClientAreaPrimaryNavbar", 99, "fleio_ClientAreaPrimaryNavbar");
add_hook("InvoiceCreation", 99, "fleio_update_invoice_hook");
add_hook("ClientEdit", 99, "fleio_client_edit");
//add_hook("DailyCronJob", 99, "fleio_PostCronjob");
add_hook("AfterCronJob", 99, "fleio_PostCronjob");
add_hook("ShoppingCartValidateCheckout", 1, "limitOrders");

function fleio_PostCronjob() {
    // Retrieve a list of all Fleio Clients that have external billing set and have reached their credit limit or have 
    // unsettled billing histories
    $fleioServers = FleioUtils::getFleioProducts();    // get all WHMCS products associated with the Fleio module
    foreach($fleioServers as $server) {
        $invoiceWithAgreement = $server->configoption11 == 'on' ? true : false; // invoice clients with billing agreement
        $invoiceWithoutAgreement = $server->configoption10 == 'on' ? true : false;    // invoice clients without billing agreement
        $capturePaymentImmediately = $server->configoption12 == 'on' ? true : false; // Attempt to capture payment immediately
        if ($invoiceWithAgreement && $invoiceWithoutAgreement) {
            logActivity('Fleio: Looking at clients with and without a billing agreement');
        };
        $flApi = new FlApi($server->configoption4, $server->configoption1);
        FleioUtils::updateClientsBillingAgreement(
            $flApi,
            $capturePaymentImmediately,
            'Active',
            $server->configoption13,
            $server->configoption15,
            $server->configoption16
        );
        logActivity('Fleio: retrieving all overdue clients');
        $url = "/clients/get_clients_to_invoice";
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
            foreach ($clientsOverLimit as $clientOl) {
                $invoiceProcessingUrl = sprintf('/clients/%s/get_client_for_invoice_processing', $clientOl['id']);
                try {
                    $clientToProcess = $flApi->get($invoiceProcessingUrl, array());
                } catch ( Exception $e ) {
                    $clientToProcess = NULL;
                    logActivity(
                        'Fleio: error when trying to get fleio client (' . $clientOl['id'] .
                        ') in order to process and invoice him: ' . $e->getMessage()
                    );
                }
                if ($clientToProcess) {
                    try {
                        $clientFromUUID = FleioUtils::getUUIDClient($clientToProcess['external_billing_id']);
                        if ($clientFromUUID != NULL) {
                            try {
                                $generatedInvoiceId = FleioUtils::invoiceClient(
                                    $clientFromUUID,
                                    $clientToProcess,
                                    $server->configoption14,
                                    FleioUtils::getFleioProductsInvoicedAmount($clientFromUUID->id, $server->id)
                                );
                            } catch ( Exception $e ) {
                                logActivity(
                                    'Fleio: error when trying to invoice client ' .
                                    $clientToProcess['external_billing_id'] . ': ' . $e->getMessage()
                                );
                                $generatedInvoiceId = NULL;
                            }
                            if ($generatedInvoiceId) {
                                $numInvoicedClients += 1;
                                // TODO: take billing agreement status of client from fleio response from fleio 2020.03
                                $clientHasBillingAgreementResponse = FleioUtils::clientHasBillingAgreement(
                                    $clientFromUUID->id,
                                    $server->configoption13
                                );
                                $clientHasBillingAgreement = $clientHasBillingAgreementResponse['hasAgreement'];
                                if ($capturePaymentImmediately && $clientHasBillingAgreement) {
                                    $captured = FleioUtils::captureInvoicePayment($generatedInvoiceId);
                                    if ($captured === false && $server->configoption16 === '1') {
                                        // capture failed and setting says the client is no more on agreement
                                        FleioUtils::removeClientBillingAgreement(
                                            $flApi, $clientToProcess['external_billing_id']
                                        );
                                    }
                                }
                            } else {
                                // re-set the status of invoiced periods
                                FleioUtils::resetInvoicedPeriodsStatus($flApi, $clientToProcess);
                            }
                        } else {
                            logActivity(
                                'Fleio: unable to retrieve WHMCS client with UUID: ' .
                                $clientToProcess['external_billing_id']
                            );
                            // re-set the status of invoiced periods
                            FleioUtils::resetInvoicedPeriodsStatus($flApi, $clientToProcess);
                            continue;
                        }
                    } catch ( Exception $e ) {
                        logActivity($e->getMessage());
                        // re-set the status of invoiced periods
                        FleioUtils::resetInvoicedPeriodsStatus($flApi, $clientToProcess);
                        continue;
                    }
                }
            }
            if ($numInvoicedClients > 0) {
                logActivity('Fleio: invoiced ' . $numInvoicedClients . ' overdue clients' );
            } else {
                logActivity('Fleio: no overdue clients to invoice found on ' . $server->configoption4);
            }
        } catch ( Exception $e ) {
            logActivity(
                'Fleio: unable to retrieve over credit clients from '. $server->configoption4 . ' (' .
                $e->getMessage() . ')'
            );
            continue;
        }

        FleioUtils::markWhmcsSuspendedServices($server->configoption4, $flApi);

        FleioUtils::markWhmcsActiveServices($server->configoption4, $flApi);

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

function openstack_change_funds($invoiceid, $subtract=false) {
    /*
    Check all invoice items and for each Fleio product, either add or
    remove credit based on the total item costs and the action performed.
    */
    $items = Capsule::table('tblinvoiceitems')->where('invoiceid', '=', $invoiceid)->get();

    // Retrieve the invoice total paid.
    // If this invoice was not fully paid, we do not subtract from Fleio.
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
            $amount = convertCurrency($clientAmount, 1, $clientCurrency['id']);    // Amount in default currency.
            if ($amount == 0) {
                logActivity('Fleio: ignoring Service ID: '. $item->relid . ' with cost equal to 0 from Invoice ID: ' . $invoiceid);
                continue;
            }
            $fl = Fleio::fromServiceId($item->relid);
            # TODO(tomo): We use the userid which can be a contact ?
            try {
                $addCredit = !$subtract;    // Add credit or subtract, boolean
                if ($addCredit) {
                    $msg_format = "Fleio: adding credit for WHMCS User ID: %s with %.02f %s (%.02f %s from Invoice ID: %s)";
                } else {
                    $msg_format = "Fleio: removing credit for WHMCS User ID: %s with %.02f %s (%.02f %s from Invoice ID: %s)";
                }
                $msg = sprintf($msg_format, $item->userid, $amount, $defaultCurrency["code"], $clientAmount, $clientCurrency["code"], $invoiceid);
                logActivity($msg);
                $response = $fl->clientChangeCredit($addCredit, $amount, $defaultCurrency["code"], $clientCurrency["rate"], $clientAmount, $clientCurrency["code"], $invoiceid);
            } catch (FlApiException $e) {
                logActivity("Unable to update the client credit in Fleio: " . $e->getMessage()); 
                return;
            }
            logActivity("Fleio: successfully changed client credit with ".$amount." ".$defaultCurrency["code"]. " for Fleio client id: ".$response['client'].". New Fleio balance: ".$response['credit_balance']." ".$defaultCurrency["code"]); 
        }
    }
}


function openstack_add_funds_hook($vars) {
    openstack_change_funds($vars["invoiceid"]);
}

function openstack_del_credit_hook($vars) {
    openstack_change_funds($vars["invoiceid"], true);
}

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
    if (!$product) {
        return;
    }
    else {
        $details = array(
            "first_name" => $vars["firstname"],
            "last_name" => $vars["lastname"],
            "address1" => $vars["address1"],
            "address2" => $vars["address2"],
            "city" => $vars["city"],
            "state" => $vars["state"],
            "email" => $vars["email"],
            "zip_code" => $vars["postcode"],
            "country" => $vars["country"],
            "company" => $vars["companyname"],
            "phone" => $vars["phonenumber"]
        );
    }
    try {
        $fl = Fleio::fromServiceId($product->id);
        return $fl->updateFleioClient($details);
    } catch (Exception $e) {
        // FIXME(tomo): Catch all just in case...
        return;
    }
}

function limitOrders($vars) {
    // doesn't let users order more than one product of type fleio
    $productsInCart = $_SESSION['cart']['products'];
    $fleioRelatedServicesInCart = 0;
    foreach ($productsInCart as $product) {
        $dbProduct = Capsule::table('tblproducts')
                        ->select('tblproducts.servertype')
                        ->where('tblproducts.id', '=', $product['pid'])
                        ->first();
        if ($dbProduct->servertype === 'fleio') {
            $fleioRelatedServicesInCart = $fleioRelatedServicesInCart + 1;
            if ($fleioRelatedServicesInCart > 1) {
                global $errormessage;
                $errormessage = "<li>Cloud products are limited to one per customer. Please remove other cloud products from cart.</li>";
            }
            if ($_SESSION['uid']) {
                $otherServices = Capsule::table('tblhosting')
                                    ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                                    ->where('tblproducts.servertype', '=', 'fleio')
                                    ->where('tblhosting.userid', '=', $_SESSION['uid'])
                                    ->select('tblhosting.domainstatus')
                                    ->get();
                $otherServicesCount = 0;
                $otherServicesFraudCount = 0;
                foreach ($otherServices as $otherFleioService) {
                    $otherServicesCount = $otherServicesCount + 1;
                    if ($otherFleioService->domainstatus === 'Fraud') {
                        $otherServicesFraudCount = $otherServicesFraudCount + 1;
                    }
                }
                if ($otherServicesCount > 0 && $otherServicesCount !== $otherServicesFraudCount) {
                    // throw error if user has any other fleio related service that isn't marked as fraud
                    global $errormessage;
                    $errormessage = "<li>Cloud products are limited to one per customer. Contact support if you need help.</li>";
                }
            }
        }
    }
}
