<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/api.php';
use Illuminate\Database\Capsule\Manager as Capsule;
use WHMCS\View\Menu\Item as MenuItem;

add_hook("InvoicePaid", 99, "openstack_add_funds_hook", "");
add_hook("InvoiceUnpaid", 99, "openstack_del_credit_hook");
add_hook("InvoiceRefunded", 99, "openstack_del_credit_hook");
add_hook("AfterModuleCreate", 99, "openstack_add_initial_credit");
add_hook("ClientAreaPrimarySidebar", 99, "fleio_ClientAreaPrimarySidebar");
add_hook("ClientAreaPrimaryNavbar", 99, "fleio_ClientAreaPrimaryNavbar");
add_hook("InvoiceCreation", 99, "fleio_update_invoice_hook");

function fleio_update_invoice_hook($vars) {
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
            $fl = Fleio::fromProdId($item->relid);
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
        if ($item->type == 'fleio') {
            $currency = getCurrency($item->userid);
            $defaultCurrency = getCurrency();
            $convertedAmount = $item->amount; // Amount in client's currency
            $amount = convertCurrency($convertedAmount, $currency['id']);  // Amount in default currency
            if ($substract) {
                $convertedAmount = (-1 * $convertedAmount);
                $amount = (-1 * $amount);
            }
            $fl = Fleio::fromProdId($item->relid);
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


function openstack_add_funds_hook($vars) {
    # Ignore the invoice if it's related to a fleio product creation (the initial invoice).
    # TODO(tomo): Find a better way. This is here to avoid a double credit addition triggerd by InvoicePaid and PostServiceCreate events
    $order = Capsule::table('tblorders')->where('invoiceid', '=', $vars["invoiceid"])->first();
    if (!isset($order)) {
        openstack_change_funds($vars["invoiceid"]);
    }
}

function openstack_del_credit_hook($vars) {
    openstack_change_funds($vars["invoiceid"], True);
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

function openstack_add_initial_credit($vars) {
    $params = $vars['params'];
    if ($params['moduletype'] != "fleio") { return ""; }
    $invoice = Capsule::table('tblhosting')
        ->join('tblorders', 'tblorders.id', '=', 'tblhosting.orderid')
        ->join('tblinvoices', 'tblorders.invoiceid', '=', 'tblinvoices.id')
        ->where('tblhosting.id', '=', (string) $params['serviceid'])
        ->select('tblinvoices.*')
        ->first();
    if (!isset($invoice->id)) {
        return "";
    }
    Capsule::table('tblinvoiceitems')
            ->where('invoiceid', (string) $invoice->id)
            ->where('relid', (string) $params['serviceid'])
            ->update(array("type"=>"fleio")); 
    if ($invoice->status == 'Paid') {
        logActivity("Adding initial Fleio credit from Invoice ID: " . (string) $invoice->id);
        openstack_change_funds($invoice->id);
    } else {
        logActivity("Invoice status is unpaid, not adding credit in Fleio");
    }
}
