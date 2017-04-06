<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . DIRECTORY_SEPARATOR . 'api.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'utils.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use WHMCS\View\Menu\Item as MenuItem;

add_hook("InvoicePaid", 99, "openstack_add_funds_hook", "");
add_hook("InvoiceUnpaid", 99, "openstack_del_credit_hook");
add_hook("InvoiceRefunded", 99, "openstack_del_credit_hook");
add_hook("ClientAreaPrimarySidebar", 99, "fleio_ClientAreaPrimarySidebar");
add_hook("ClientAreaPrimaryNavbar", 99, "fleio_ClientAreaPrimaryNavbar");
add_hook("InvoiceCreation", 99, "fleio_update_invoice_hook");
add_hook("DailyCronJob", 99, "fleio_cronjob");

function fleio_cronjob($vars) {
    logActivity('Fleio: daily cron start');
    $fleioServers = FleioUtils::getFleioProducts();
    foreach($fleioServers as $server) {
        $flApi = new FlApi($server->configoption4, $server->configoption1);
        try {
            $bhistories = FleioUtils::getBillingHistory($flApi, date('Y-m-d'));
        } catch ( Exception $e ) {
            logActivity('Fleio: unable to retrieve billing history. ' . $e->getMessage());
            continue;
        }
        logActivity('Fleio: got ' . count($bhistories) . ' client logs from Fleio Product ID: ' . $server->id);
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
		  $product = FleioUtils::getClientProduct($client->id);
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
    $items = Capsule::table('tblinvoiceitems')->where('invoiceid', '=', $vars["invoiceid"])->get();
    $tax = 0.0;
    $tax2 = 0.0;
    $subtotal_price = 0.0;
    foreach($items as $item) {
        $product = Capsule::table('tblinvoiceitems')
                           ->join('tblhosting', 'tblinvoiceitems.relid', '=', 'tblhosting.id')
                           ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                           ->where('tblinvoiceitems.relid', '=', $item->relid)
                           ->select('tblproducts.servertype', 'tblhosting.domainstatus')->first();
        if ($product->servertype != 'fleio' || $product->domainstatus == 'Pending') {
            # Item on invoice is not related to fleio, or this is a pending service and
            # the account is not created yet. Continue to the next invoice item
            continue;
	    }
        try {
            $fl = Fleio::fromServiceId($item->relid);
        } catch (Exception $e) {
            logActivity('Unable to initialize the Fleio module: ' . $e->getMessage());
            continue;
        }
        # If the product is active, try to get the price from Fleio billing
        try {
            $price = $fl->getBillingPrice();
        } catch (FlApiRequestException $e) {
            logActivity($e->getMessage());
            # NOTE(tomo): Deleting the item will cause a retry on the next run, which we want but there
            # are other complications. Comment for now and set the price to 0
            Capsule::table('tblinvoiceitems')->where('id', '=', $item->id)->delete();
            continue;
        }
        Capsule::table('tblinvoiceitems')
               ->where('id', (string) $item->id)
               ->increment('amount', $price);
        if ($item->taxed) {
            $tax += $price * $invoice->taxrate / 100;
            $tax2 += $price * $invoice->taxrate2 / 100;
        }
        $subtotal_price += $price;
    }
    $total_price = $subtotal_price + $tax + $tax2;
    if ($total_price > 0) {
        #Capsule::table('tblinvoices')->where('id', '=', $vars["invoiceid"])->delete();
        #logactivity('Invoice with id '.((string) $vars["invoiceid"]).' deleted');
        Capsule::table('tblinvoices')->where('id', '=', $vars["invoiceid"])->update(array("subtotal"=>$total_price, "tax"=>$tax, "tax2"=>$tax2, "total"=>$total_price));
    }
}

function openstack_change_funds($invoiceid, $substract=False) {
    $items = Capsule::table('tblinvoiceitems')->where('invoiceid', '=', $invoiceid)->get();
    foreach($items as $item) {
        $service = FleioUtils::getServiceById($item->relid);
        if ($service->servertype == 'fleio') {
            # NOTE(tomo): Make sure the service is active. If it's not active and we don't handle this, the credit is lost.
            # NOTE(tomo): We currently handle this in the updateCredit method.
            $currency = getCurrency($item->userid);
            $defaultCurrency = getCurrency();
            $convertedAmount = $item->amount; // Amount in client's currency
            $amount = convertCurrency($convertedAmount, $currency['id']);  // Amount in default currency
            if ($substract) {
                $convertedAmount = (-1 * $convertedAmount);
                $amount = (-1 * $amount);
            }
            $fl = Fleio::fromServiceId($item->relid);
            $msg_format = "Changing Fleio credit for WHMCS User ID: %s with %.02f %s (%.02f %s from Invoice ID: %s)";
            $msg = sprintf($msg_format, $item->userid, $amount, $defaultCurrency["code"], $convertedAmount, $currency["code"], $invoiceid);
            logActivity($msg);
            # TODO(tomo): We use the userid which can be a contact ?
            try {
                $response = $fl->updateCredit($amount, $currency["code"], $currency["rate"], $convertedAmount, $invoiceid);
            } catch (FlApiException $e) {
                logActivity("Unable to update the client credit in Fleio: " . $e->getMessage()); 
                return;
            }
            logActivity("Successfully changed credit with ".$amount." ".$defaultCurrency["code"]." for Fleio client id: ".$response['client'].". New Fleio balance: ".$response['credit_balance']); 
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

