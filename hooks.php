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
# add_hook("AfterModuleCreate", 99, "fleio_add_initial_credit");
add_hook("ClientAreaPrimarySidebar", 99, "fleio_ClientAreaPrimarySidebar");
add_hook("ClientAreaPrimaryNavbar", 99, "fleio_ClientAreaPrimaryNavbar");
add_hook("InvoiceCreation", 99, "fleio_update_invoice_hook");
add_hook("DailyCronJob", 99, "fleio_cronjob");
add_hook("AdminClientServicesTabFieldsSave", 99, "fleio_cronjob");


function fleio_cronjob($vars) {
    logActivity('Fleio: daily cron start');
    $fleioServers = FleioUtils::getFleioProducts();
    foreach($fleioServers as $server) {
        $flApi = new FlApi($server->configoption4, $server->configoption1);
        try {
            $bhistories = FleioUtils::getBillingHistory($flApi, date('Y-m-d'));
        } catch ( Exception $e ) {
            logActivity('Fleio: unable to retrieve billing history. ' . $e->getMessage());
            return;
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
            logActivity('Fleio: no active products for User ID: ' . $client->id, $client->id); 
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
        }
    }
}

function fleio_update_invoice_hook($vars) {
    logActivity('Invoice Creation');
    logActivity(print_r($vars, true));
    if ($vars['source'] != 'autogen') {
	# created manually in admin or client area, skip ?
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
            # Item on invoice is not related to fleio, or this is a pending order and
            # the account is not created yet. Continue to the next invoice item
            continue;
	}
        try {
            $fl = Fleio::fromServiceId($item->relid);
        } catch (Exception $e) {
            logActivity('Unable to initialize the Fleio module: ' . $e->getMessage());
            return;
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
        if (FleioUtils::isFleioService($item->relid)) {
            # Make sure the service is active. If it's not active, the credit will get lost! TODO(tomo)
            $service = FleioUtils::getServiceById($item->relid);
            if ($service->domainstatus != 'Active') { logActivity('Ignoring inactive fleio Service ID: ' . $item->relid); continue; }
            # If the service is active, continue with the invoice.
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
                $response = $fl->updateCredit($amount, $currency["code"], $currency["rate"], $convertedAmount);
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

/*
function fleio_add_initial_credit($vars) {
    logActivity('After Module Create');
    $params = $vars['params'];
    if ($params['moduletype'] != 'fleio') { return ''; }
    # Get the first invoice (associated with the order)
    $invoice = Capsule::table('tblhosting')
        ->join('tblorders', 'tblorders.id', '=', 'tblhosting.orderid')
        ->join('tblinvoices', 'tblorders.invoiceid', '=', 'tblinvoices.id')
        ->where('tblhosting.id', '=', (string) $params['serviceid'])
        ->select('tblinvoices.status', 'tblinvoices.id')
        ->first();
    if (!isset($invoice->id)) {
        return '';
    }
    # Get only the items associated with fleio (an invoice can contain any number/types of items)
    Capsule::table('tblinvoiceitems')
            ->where('invoiceid', (string) $invoice->id)
            ->where('relid', (string) $params['serviceid'])
            ->update(array("type"=>"Hosting"));
    if ($invoice->status == 'Paid') {
        logActivity("Adding initial Fleio credit from Invoice ID: " . (string) $invoice->id);
        try {
          openstack_change_funds($invoice->id);
        } catch (Exception $e) {
          FleioUtils::addQueueTask($params['serviceid'], 'AddInitialCreditRetry', $e->getMessage());
          logActivity('Unable to add initial credit from Invoice ID: ' . $invoice->id . ' ;' . $e->getMessage());
        }
    } else {
        logActivity("Invoice ID: " . $invoice->id . " status is unpaid, not adding credit in Fleio");
    }
}
*/
