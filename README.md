Fleio WHMCS module
==================

This is the Fleio WHMCS module to allow integration between WHMCS and Fleio.

Fleio is an OpenStack billing system and self-service portal software that
enables service providers to sell public cloud services.



Installation
============

**Requires WHMCS 7.x**

1. Copy the source files to the WHMCS installation directory in `modules/servers/fleio`
2. Move `WHMCS_INSTALL_DIR/modules/servers/fleio/fleioaddcredit.php` to `WHMCS_INSTALL_DIR/fleioaddcredit.php`
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

How the module works
====================

The WHMCS module supports the following: 
* WHMCS product actions: Create. Suspend, Unsuspend and Terminate
* auto creation of Fleio account
* auto login to Fleio from WHMCS Service page for users
* auto credit adjustment for paid invoices
* ability to create new invoices for Fleio credit
* auto update of Fleio client details if WHMCS client is updated
* auto update of Fleio client when he adds a billing agreement or CC in WHMCS
* auto issue of invoices for Fleio clients passing a certain amount in usage before the end of a month
* auto issue of invoices for Fleio clients at the end of a month
* auto charge attempt after an invoice is issued for clients with a billing agreement or CC on file

How does the module issue new invoices
======================================

We will assume a credit limit exists in Fleio:

* for clients without a billing agreement or CC on file of: -10 USD
* for clients with a billing agreement or CC on file of: -20 USD

If *Invoice clients without billing agreement* is checked in the Fleio Module Settings in WHMCS, all clients that do not have a billing agreement or CC on file in WHMCS will be issued invoices as follows:

* when they reach a usage of over 10 USD at any time during a month
* when a month passes, for the amount used if it's lower than the credit limit

The same as the above applies for clients with a billing agreement of CC on file when "Invoice clients with billing agreement" is checked with the usage being over 20 USD.
If the option *Attempt a charge immediately* is also checked, then a charge is attempted automatically for these clients, for the invoices issued.

When a client passes his credit limit the second time during a month, a second invoice will be issued and so on.

On all cases, two invoices will never be issued on the same day for the same product.
There is a delay of 1 day for when a second invoice can be automatically issued.
The payment method set for invoices is the one set on the WHMCS service, not the Client. 
Do note however that if you set a new payment method on the WHMCS Client profile tab, that payment method will be used for all unpaid invoices and 
auto charging may fail if the new gateway does not support this.
If auto generated invoices are deleted or marked canceled, new invoices will be issued.

Clients usage is checked every time the WHMCS cron runs. The default recommended period is 5 minutes.


Additional notes
================

Any WHMCS automated billing will not work as expected and is not recommended.
Instead you should use the Fleio billing system to calculate usage for clients and to require them to pay invoices or add credit.
To achieve this, you can set the product as free or set a one time payment requirement in WHMCS.

It's really important to remember that when/if a WHMCS customer's currency is changed, the same operation needs to be done in Fleio and
all Fleio client's services should be checked to have prices in the new currency set for the WHMCS client.

License information
===================

fleio-whmcs is licensed under BSD License. See the "LICENSE" file for more information.
