<?php

if (!defined('WHMCS')) {
    die("This file cannot be accessed directly");
}

require_once 'vendor/autoload.php';

use OpenproviderTransfers\ScheduledDomainTransfer;
use WHMCS\Database\Capsule;
use Carbon\Carbon;

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

    if ($action == 'export_csv') {

        $filepath = $scheduledDomainTransfer->saveDataToCsv();

        $views['filepath'] = $filepath;

        require __DIR__ . '/templates/download_csv.php';
        return;
    }

    $page = $_REQUEST['p'] ?? '1';
    $numberPerPage = $_REQUEST['n'] ?? '30';

    if ($action == 'update_statuses') {
        $scheduledDomainTransfer->updateStatuses();
    } elseif ($action == 'remove_all_fai') {
        $scheduledDomainTransfer->removeAllFAIDomains();
    }

    if ($action == 'remove_all') {
        $result = $scheduledDomainTransfer->removeScheduledTransferDomains();

        if (isset($result['error'])) {
            $view['error'] = $result['error'];
        }
    } else if ($action == 'load_scheduled_transfers') {
        $scheduledDomainTransfer->updateScheduledTransferTable();

        $scheduledDomainTransfer->linkDomainsToWhmcsDomains();

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
    } else if ($action == 'requested_transfers') {

        $result = $scheduledDomainTransfer->getRequestedTransfersDomains();

        if (isset($result['error'])) {
            $view['error'] = $result['error'];
        }

        try {
            $offset = ((int)$page - 1) * ((int) $numberPerPage);
            // Select all domains that have expiry date bigger than today
            $scheduledTransferDomains = Capsule::select("
                select * from mod_openprovider_transfers_scheduled_domain_transfer
                where (op_status = 'SCH' or op_status = 'REQ') and domain_id
                in (
                    select id from tbldomains where expirydate > CURRENT_DATE() 
                    order by expirydate
                )
                order by domain
                limit {$numberPerPage} offset {$offset}
            ");
            $domainsNumber = count($scheduledTransferDomains);
            $view['scheduled_transfer_domains'] = array_map(function ($item) {
                return (array) $item;
            }, $scheduledTransferDomains);
            $view['page'] = $page;
            $view['number_per_page'] = $numberPerPage;
            $view['domains_number'] = $domainsNumber;
            $view['max_pages_list'] = 6;

        } catch (\Exception $e) {
            $view['error'] = $e->getMessage();
        }
    } else if ($action == 'failed_transfers') {
        try {
            $offset = ((int)$page - 1) * ((int) $numberPerPage);
            $untilDate = Carbon::now()->addDays(14)->format('Y-m-d');
            // Select all domains that have expiry date bigger than today
            $scheduledTransferDomains = Capsule::select("
                select * from mod_openprovider_transfers_scheduled_domain_transfer
                where op_status = 'FAI' or op_status = 'REQ'
                and domain_id
                in (
                    select id from tbldomains where expirydate < '{$untilDate}' and expirydate > CURRENT_DATE()
                    order by expirydate
                )
                order by domain
                limit {$numberPerPage} offset {$offset}
            ");

            $domainsNumber = count($scheduledTransferDomains);
            $view['scheduled_transfer_domains'] = array_map(function ($item) {
                return (array) $item;
            }, $scheduledTransferDomains);
            $view['page'] = $page;
            $view['number_per_page'] = $numberPerPage;
            $view['domains_number'] = $domainsNumber;
            $view['max_pages_list'] = 6;
        } catch (\Exception $e) {
            $view['error'] = $e->getMessage();
        }
    } else if ($action == 'completed_transfers') {
        try {
            $offset = ((int)$page - 1) * ((int) $numberPerPage);
            // Select all domains that have expiry date bigger than today
            $scheduledTransferDomains = Capsule::select("
                select * from mod_openprovider_transfers_scheduled_domain_transfer
                where op_status = 'ACT'
                and domain_id
                order by finished_transfer_date, domain
                limit {$numberPerPage} offset {$offset}
            ");

            $domainsNumber = count($scheduledTransferDomains);
            $view['scheduled_transfer_domains'] = array_map(function ($item) {
                return (array) $item;
            }, $scheduledTransferDomains);
            $view['page'] = $page;
            $view['number_per_page'] = $numberPerPage;
            $view['domains_number'] = $domainsNumber;
            $view['max_pages_list'] = 6;
        } catch (\Exception $e) {
            $view['error'] = $e->getMessage();
        }
    } else {
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
    }

    require __DIR__ . '/templates/scheduled_transfer_domains_list.php';
}
