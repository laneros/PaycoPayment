# ePayco Integration for XenForo 2.x

This is the repository for the colombian payment gateway [ePayco](https://epayco.co/)

The following payment methods are supported

 * Credit cards
 * PSE

## Installation

This is a development version of the addon which means you have to download the vendor libraries before installing it
into your site.

Run the following commands in the addon directory

 * `composer install`
 * `bower install`
 
 Run the following command in your XenForo root directory
 
 * `php cmd.php xf-addon:build-release PaycoPayment`