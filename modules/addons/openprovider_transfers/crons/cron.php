<?php

if (!function_exists('is_cli')) {
    function is_cli()
    {
        if (defined('STDIN')) {
            return true;
        }

        if (php_sapi_name() === 'cli') {
            return true;
        }

        if (array_key_exists('SHELL', $_ENV)) {
            return true;
        }

        if (empty($_SERVER['REMOTE_ADDR']) and !isset($_SERVER['HTTP_USER_AGENT']) and count($_SERVER['argv']) > 0) {
            return true;
        }

        if (!array_key_exists('REQUEST_METHOD', $_SERVER)) {
            return true;
        }

        return false;
    }
}

if (!is_cli()) {
    exit('ACCESS DENIED');
}

// Init WHMCS
require __DIR__ . '/../../../../init.php';
require '../vendor/autoload.php';

use Carbon\Carbon;
use OpenproviderTransfers\ScheduledDomainTransfer;
use WHMCS\Database\Capsule;

$addonHelper = new OpenproviderTransfersAddonHelper();
$addonHelper->loadCredentialsFromDatabase();

$scheduledDomainTransfer = new ScheduledDomainTransfer();
$scheduledDomainTransfer->setAddonHelper($addonHelper);

$scheduledDomainTransfer->updateScheduledTransferDomains();

$scheduledDomainTransfer->linkDomainsToWhmcsDomains();

$scheduledDomainTransfer->updateActiveDomains();

$scheduledDomainTransfer->updateRequestedDomains();

$scheduledDomainTransfer->updateFailedDomains();
