<?php

if (!function_exists('is_cli')) {
    function is_cli()
    {
        if ( defined('STDIN') )
        {
            return true;
        }

        if ( php_sapi_name() === 'cli' )
        {
            return true;
        }

        if ( array_key_exists('SHELL', $_ENV) ) {
            return true;
        }

        if ( empty($_SERVER['REMOTE_ADDR']) and !isset($_SERVER['HTTP_USER_AGENT']) and count($_SERVER['argv']) > 0)
        {
            return true;
        }

        if ( !array_key_exists('REQUEST_METHOD', $_SERVER) )
        {
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

$scheduledDomains = Capsule::select("
    select motsdt.domain_id,
           motsdt.domain,
           motsdt.op_status,
           motsdt.prev_registrar,
           motsdt.informed_below_two_weeks,
           tbldomains.expirydate
    from mod_openprovider_transfers_scheduled_domain_transfer as motsdt
    left join tbldomains
    on motsdt.domain_id = tbldomains.id
    where motsdt.domain_id and motsdt.op_status <> 'ACT' and motsdt.op_status <> 'FAI'
");
//
//$scheduledDomains = [
//    new stdClass(),
//    new stdClass(),
//    new stdClass(),
//    new stdClass()
//];
//
//$scheduledDomains[0]->domain = 'test-domain-qwe2.com';
//$scheduledDomains[0]->domain_id = 1;
//$scheduledDomains[0]->op_status = 'ACT';
//$scheduledDomains[0]->prev_registrar = '';
//$scheduledDomains[0]->informed_below_two_weeks = 0;
//$scheduledDomains[0]->expirydate = '2021-11-01';
//
//$scheduledDomains[1]->domain = 'schtest-vvhc-1.com';
//$scheduledDomains[1]->domain_id = 2;
//$scheduledDomains[1]->op_status = 'PEN';
//$scheduledDomains[1]->prev_registrar = '';
//$scheduledDomains[1]->informed_below_two_weeks = 0;
//$scheduledDomains[1]->expirydate = '2021-10-17';
//
//$scheduledDomains[2]->domain = 'schtest-fetm-1.com';
//$scheduledDomains[2]->domain_id = 4;
//$scheduledDomains[2]->op_status = 'FAI';
//$scheduledDomains[2]->prev_registrar = 'enom';
//$scheduledDomains[2]->informed_below_two_weeks = 0;
//$scheduledDomains[2]->expirydate = '2021-11-04';
//
//$scheduledDomains[3]->domain = 'schtest-qmkh-7.com';
//$scheduledDomains[3]->domain_id = 3;
//$scheduledDomains[3]->op_status = 'PEN';
//$scheduledDomains[3]->prev_registrar = '';
//$scheduledDomains[3]->informed_below_two_weeks = 1;
//$scheduledDomains[3]->expirydate = '2021-11-04';

foreach ($scheduledDomains as $scheduledDomain) {
    $syncedAt = Carbon::now();
    $domainOp = $addonHelper->sendRequest('retrieveDomainRequest', [
        'domain' => $addonHelper->getDomainArray($scheduledDomain->domain)
    ]);

    // Update status in mod_openprovider_transfers_scheduled_domain_transfer table
    Capsule::update("
        update mod_openprovider_transfers_scheduled_domain_transfer
        set op_status='{$domainOp['status']}',
            synced_at='{$syncedAt->toDateTimeString()}'
        where domain_id={$scheduledDomain->domain_id}
    ");

    switch ($domainOp['status']) {
        case 'ACT':
            Capsule::update("
                update mod_openprovider_transfers_scheduled_domain_transfer
                set finished_transfer_date='{$syncedAt->toDateString()}'
                where domain_id={$scheduledDomain->domain_id}
            ");

            Capsule::update("
                update tbldomains
                set status='Active'
                where id={$scheduledDomain->domain_id}
            ");

            break;
        case 'PEN':
            Capsule::update("
                update tbldomains
                set status='Pending Transfer'
                where id={$scheduledDomain->domain_id}
            ");

            if ($scheduledDomain->informed_below_two_weeks) {
                break;
            }

            // If expiry date is less than two weeks
            // we need to create todoitem to check if domain ok
            if ($syncedAt->subDays(14)->toDateString() < Carbon::createFromFormat('Y-m-d', $scheduledDomain->expirydate)->toDateString()) {
                Capsule::table('tbltodolist')
                    ->insert([
                        'title' => 'Check transfer completed',
                        'description' => "{$scheduledDomain->domain} is still in the pending stage in Openprovider.",
                        'status' => 'Pending',
                        'date' => $syncedAt->toDateString(),
                        'duedate' => $syncedAt->toDateString(),
                    ]);

                Capsule::update("
                    update mod_openprovider_transfers_scheduled_domain_transfer
                    set informed_below_two_weeks=1
                    where domain_id={$scheduledDomain->domain}
                ");
            }
            break;
        case 'FAI':
            Capsule::update("
                update tbldomains
                set status='Active',
                    registrar='{$scheduledDomain->prev_registrar}'
                where id={$scheduledDomain->domain_id}
            ");
            break;
    }
}
