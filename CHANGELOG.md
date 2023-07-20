v2.0.5
======

https://github.com/fleio/fleio-whmcs/tree/2.0.5

Release date: 2023-07-20

### Added

\-

### Changed

\-

### Fixed

* [fix] #101 Automatically generated invoices do not change client credit in Fleio once paid


### Unreleased

\-

### Deprecated

\-

### Removed

\-

### Security

\-

### Notes

* [internal] #100 Specify in readme that `tblclients` - `uuid` column should be indexed


v2.0.4
======

https://github.com/fleio/fleio-whmcs/tree/2.0.4

Release date: 2023-07-14

### Added

\-

### Changed

* [change] #93 Add more detailed logging for auto-invoicing feature
* [change] #98 Sync terminated services from Fleio

### Fixed

* [fix] #92 If last client to auto-invoice throws error, last batch of clients to be processed is skipped
* [fix] #96 getClientProduct does not work without providing Fleio product ID


### Unreleased

\-

### Deprecated

\-

### Removed

\-

### Security

\-

### Notes


v2.0.3
======

https://github.com/fleio/fleio-whmcs/tree/2.0.3

Release date: 2023-06-14

### Added

\-

### Changed

\-

### Fixed

* [fix] #94 Fleio end-user is left inactive after unsuspend from WHMCS 

### Unreleased

\-

### Deprecated

\-

### Removed

\-

### Security

\-

### Notes


v2.0.2
======

https://github.com/fleio/fleio-whmcs/tree/2.0.2

Release date: 2023-02-20

### Added

\-

### Changed

* [change] #89 Use database transaction when generating invoices

### Fixed

\-

### Unreleased

\-

### Deprecated

\-

### Removed

\-

### Security

\-

### Notes

* Requires Fleio versions 2022.11 and higher


v2.0.1
======

https://github.com/fleio/fleio-whmcs/tree/2.0.1

Release date: 2023-01-12

### Added

\-

### Changed

\-

### Fixed

* [fix] #80 Multiple unnecessary GET calls for client when creating OS service

### Unreleased

\-

### Deprecated

\-

### Removed

\-

### Security

\-

### Notes

* Requires Fleio versions 2022.11 and higher


v2.0.0
======

https://github.com/fleio/fleio-whmcs/tree/2.0.0

Release date: 2022-11-01

### Added

\-

### Changed

* [change] #79 Mention "Mark invoiced periods as paid" setting in Readme
* [change] #78 Client OpenStack services API endpoint

### Fixed

* [fix] #81 API request filter by removed field GET /staffapi/users?username=whmcs76

### Unreleased

\-

### Deprecated

\-

### Removed

\-

### Security

\-

### Notes

* Requires Fleio versions 2022.11 and higher

v1.0.1
======

https://github.com/fleio/fleio-whmcs/tree/1.0.1

Release date: 2022-11-01

### Fixed

* [fix] #81 API request filter by removed field GET /staffapi/users?username=whmcs76

### Notes

* Works with Fleio versions up to and including 2022.10

v1.0.0
======

https://github.com/fleio/fleio-whmcs/tree/1.0.0

Release date: 2022-09-06

### Notes

* Works with Fleio versions up to and including 2022.10

* Latest commit that includes version 1.0.0 is: [change] #77 Mention "Fleio username prefix" is not used anymore from Fleio 2022.09.0
