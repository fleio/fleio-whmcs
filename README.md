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
8. Set the Maximum and Minimum ammounts (these are used to limit the amount a user can pay for a service)
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

Additional notes
================

Any WHMCS automated billing will not work as expected and is not recommended.
Instead you should use the Fleio billing system to calculate usage for clients and to require them to pay invoices or add credit.
To achive this, you can set the product as free or set a one time payment requirement in WHMCS.
