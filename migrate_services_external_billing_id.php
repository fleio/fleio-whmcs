<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$whmcsServicesCount = Capsule::table('tblhosting')
                        ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                        ->join('tblclients as tc', 'tc.id', '=', 'tblhosting.userid')
                        ->where('tblproducts.servertype', '=', 'fleio')
                        ->count();


$whmcsClients = Capsule::table('tblclients')
                ->select('tblclients.id', 'tblclients.uuid')
                ->get();


echo 'There are '. $whmcsServicesCount . ' fleio services';
echo "\r\n";

$processedServices = 0;
foreach($whmcsClients AS $whmcsClient) {
        $whmcsClientServices = Capsule::table('tblhosting')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->where('tblproducts.servertype', '=', 'fleio')
                ->where('tblhosting.userid', '=', $whmcsClient->id)
                ->select('tblhosting.id', 'tblhosting.domainstatus')
                ->get();
        $servicesStatusesCountMap = array(
                "Active" => array(
                        "count" => 0,
                        "id_list" => array()
                ),
                "Suspended" => array(
                        "count" => 0,
                        "id_list" => array()
                ),
                "Terminated" => array(
                        "count" => 0,
                        "id_list" => array()
                ),
                "Cancelled" => array(
                        "count" => 0,
                        "id_list" => array()
                ),
                "Fraud" => array(
                        "count" => 0,
                        "id_list" => array()
                ),
                "Pending" => array(
                        "count" => 0,
                        "id_list" => array()
                )
        );
        foreach($whmcsClientServices AS $whmcsClientService) {
                $servicesStatusesCountMap[$whmcsClientService->domainstatus]["count"] = $servicesStatusesCountMap[$whmcsClientService->domainstatus]["count"] + 1;
                array_push($servicesStatusesCountMap[$whmcsClientService->domainstatus]["id_list"], $whmcsClientService->id);
                $processedServices = $processedServices + 1;
        }
        if ($servicesStatusesCountMap["Active"]["count"] > 1) {
                // if more than one active found, we cannot automatically determine
                echo "Cannot process services for client " . $whmcsClient->id . " : More than one active service found\n";
        } else if ($servicesStatusesCountMap["Active"]["count"] == 1) {
                // if only one active, update it in fleio
                try {
                        $flApi = Fleio::fromServiceId($servicesStatusesCountMap["Active"]["id_list"][0]);
                        $flApi->updateServiceExternalBillingId($servicesStatusesCountMap["Active"]["id_list"][0], $whmcsClient->uuid);
                } catch (Exception $e) { 
                        echo ''.$e->getMessage(); 
                }
        } else if ($servicesStatusesCountMap["Suspended"]["count"] > 1) {
                // if none active found and multiple suspended found, we cannot determine
                echo "Cannot process services for client " . $whmcsClient->id . " : More than one suspended service found and none active\n";
        } else if ($servicesStatusesCountMap["Suspended"]["count"] == 1) {
                // if none active but one suspended set it
                try {
                        $flApi = Fleio::fromServiceId($servicesStatusesCountMap["Suspended"]["id_list"][0]);
                        $flApi->updateServiceExternalBillingId($servicesStatusesCountMap["Suspended"]["id_list"][0], $whmcsClient->uuid);
                } catch (Exception $e) { 
                        echo ''.$e->getMessage(); 
                }
        } else if ($servicesStatusesCountMap["Terminated"]["count"] > 0 && $servicesStatusesCountMap["Cancelled"]["count"] > 0) {
                // if none active or suspended but at least one terminated and cancelled, we cannot determine
                echo "Cannot process services for client " . $whmcsClient->id . " : Has no active or suspended service and has at least one terminated and at least one cancelled service.\n";
        } else if ($servicesStatusesCountMap["Terminated"]["count"] > 1) {
                // if none active, suspended or cancelled but multiple terminated we cannot determine
                echo "Cannot process services for client " . $whmcsClient->id . " : Has no active, suspended or cancelled service and has multiple terminated services.\n";
        } else if ($servicesStatusesCountMap["Cancelled"]["count"] > 1) {
                // if none active, suspended or terminated but multiple cancelled we cannot determine
                echo "Cannot process services for client " . $whmcsClient->id . " : Has no active, suspended or terminated service and has multiple cancelled services.\n";
        } else if ($servicesStatusesCountMap["Cancelled"]["count"] == 1) {
                // if no other status than one of cancelled (excluding pending), update it in fleio
                try {
                        $flApi = Fleio::fromServiceId($servicesStatusesCountMap["Cancelled"]["id_list"][0]);
                        $flApi->updateServiceExternalBillingId($servicesStatusesCountMap["Cancelled"]["id_list"][0], $whmcsClient->uuid);
                } catch (Exception $e) { 
                        echo ''.$e->getMessage(); 
                }
        } else if ($servicesStatusesCountMap["Terminated"]["count"] == 1) {
                // if no other status than one of terminated (excluding pending), update it in fleio
                try {
                        $flApi = Fleio::fromServiceId($servicesStatusesCountMap["Terminated"]["id_list"][0]);
                        $flApi->updateServiceExternalBillingId($servicesStatusesCountMap["Terminated"]["id_list"][0], $whmcsClient->uuid);
                } catch (Exception $e) { 
                        echo ''.$e->getMessage(); 
                }
        } else if ($servicesStatusesCountMap["Fraud"]["count"] > 1) {
                // if none active, suspended, terminated or cancelled but multiple fraud we cannot determine
                echo "Cannot process services for client " . $whmcsClient->id . " : Has no active, suspended, terminated or cancelled services and has multiple fraud services.\n";
        } else if ($servicesStatusesCountMap["Fraud"]["count"] == 1) {
                // if no other status than one of fraud (excluding pending), update it in fleio
                try {
                        $flApi = Fleio::fromServiceId($servicesStatusesCountMap["Fraud"]["id_list"][0]);
                        $flApi->updateServiceExternalBillingId($servicesStatusesCountMap["Fraud"]["id_list"][0], $whmcsClient->uuid);
                } catch (Exception $e) { 
                        echo ''.$e->getMessage(); 
                }
        }
}
echo 'Processed services count: '.$processedServices;

?>
