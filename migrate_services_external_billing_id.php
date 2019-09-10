<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$whmcsServices = Capsule::table('tblhosting')
                        ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                        ->join('tblclients as tc', 'tc.id', '=', 'tblhosting.userid')
                        ->where('tblproducts.servertype', '=', 'fleio')
                        ->select('tblhosting.id', 'tc.uuid')
                        ->get();

foreach($whmcsServices AS $whmcsService) {
	$flApi = Fleio::fromServiceId($whmcsService->id);
	$clientUUID = $whmcsService->uuid;
	$flApi->updateServiceExternalBillingId($whmcsService->id, $clientUUID);
}

?>
