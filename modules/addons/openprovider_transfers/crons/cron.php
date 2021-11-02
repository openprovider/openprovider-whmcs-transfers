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
            // Set Pending transfer status
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
            // Return previous registrar and set status Active
            // Add todoitem to todolist with failed information
            Capsule::table('tbldomains')
                ->where('id', $scheduledDomain->domain_id)
                ->update([
                    'status' => 'Active',
                    'registrar' => $scheduledDomain->prev_registrar
                ]);

            Capsule::table()
                ->insert([
                    'title' => 'Domain transfer to Openprovider failed',
                    'description' => "{$scheduledDomain->domain} has status FAI in Openprovider",
                    'status' => 'Pending',
                    'date' => $syncedAt->toDateString(),
                    'duedate' => $syncedAt->toDateString(),
                ]);
            break;
    }
}
