<?php

if (!defined('WHMCS')) {
    die("This file cannot be accessed directly");
}

require_once 'vendor/autoload.php';
require_once 'helper.php';

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
    $page = $_REQUEST['p'] ?? '1';
    $numberPerPage = $_REQUEST['n'] ?? '30';

    if ($action == 'update_statuses') {
        // Get scheduled domains with statuses not equals ACT/FAI and synced_at older than 2 hours.
        // Limit is 30 rows per time.
        $edgedDatetimeToSync = Carbon::now()->subHours(2)->toDateTimeString();
        $scheduledDomains = Capsule::select("
            select motsdt.domain_id,
                   motsdt.domain,
                   motsdt.op_status,
                   motsdt.prev_registrar,
                   motsdt.informed_below_two_weeks,
                   tbldomains.expirydate
            from mod_openprovider_transfers_scheduled_domain_transfer as motsdt
            inner join tbldomains
            on motsdt.domain_id = tbldomains.id
            where 
                  motsdt.domain_id 
              and motsdt.op_status <> 'ACT' 
              and motsdt.op_status <> 'FAI'
              and (motsdt.synced_at is NULL or motsdt.synced_at < '{$edgedDatetimeToSync}')
            limit 30;
        ");

        foreach ($scheduledDomains as $scheduledDomain) {
            $syncedAt = Carbon::now();
            $domainOp = $addonHelper->sendRequest('retrieveDomainRequest', [
                'domain' => $addonHelper->getDomainArray($scheduledDomain->domain)
            ]);

            // Update status in mod_openprovider_transfers_scheduled_domain_transfer table
            Capsule::table('mod_openprovider_transfers_scheduled_domain_transfer')
                ->where('domain_id', $scheduledDomain->domain_id)
                ->update([
                    'op_status' => $domainOp['status'],
                    'synced_at' => $syncedAt->toDateTimeString(),
                ]);

            switch ($domainOp['status']) {
                case 'ACT':
                    // Set finished transfer date today
                    // And set domain status active
                    Capsule::table('mod_openprovider_transfers_scheduled_domain_transfer')
                        ->where('domain_id', $scheduledDomain->domain_id)
                        ->update([
                            'finished_transfer_date' => $syncedAt->toDateString(),
                        ]);

                    Capsule::table('tbldomains')
                        ->where('id', $scheduledDomain->domain_id)
                        ->update([
                            'status' => 'Active',
                        ]);
                    break;
                case 'REQ':
                    Capsule::table('tbldomains')
                        ->where('id', $scheduledDomain->domain_id)
                        ->update([
                            'status' => 'Pending Transfer',
                        ]);
                    if ($scheduledDomain->informed_below_two_weeks) {
                        break;
                    }

                    // If expiry date is less than two weeks
                    // we need to create todoitem to check if domain ok
                    if ($syncedAt->toDateString() > Carbon::createFromFormat('Y-m-d', $scheduledDomain->expirydate)->subDays(14)->toDateString()) {
                        Capsule::table('tbltodolist')
                            ->insert([
                                'title' => 'Check transfer completed',
                                'description' => "{$scheduledDomain->domain} is still in the pending stage in Openprovider.",
                                'status' => 'Pending',
                                'date' => $syncedAt->toDateString(),
                                'duedate' => $syncedAt->toDateString(),
                            ]);

                        Capsule::table('mod_openprovider_transfers_scheduled_domain_transfer')
                            ->where('domain_id', $scheduledDomain->domain_id)
                            ->update([
                                'informed_below_two_weeks' => 1
                            ]);
                    }
                    break;
                case 'FAI':
                    Capsule::table('tbldomains')
                        ->where('id', $scheduledDomain->domain_id)
                        ->update([
                            'status' => 'Active',
                            'registrar' => $scheduledDomain->prev_registrar
                        ]);
                    break;
            }
        }
    } elseif ($action == 'remove_all_fai') {
        Capsule::table('mod_openprovider_transfers_scheduled_domain_transfer')
            ->where('op_status', 'FAI')
            ->delete();
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
