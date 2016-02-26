Installation
============

The required WHMCS version is 7.x (it probably works with 6.x as well, but wasn't tested much).

1. Copy the source files to the WHMCS_INSTALL_DIR/modules/servers/fleio
2. Move WHMCS_INSTALL_DIR/modules/servers/fleio/fleioaddcredit.php to WHMCS_INSTALL_DIR/fleioaddcredit.php
3. Login to WHMCS as admin and create a new product from: Setup -> Product/Services -> Product/Services -> Create New Product
4. Under Module Settings on the new product page, select the Fleio module
5. Retrieve a token from Fleio (after logging in as admin in backend -> Tokens -> Add Token) and add it to the WHMCS module in module settings
6. Set the frontend public urls for user and admin
7. Set the backend public url (eg: http://server_hostname/staffapi)
8. Set the Maximum and Minimum ammounts (these are used to limit the amount a user can pay for this service)

