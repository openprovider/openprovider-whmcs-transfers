<?php

if (!defined('WHMCS')) {
    die("This file cannot be accessed directly");
}

require_once 'vendor/autoload.php';
require_once 'helper.php';

use OpenproviderTransfers\ScheduledDomainTransfer;

add_hook('DailyCronJob', 1, function () {
    $addonHelper = new OpenproviderTransfersAddonHelper();
    $addonHelper->loadCredentialsFromDatabase();

    $scheduledDomainTransfer = new ScheduledDomainTransfer();
    $scheduledDomainTransfer->setAddonHelper($addonHelper);

    $scheduledDomainTransfer->updateScheduledTransferTable();

    $scheduledDomainTransfer->linkDomainsToWhmcsDomains();

    $scheduledDomainTransfer->checkDomainsRequestedForTransfer();
});

add_hook('PreRegistrarRenewDomain', 1, function ($vars) {
    $params = $vars['params'];
    if ($params['registrar'] != 'openprovider') {
        $addonHelper = new OpenproviderTransfersAddonHelper();
        $addonHelper->loadCredentialsFromDatabase();

        $scheduledDomainTransfer = new ScheduledDomainTransfer();
        $scheduledDomainTransfer->setAddonHelper($addonHelper);

        return $scheduledDomainTransfer->transferDomainToOpenprovider($params);
    }
});
