<?php

use OpenproviderTransfers\ScheduledDomainTransfer;

if (!defined('WHMCS')) {
    die("This file cannot be accessed directly");
}

require_once 'vendor/autoload.php';
require_once 'helper.php';

function openprovider_transfers_config()
{
    return [
        'name' => 'Openprovider Transfers', // Display name for your module
        'description' => 'Automated assistance consolidating your domains in Openprovider.', // Description displayed within the admin interface
        'author' => 'Openprovider', // Module author name
        'language' => 'english', // Default language
        'version' => '0.0.1', // Version number
        'fields' => [
            'option1' => [
                'FriendlyName' => 'Username',
                'Type' => 'text'
            ],
            'option2' => [
                'FriendlyName' => 'Password',
                'Type' => 'password'
            ],
        ]
    ];
}

function openprovider_transfers_activate()
{
    $scheduledDomainTransfer = new ScheduledDomainTransfer();

    $scheduledDomainTransfer->createTables();
}

function openprovider_transfers_deactivate()
{
    $scheduledDomainTransfer = new ScheduledDomainTransfer();

    $scheduledDomainTransfer->dropTables();
}

function openprovider_transfers_output($params)
{
    openprovider_transfers_output_scheduled_transfer_domains($params);
}

function openprovider_transfers_output_scheduled_transfer_domains($params)
{
    $addonHelper = new OpenproviderTransfersAddonHelper();
    $addonHelper->loadCredentialsFromParams($params);

    $scheduledDomainTransfer = new ScheduledDomainTransfer();
    $scheduledDomainTransfer->setAddonHelper($addonHelper);

    $action = $_REQUEST['action'] ?? '';
    $page = $_REQUEST['p'] ?? '1';
    $numberPerPage = $_REQUEST['n'] ?? '30';

    if ($action == 'remove_all') {
        $result = $scheduledDomainTransfer->removeScheduledTransferDomains();

        if (isset($result['error'])) {
            $view['error'] = $result['error'];
        }
    }

    $domainsNumber = $scheduledDomainTransfer->getScheduledTransferDomainsNumber();
    $scheduledTransferDomains = $scheduledDomainTransfer->getScheduledTransferDomains((int) $page, (int) $numberPerPage);
    if (isset($scheduledTransferDomains['error'])) {
        $view['error'] = $scheduledTransferDomains['error'];
    }

    $view['scheduled_transfer_domains'] = $scheduledTransferDomains;
    $view['page'] = $page;
    $view['number_per_page'] = $numberPerPage;
    $view['domains_number'] = $domainsNumber;
    $view['max_pages_list'] = 6;


    require __DIR__ . '/templates/scheduled_transfer_domains_list.php';
}
