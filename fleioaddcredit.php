<?php
# Move this file to the main installation directory 

use WHMCS\ClientArea;
use WHMCS\Database\Capsule;
 
 
define('CLIENTAREA', true);
//define('FORCESSL', true); // Uncomment to force the page to use https://
 
require getcwd() . '/init.php';

$ca = new ClientArea();
$ca->setPageTitle('Fleio add credit');
$ca->initPage();
 
$ca->requireLogin();
 
// Check login status
if ($ca->isLoggedIn()) {
    try {
        $prodId = Capsule::table('tblhosting')
                    ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                    ->where('tblhosting.userid', '=', $ca->getUserID())
                    ->where('tblhosting.domainstatus', '=', 'Active')
                    ->where('tblproducts.servertype', '=', 'fleio')
                    ->select('tblhosting.id')
                    ->first();
    } catch (Exception $e) {
        header('Location: clientarea.php' );
        return;
    }
    header('Location: ' . 'clientarea.php?action=productdetails&id=' . $prodId->id);
 
} else {
    header('Location: ' . 'clientarea.php'); 
}
