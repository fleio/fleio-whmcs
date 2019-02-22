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
        return Capsule::table('tbladmins')->where([['roleid', '=', 1], ['disabled', '=', 0]])->limit(1)->value('username');
      } catch ( Exception $e) {
        logActivity('Fleio: unable to get an admin username: '. $e->getMessage());
        return;
      }
    }

    public static function addQueueTask($serviceId, $action, $errmsg='', $serviceType='service') {
      # Adds a task to Utilities -> Module Queue to allow admins to retry the operation
      return ModuleQueue::add($serviceType, $serviceId, 'fleio', $action, $errmsg);    
    }

    public static function daysDiff($stringDate) {
        // Days diff between now and a date as strings: eg: '2019-02-14'
        try {
            $sndDate = date(strtotime($stringDate));
            $datediff = time() - $sndDate;
            return round($datediff / (60 * 60 * 24));
        } catch (Exception $e) {
            logActivity('Fleio: unable to convert dates!');
            return NULL;
        }
    }

	public static function getServiceById($serviceId) {
      # Return a service including the product name amd description and some settings
      try {
        $prod = Capsule::table('tblhosting AS th')
                       ->join('tblproducts AS tp', 'th.packageid', '=', 'tp.id')
                       ->where('th.id', '=', $serviceId)
                       ->select('th.*', 'tp.name', 'tp.description', 'tp.servergroup', 'tp.tax', 'tp.servertype', 'tp.configoption8 AS configuration')
                       ->first();
      } catch (Exception $e) {
        logActivity('Fleio: unable to get the fleio product with ID: ' . $serviceId . '; ' . $e->getMessage());
        return NULL;
      }
      return $prod;
    }

    public static function getClientProduct($clientId, $packageId=NULL, $status='Active') {
	  # Get the Fleio service for a client. A client has only one Fleio product.
      try {
         if (!is_null($packageId)) {
             $prod = Capsule::table('tblhosting AS th')
                             ->join('tblproducts AS tp', 'th.packageid', '=', 'tp.id')
                             ->where('th.userid', '=', $clientId)
                             ->where('th.domainstatus', '=', $status)
                             ->where('tp.servertype', '=', 'fleio')->first();
          } else {
            $prod = Capsule::table('tblhosting AS th')
                            ->join('tblproducts AS tp', 'th.packageid', '=', 'tp.id')
                            ->where('th.userid', '=', $clientId)
                            ->where('th.domainstatus', '=', $status)
                            ->where('tp.id', '=', $packageId)
                            ->where('tp.servertype', '=', 'fleio')->first(); 
          }
      } catch (Exception $e) {
        return NULL;
      }
      return $prod;
    }

    public static function getUUIDClient($clientUUID) {
	    // Get the Fleio product for a WHMCS client specified by Client UUID
        return Capsule::table('tblclients AS tc')
                          ->where('tc.uuid', '=', $clientUUID)
                          ->select('tc.*')
                          ->first();
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
      // Get an admin username
      try {
          $adminUsername = self::getWHMCSAdmin();
      } catch (Exception $e) {
          logActivity('Fleio: ' . $e->getMessage());
          throw new Exception('Unable to create invoice'); // We do not throw the original message since it may contain sensitive data
      }
      $result = localAPI('CreateInvoice', $data, $adminUsername);
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

    public static function createOverdueClientInvoice($clientId, $amount, $fleioServiceId, $invoicePaymentMethod=NULL, $type='Hosting') {
      // Calculate date and due date of invoice
      $dueDays = 0;
      $today = date('Y-m-d');
      $dueDate = new DateTime($today);
      $dueDate->modify('+' . $dueDays . ' day');
      // Get an admin username, required to use local API 
      try {
	    $adminUsername = self::getWHMCSAdmin();
      } catch (Exception $e) {
        logActivity('Fleio: ' . $e->getMessage());
        throw new Exception('Unable to create invoice'); // We do not throw the original message since it may contain sensitive data
      }
      // Get the client Fleio product, to create an invoice for
      if (!$fleioServiceId) {
        throw new Exception('Fleio: unable to issue invoice for Client ID: ' . $clientId . ' since no OpenStack products found.');
      }
      $data = [
        "date" => $today,
        "duedate" => $dueDate->format('Y-m-d'),
        'userid' => $clientId,
        'sendinvoice' => '1',
        'itemdescription1' => 'Cloud services',
        'itemamount1' => $amount,
        'itemtaxed1' => true];
      if (!is_null($invoicePaymentMethod)) {
        $data['paymentmethod'] = $invoicePaymentMethod;
      }
      $result = localAPI('CreateInvoice', $data, $adminUsername);
      if ($result["result"] == "success") {
        $invoice_id = $result['invoiceid'];
        Capsule::table('tblinvoiceitems')
               ->where('invoiceid', (string) $invoice_id)
               ->update(array("type"=>$type, "relid"=>$fleioServiceId));
        return $invoice_id;
      } else {
        throw new Exception($result["message"]);
      }
    }

    public static function getFleioProductsInvoicedAmount($clientId, $fleioPackageId) {
      // Get the amount already invoiced and unpaid for Fleio active products related to this client
      // Return an array of: {"amount": .. "currency": .. "product": .. , "invoiced_since_days": ..}
      $fleioProduct = self::getClientProduct($clientId, $fleioPackageId);
      $clientCurrency = getCurrency($clientId);
      if (!$fleioProduct) {
        throw new Exception('Fleio: unable to issue invoice for Client ID: ' . $clientId . ' since no OpenStack products found.');
      }
      $items = Capsule::table('tblinvoiceitems')
                      ->join('tblclients AS tc', 'tc.id', '=', 'tblinvoiceitems.userid')
	                  ->join('tblinvoices AS tinv', 'tinv.id', '=', 'tblinvoiceitems.invoiceid')
                      ->where([['tblinvoiceitems.userid', '=', $clientId], ['tblinvoiceitems.relid', '=', $fleioProduct->id]])
                      ->select('tblinvoiceitems.amount', 'tblinvoiceitems.userid', 'tc.currency', 'tinv.date', 'tinv.status')->get();
      $daysSinceLastInvoice = NULL;
      $amount = 0;
      foreach($items as $item) {
          if ($item->status == 'Unpaid') {
              // Count only unpaid invoices
	          $amount += $item->amount;
          }
          $invIssuedDays = self::daysDiff($item->date);
          if ($daysSinceLastInvoice == NULL && $invIssuedDays != NULL) {
            $daysSinceLastInvoice = $invIssuedDays;
          } else {
            if ($daysSinceLastInvoice > $invIssuedDays){
                $daysSinceLastInvoice = $invIssuedDays;
            }
          }
      }
      return array("amount" => $amount, "currency" => $clientCurrency, "product" => $fleioProduct, 'days_since_last_invoice' => $daysSinceLastInvoice);
    }

   public static function updateClientsBillingAgreement($flApi, $status='Active') {
       logActivity('Fleio: update all clients billing agreements');
       try {
            $clients = Capsule::table('tblhosting AS th')
                           ->join('tblproducts AS tp', 'th.packageid', '=', 'tp.id')
                           ->join('tblclients as tc', 'tc.id', '=', 'th.userid')
                           ->where('th.domainstatus', '=', $status)
                           ->where('tp.servertype', '=', 'fleio')
                           ->select('tc.gatewayid', 'tc.id', 'tc.uuid')
                           ->get();
           } catch (Exception $e) {
             return NULL;
           }
        $agreements = [];
        foreach($clients AS $client) {
           $hasAgreement = isset($client->gatewayid) && !(is_null($client->gatewayid)) && !(empty($client->gatewayid));
           $clientAgreement = array("uuid" => $client->uuid, "agreement" => $hasAgreement);
           array_push($agreements, $clientAgreement);
        };
        if (sizeof($agreements)) {
          $url = '/clients/set_billing_agreements';
          try {
            $flApi->post($url, $agreements);
          } catch (Exception $e) {
            logActivity('Fleio update billing agreements FAIL: '. $e->getMessage());
          }
        }
   }
   public static function captureInvoicePayment($invoiceId) {
        $data = array('invoiceid' => $invoiceId);
        $result = localAPI('CapturePayment', $data);
        if (is_array($result) && $result['result'] != 'success') {
            $captureMessage = $result['message'];
        } else {
            $captureMessage = 'Captured successfully';
        }
        logActivity('Fleio: capture Invoice ID: '. $invoiceId .' '.$captureMessage);
   }
  public static function getClientPaymentMethod($clientId) {
        try {
            $pgw = Capsule::table('tblclient AS tc')
                           ->join('tblpaymentgateways AS tgw', 'tc.gatewayid', '=', 'tgw.id')
                           ->select('tgw.gateway')
                           ->first();
           return $pgw->gateway;
        } catch (Exception $e) {
           return NULL;
        }
  }
}

