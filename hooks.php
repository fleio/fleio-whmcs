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
add_hook("ClientAreaPrimarySidebar", 99, "fleio_ClientAreaPrimaryNavbar");

function openstack_change_funds($invoiceid, $substract=False) {
    $items = Capsule::table('tblinvoiceitems')->where('invoiceid', '=', $invoiceid)->get();
    foreach($items as $item) {
        if ($item->type == 'fleio') {
            $currency = getCurrency($item->userid);
            $defaultCurrency = getCurrency();
            $amount = $item->amount;
            $convertedAmount = convertCurrency($amount, $currency['id']);
            if ($substract) {
                $converted_amount = (-1 * $converted_amount);
                $amount = (-1 * $amount);
            }
            $fl = Fleio::fromProdId($item->relid);
            $msg_format = "Changing Fleio credit for WHMCS User ID: %s with %.02f %s (%.02f %s from Invoice ID: %s)";
            $msg = sprintf($msg_format, $item->userid, $convertedAmount, $defaultCurrency["code"], $amount, $currency["code"], $invoiceid);
            logActivity($msg);
            # TODO(tomo): We use the userid which can be a contact ?
            try {
                $response = $fl->updateCredit($amount, $currency["code"], $currency["rate"], $convertedAmount);
            } catch (FlApiException $e) {
                logActivity("Unable to update the client credit in Fleio: " . $e->getMessage()); 
                return;
            }
            logActivity("Successfully changed credit with ".$convertedAmount." ".$defaultCurrency["code"]." for Fleio client id: ".$response['client'].". New Fleio balance: ".$response['credit_balance']); 
        }
    }
}


function openstack_add_funds_hook($vars) {
    openstack_change_funds($vars["invoiceid"]);
}

function openstack_del_credit_hook($vars) {
    openstack_change_funds($vars["invoiceid"], True);
}

function fleio_ClientAreaPrimaryNavbar(MenuItem $pn) {
    $actionsNav = $pn->getChild("Service Details Actions");
    if (is_null($actionsNav)) {
        return;
    }
    logActivity($type);
    $navItem = $actionsNav->getChild('Custom Module Button Login to Fleio');
    if (!is_null($navItem)) {
        $navItem->setAttribute("target", '_blank');
    }
}
