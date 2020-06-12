<?php
# Move this file to the main installation directory

use WHMCS\ClientArea;
use WHMCS\Database\Capsule;


define('CLIENTAREA', true);
//define('FORCESSL', true); // Uncomment to force the page to use https://

require getcwd() . '/init.php';

$ca = new ClientArea();
$ca->setPageTitle('Fleio SSO login');
$ca->initPage();

$ca->requireLogin();

// Check login status
if ($ca->isLoggedIn()) {
    try {
        $prod = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->where('tblhosting.userid', '=', $ca->getUserID())
            ->whereIn('tblhosting.domainstatus', ['Active', 'Suspended'])
            ->where('tblproducts.servertype', '=', 'fleio')
            ->select('tblhosting.id')
            ->first();
    } catch (Exception $e) {
        header('Location: clientarea.php' );
        exit;
    }
    $fl = Fleio::fromServiceId($prod->id);
    $url = $fl->getSSOUrl();
    header("Location: " . $url);
    exit;

} else {
    header('Location: ' . 'clientarea.php');
    exit;
}
