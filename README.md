HarvestBlame
============

Command-line PHP that uses the Harvest API's to check if everyone has filled in their timesheets.

HarvestBlame uses HarvestAPI written by Matthew John Denton <matt@mdbitz.com>, which is distributed here for convenience. 

Rename config.sample.inc to config.inc and update with your own settings.

Run 
    php HarvestBlame.php

Requires:
 * PHP 5.2 or greater
 * PHP cURL extension
 * The ability to send email from the server
