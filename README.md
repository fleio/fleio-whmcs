## Fleio WHMCS module


This is the Fleio WHMCS module to allow integration between WHMCS and Fleio.

Fleio is an OpenStack billing system and self-service portal software that
enables service providers to sell public cloud services.


## Requirements
* WHMCS 7.x
* Requires PHP 7.x


## Installation


1. Copy the source files to the WHMCS installation directory in `modules/servers/fleio`
2. Move `WHMCS_INSTALL_DIR/modules/servers/fleio/fleioaddcredit.php` to `WHMCS_INSTALL_DIR/fleioaddcredit.php`
2. Move `WHMCS_INSTALL_DIR/modules/servers/fleio/accesscloudcontrolpanel.php` to `WHMCS_INSTALL_DIR/accesscloudcontrolpanel.php`
3. Login to WHMCS as admin and create a new product from: `Setup -> Product/Services -> Product/Services -> Create New Product`
4. Under Module Settings on the new product page, select the `Fleio` module
5. Retrieve a token from Fleio (after logging in as admin in backend (/backend/admin) -> Tokens -> Add Token for a staff user) 
    and add it to the WHMCS module in module settings
6. Set the frontend public urls for user and admin
7. Set the backend public url (eg: http://server_hostname/staffapi)
8. Set the Maximum and Minimum amounts (these are used to limit the amount a user can pay for a service)
9. Make sure that WHMCS and Fleio have the same currencies
10. In case a Configuration name and Group name is set in WHMCS Module Settings, make sure Fleio has the same Configuration and Client Group names.
    
    The Client Group name can be set through the /backend/admin/ at `Fleio core app` -> `Client groups`

## How the module works


The WHMCS module supports the following: 
* WHMCS product actions: Create. Suspend, Unsuspend and Terminate
* auto creation of Fleio account
* auto login to Fleio from WHMCS Service page for users
* auto credit adjustment for paid invoices
* ability to create new invoices for Fleio credit
* auto update of Fleio client details if WHMCS client is updated
* auto update of Fleio client when he adds a billing agreement or CC in WHMCS
   * you can filter the billing agreement ID prefix that leads to client marked as having a billing agreement in Fleio. For instance you can consider clients with a Stripe billing agreement ( prefix "cus_"), but not does with a PayPal billing agrement (prefix "B-").
* auto issue of invoices for Fleio clients passing a certain amount in usage before the end of a month
* auto issue of invoices for Fleio clients at the end of a month
* auto charge attempt after an invoice is issued for clients with a billing agreement or CC on file

## How does the module issue new invoices

We will assume a credit limit exists in Fleio:

* for clients without a billing agreement or CC on file of: -10 USD
* for clients with a billing agreement or CC on file of: -20 USD

We have the following limit in WHMCS:
*Do not invoice amount below*: 1 USD

Also, we will have the following terms:
* uninvoiced ussage: the difference between total usage and invoiced usage

If *Invoice clients without billing agreement* is checked in the Fleio Module Settings in WHMCS, all clients that do not have a billing agreement or CC on file in WHMCS will be issued invoices as follows:

* any time during a month, if uninvoiced usage exceeds the credit limit defined in Fleio configuration (10 USD in our example)
* at the end a billing cycle (one month), for the amount consumed if it's not lower than the *Do not invoice amount below* limit

Also, if *Invoice only in end of cycle timespan* is checked, invoice won't be issued at the end of the billing cycle (related service cycle having a status of "unpaid") if WHMCS cron runs and processes the client 72 hours after the cycle end date.

The same as the above applies for clients with a billing agreement of CC on file when *Invoice clients with billing agreement* is checked with the usage being over 20 USD.
If the option *Attempt a charge immediately* is also checked, then a charge is attempted automatically for these clients, for the invoices issued.

When a client passes his credit limit the second time during a month, a second invoice will be issued and so on.

On all cases, two invoices will never be issued on the same day for the same product.
There is a delay of 1 day for when a second invoice can be automatically issued.
The payment method set for invoices is the one set on the WHMCS service, not the Client. 
Do note however that if you set a new payment method on the WHMCS Client profile tab, that payment method will be used for all unpaid invoices and 
auto charging may fail if the new gateway does not support this.
If auto generated invoices are deleted or marked canceled, new invoices will be issued.

Clients usage is checked every time the WHMCS cron runs. The default recommended period is 5 minutes.

## Scenarios


#### Scenario 1 

* The *Do not invoice amount below* limit is 1 USD.
* The *Credit limit when on agreement* limit is set to 50 USD.
* The *Delay suspend* limit is set to 30 days

At the end of billing cycle, the client has a total cost of services of 10 USD. An invoice will be issued, with the value of 10 USD. Invoice status is unpaid.

The next day (1st day after the end of the billing cycle) the total cost of services rises up to 20 USD. Invoice will not be issued, since the *Credit limit when on agreement* limit was not reached. 

On the 4th day, the total cost of services rises up to 50 USD. Invoice will still not be issued, since the *Credit limit when on agreement* limit was not reached:
Currently, the client has an invoice of 10 USD, the total costs of services is 50 USD. Uninvoiced usage is equal to 40 USD. The limit is not reached, no invoice is issued. The suspension timer starts since it reached the 50 USD owned mark.

On the 5th day, the total cost gets to 59.9 USD. Invoice will not be issued.

On the 6th day, the cost gets to 60 USD. Invoice will be issued, with the total cost of 50 USD. Uninvoiced credit resets to 0 USD.

On the 25th day of the billing cycle, the costs gets to 90 USD. No invoice will be issued. The uninvoiced credit is 30 USD, which is lower than the *Credit limit when on agreement* limit.

On the 29th day, the costs gets to 110 USD. Uninvoiced credit is 50 USD, so a new invoice will be issued, with the value of 50 USD. 


Upon this date, all the invoices are still unpaid. If the invoices are still unpaid on 34th day the client gets suspended. Why?

1. The suspension timer started on 4th day
2. The *delay suspend* limit is 30 days. 

#### Scenario 2

* The *Do not invoice amount below* limit is 1 USD.
* The *Credit limit when on agreement* limit is set to 50 USD.
* The *Delay suspend* limit is set to 30 days

At the end of the billing cycle, the client has 0 uninvoiced usage, and all the invoices are paid.

On the 1st day of the billing cycle, the client will consume a total of 0.23 USD. No invoice will be issued since it does not reach the *Credit limit when on agreement* limit is set to 50 USD.

On the 3rd day, he has a cost total cost os 0.96 USD. No invoice will be issued. He deletes all the resources and does not create any other cost for the rest of the billing cycle.

At the end of the billing cycle, the client still won't be invoiced, since the uninvoiced usage does not pass the *Do not invoice amount below* limit.
If you wish to invoice the 0.96 USD at the end of the billing cycle, just set `The Do not invoice amount below: 0 USD`.

#### Scenario 3

* The *Do not invoice amount below* limit is 1 USD 
* The *Credit limit when on agreement* limit is set to 50 USD
* The *Delay suspend* limit is set to 30 days

At the end of the billing cycle, the client has 0 uninvoiced usage, and all the invoices are paid.

On the 1st day of the billing cycle, the client will consume a total of 0.23 USD. No invoice will be issued since it does not reach the *Credit limit when on agreement* limit is set to 50 USD.

On the 3rd day, he has a cost total cost of 1 USD. No invoice will be issued since it does not reach the 2nd limit. He deletes all the resources and no further costs is generated.

At the end of the billing cycle it will be issued an invoice of 1 USD. 


Additional notes
================

The module won't work with more than one Fleio product defined (connected to 2 Fleio installations for example).

Any WHMCS automated billing will not work as expected and is not recommended.
Instead you should use the Fleio billing system to calculate usage for clients and to require them to pay invoices or add credit.
To achieve this, you can set the product as free or set a one time payment requirement in WHMCS.

It's really important to remember that when/if a WHMCS customer's currency is changed, the same operation needs to be done in Fleio and
all Fleio client's services should be checked to have prices in the new currency set for the WHMCS client.

If you're using invoice generation features of fleio-whmcs plugin, automatic settlements and invoice generation settings
`have to be disabled in Fleio`. With this setup, in order to settle invoiced service cycles in Fleio (thus also adjusting
client total credit in Fleio), you may make use of `Mark invoiced periods as paid when using external billing` setting
from Configuration details / Billing cycles expandable row. This setting works as follows: all invoiced service cycles are
settled if client has up to date credit greater than 0 after paying a fleio-whmcs invoice, otherwise settling invoiced cycles
depends on their associated price and how much credit the client added.

Partial refunds must be manually handled. The Fleio credit will not be modified if you partially refund an invoice in WHMCS


License information
===================

fleio-whmcs is licensed under BSD License. See the *LICENSE* file for more information.
