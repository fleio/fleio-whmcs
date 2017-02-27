<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use Illuminate\Database\Capsule\Manager as Capsule;
use WHMCS\Module\Queue as ModuleQueue;

class FleioUtils {
    public function __construct(){}

    public static function getWHMCSAdmin() {
      # Get the first WHMCS admin username (usually for localApi requests).
      try {
        return Capsule::table('tbladmins')->where('roleid', '=', 1)->limit(1)->value('username');
      } catch ( Exception $e) {
        logActivity('Fleio: unable to get an admin username: '. $e->getMessage());
        return;
      }
    }

    public static function addQueueTask($serviceId, $action, $errmsg='', $serviceType='service') {
      # Adds a task to Utilities -> Module Queue to allow admins to retry the operation
      return ModuleQueue::add($serviceType, $serviceId, 'fleio', $action, $errmsg);    
    }

	public static function getServiceById($serviceId) {
      # Return a service including the product name amd description and some settings
      try {
        $prod = Capsule::table('tblhosting AS th')
                       ->join('tblproducts AS tp', 'th.packageid', '=', 'tp.id')
                       ->where('th.id', '=', $serviceId)
                       ->select('th.*', 'tp.name', 'tp.description', 'tp.servergroup', 'tp.tax', 'tp.configoption8 AS billingtype')
                       ->first();
      } catch (Exception $e) {
        logActivity('Fleio: unable to get the fleio product with ID: ' . $serviceId . '; ' . $e->getMessage());
        return NULL;
      }
      return $prod;
    }

    public static function getClientProduct($clientId, $status='Active') {
	  # Get the Fleio product for a client. A client has only one Fleio product.
	  try {
		  $prod = Capsule::table('tblhosting AS th')
					  ->join('tblproducts AS tp', 'th.packageid', '=', 'tp.id')
					  ->where('th.userid', '=', $clientId)
					  ->where('th.domainstatus', '=', $status)
					  ->where('tp.servertype', '=', 'fleio')
                      ->select('th.*', 'tp.name', 'tp.description', 'tp.servergroup', 'tp.tax', 'tp.configoption8 AS billingtype')
					  ->first();
	  } catch (Exception $e) {
		  logActivity('Fleio: unable to get the fleio product id for '. $clientId . '. ' . $e->getMessage());
		  return NULL;
	  }
	  return $prod;
	}

    public static function getFleioProducts() {
      # Retrieve all the products of type Fleio
      try {
        return Capsule::table('tblproducts')->where('servertype', '=', 'fleio')->get();
      } catch (Exception $e) {
        logActivity('Fleio: unable to retrieve products: ' . $e->getMessage());
        return array();
      }
    }

    public static function getBillingHistory($flApi, $end_date=NULL) {
		$url = '/openstack/billing/history';
		$params = array();
		if (!is_null($end_date)) {
		   $params['end_date'] = $end_date;
		}
		$response = $flApi->get($url, $params);
		#$client1 = ['client' => ['external_billing_id' => ''], 'price' => 234];
		#$client2 = ['client' => ['external_billing_id' => 'c9987cc2-82fd-4d3d-b3f4-006f88f8b7c4'], 'price' => 334];
		#$response = ['objects' => [$client1, $client2]];
        logModuleCall('fleio', 'getBillingHistory', $params, $response, $response['objects'], array());
		if ($response == null) {
			throw new FlApiRequestException("Unable to retrieve the billing history", 409);
		}
		return $response['objects'];
    }

    public static function createFleioInvoice($productId, $data, $type='Hosting') {
      # Helper to create an invoice for a Fleio product
      # Automatically calculate the date and duedate based on config
      global $CONFIG;
      $data['date'] = $data['date'] ? $data['date'] : date('Y-m-d');
      if (!$data['duedate']) {
          #TODO(tomo): $duedays = $CONFIG['CreateInvoiceDaysBeforeMonthly'] ? $CONFIG['CreateInvoiceDaysBeforeMonthly'] : $CONFIG['CreateInvoiceDaysBefore'];
          $duedays = $CONFIG['CreateInvoiceDaysBefore'];
          $dueDateTime = new DateTime($data['date']);
          $dueDateTime->modify('+' . $duedays . ' day');
          $data['duedate'] = $dueDateTime->format('Y-m-d');
      }

	  $result = localAPI('CreateInvoice', $data);
      if ($result["result"] == "success") {
	    $invoice_id = $result['invoiceid'];
	    Capsule::table('tblinvoiceitems')
	          ->where('invoiceid', (string) $invoice_id)
			  ->update(array("type"=>$type, "relid"=>$productId));
        return $invoice_id;
	  } else {
        throw new Exception($result["message"]);         
      }
    }
}

