<?php

if (!defined('WHMCS')) {
    die("This file cannot be accessed directly");
}

require_once 'vendor/autoload.php';

use OpenproviderTransfers\ScheduledDomainTransfer;

const OPENPROVIDER_TRANSFERS_EXPORT_CSV_ACTION = 'export_csv';
const OPENPROVIDER_TRANSFERS_REMOVE_ALL_FAI_ACTION = 'remove_all_fai';
const OPENPROVIDER_TRANSFERS_REMOVE_ALL_ACTION = 'remove_all';
const OPENPROVIDER_TRANSFERS_LOAD_SCHEDULED_TRANSFERS_ACTION = 'load_scheduled_transfers';
const OPENPROVIDER_TRANSFERS_REQUESTED_TRANSFERS_ACTION = 'requested_transfers';
const OPENPROVIDER_TRANSFERS_FAILED_TRANSFERS_ACTION = 'failed_transfers';
const OPENPROVIDER_TRANSFERS_COMPLETED_TRANSFERS_ACTION = 'completed_transfers';

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

    if ($action == OPENPROVIDER_TRANSFERS_EXPORT_CSV_ACTION) {

        $filepath = $scheduledDomainTransfer->saveDataToCsv();

        $views['filepath'] = $filepath;

        return $addonHelper->renderTemplate(
            OpenproviderTransfersAddonHelper::DOWNLOAD_CSV_TEMPLATE,
            $views
        );
    }

    $page = $_REQUEST['p'] ?? '1';
    $numberPerPage = $_REQUEST['n'] ?? '30';

    $views['page'] = $page;
    $views['number_per_page'] = $numberPerPage;
    $views['max_pages_list'] = 6;

    if ($action == OPENPROVIDER_TRANSFERS_REMOVE_ALL_FAI_ACTION) {
        $scheduledDomainTransfer->removeAllFAIDomains();
    }

    if ($action == OPENPROVIDER_TRANSFERS_REMOVE_ALL_ACTION) {
        $result = $scheduledDomainTransfer->removeScheduledTransferDomains();

        if (isset($result['error'])) {
            $views['error'] = $result['error'];
        }
    } else if ($action == OPENPROVIDER_TRANSFERS_LOAD_SCHEDULED_TRANSFERS_ACTION) {
        $scheduledDomainTransfer->updateScheduledTransferDomains();
        $scheduledDomainTransfer->linkDomainsToWhmcsDomains();
        $scheduledDomainTransfer->updateRequestedDomains();
        $scheduledDomainTransfer->updateFailedDomains();
        $scheduledDomainTransfer->updateActiveDomains();

        $scheduledTransferDomains = $scheduledDomainTransfer->getScheduledTransferDomains((int) $page, (int) $numberPerPage);

        if (isset($scheduledTransferDomains['error'])) {
            $views['error'] = $scheduledTransferDomains['error'];
        }

        $views['scheduled_transfer_domains'] = $scheduledTransferDomains;
        $views['domains_number'] = $scheduledDomainTransfer->getScheduledTransferDomainsNumber();
    } else if ($action == OPENPROVIDER_TRANSFERS_REQUESTED_TRANSFERS_ACTION) {
        $result = $scheduledDomainTransfer->getRequestedTransferDomains($page, $numberPerPage);
        if (isset($result['error'])) {
            $views['error'] = $result['error'];
        }

        $views['scheduled_transfer_domains'] = $result;
        $views['domains_number'] = $scheduledDomainTransfer->getRequestedTransferDomainsNumber();
    } else if ($action == OPENPROVIDER_TRANSFERS_FAILED_TRANSFERS_ACTION) {
        $result = $scheduledDomainTransfer->getFailedTransferDomains($page, $numberPerPage);

        if (isset($result['error'])) {
            $views['error'] = $result['error'];
        }

        $views['scheduled_transfer_domains'] = $result;
        $views['domains_number'] = $scheduledDomainTransfer->getFailedTransferDomainsNumber();
    } else if ($action == OPENPROVIDER_TRANSFERS_COMPLETED_TRANSFERS_ACTION) {
        $result = $scheduledDomainTransfer->getCompletedTransferDomains($page, $numberPerPage);

        if (isset($result['error'])) {
            $views['error']  = $result['error'];
        }

        $views['scheduled_transfer_domains'] = $result;
        $views['domains_number'] = $scheduledDomainTransfer->getCompletedTransferDomainsNumber();
    } else {
        $domainsNumber = $scheduledDomainTransfer->getScheduledTransferDomainsNumber();
        $scheduledTransferDomains = $scheduledDomainTransfer->getScheduledTransferDomains((int) $page, (int) $numberPerPage);
        if (isset($scheduledTransferDomains['error'])) {
            $views['error'] = $scheduledTransferDomains['error'];
        }

        $views['scheduled_transfer_domains'] = $scheduledTransferDomains;
        $views['domains_number'] = $domainsNumber;
    }

    return $addonHelper->renderTemplate(
        OpenproviderTransfersAddonHelper::SCHEDULED_TRANSFER_DOMAINS_LIST_TEMPLATE,
        $views
    );
}
