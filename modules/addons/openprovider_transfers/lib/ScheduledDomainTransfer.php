<?php

namespace OpenproviderTransfers;

use Carbon\Carbon;
use WHMCS\Database\Capsule;
use Illuminate\Database\Schema\Blueprint;

class ScheduledDomainTransfer
{
    const OPENPROVIDER_STATUS_SCH = 'SCH';
    const OPENPROVIDER_STATUS_ACT = 'ACT';
    const OPENPROVIDER_STATUS_REQ = 'REQ';
    const OPENPROVIDER_STATUS_FAI = 'FAI';

    const DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME = 'mod_openprovider_transfers_scheduled_domain_transfer';

    const LIMIT_NUMBER_OF_DOMAINS_TO_UPDATE = 30;

    /**
     * @var OpenproviderTransfersAddonHelper
     */
    private $addonHelper;

    /**
     * @param $addonHelper
     */
    public function setAddonHelper($addonHelper)
    {
        $this->addonHelper = $addonHelper;
    }

    /**
     * @return OpenproviderTransfersAddonHelper
     */
    public function getAddonHelper()
    {
        return $this->addonHelper;
    }

    public function updateScheduledTransferDomains()
    {
        try {
            $this->getOpenproviderDomains('_updateScheduledTransferDomains',
                self::OPENPROVIDER_STATUS_SCH);
        } catch (\Exception $e) {}
    }

    public function updateActiveDomains()
    {
        try {
            $this->getOpenproviderDomains(
                '_updateActiveDomains',
                self::OPENPROVIDER_STATUS_ACT);
        } catch (\Exception $e) {}
    }

    public function updateRequestedDomains()
    {
        try {
            $this->getOpenproviderDomains('_updateRequestedDomains',
                self::OPENPROVIDER_STATUS_REQ);
        } catch (\Exception $e) {}
    }

    public function updateFailedDomains()
    {
        try {
            $this->getOpenproviderDomains('_updateFailedDomains',
                self::OPENPROVIDER_STATUS_FAI);
        } catch (\Exception $e) {}
    }

    public function linkDomainsToWhmcsDomains()
    {
        try {
            $unlinkedDomains = Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->where('domain_id', null)
                ->where('op_status', self::OPENPROVIDER_STATUS_SCH)
                ->get();

        } catch (\Exception $e) {
            return;
        }

        if (!count($unlinkedDomains)) {
            return;
        }

        foreach ($unlinkedDomains as $unlinkedDomain) {
            try {
                $whmcsDomain = Capsule::table('tbldomains')->where('domain', $unlinkedDomain->domain)->first();
                if (!is_null($whmcsDomain)) {
                    Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                        ->where('id', $unlinkedDomain->id)
                        ->update([
                            'domain_id' => $whmcsDomain->id
                        ]);
                }
            } catch (\Exception $e) {
                continue;
            }
        }
    }

    /**
     * @param $params
     *
     * @return bool[]|string[]|void
     */
    public function transferDomainToOpenprovider($params)
    {
        $domainId = $params['domainid'];
        $domainName = $params['domainObj']->getSecondLevel() . '.' . $params['domainObj']->getTopLevel();
        // Get domain from mod_openprovider_transfers_scheduled_domain_transfer table
        // If domain didn't link with current domain we need to skip this script
        try {
            $scheduledTransferDomain = Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->where('op_status', self::OPENPROVIDER_STATUS_SCH)
                ->where('domain_id', $domainId)
                ->where('domain', $domainName)
                ->first();

            if (!$scheduledTransferDomain) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        // Try changing transfer date to today in openprovider
        // Continue the renew if error
        $scheduledTransferDate = Carbon::today()->format('Y-m-d H:i:s');
        try {
            $result = $this->addonHelper->sendRequest('modifyDomainRequest', [
                'domain' => [
                    'name' => $params['domainObj']->getSecondLevel(),
                    'extension' => $params['domainObj']->getTopLevel(),
                ],
                'scheduledAt' => $scheduledTransferDate,
            ]);
        } catch (\Exception $e) {
            return;
        }

        // Update registrar to openprovider
        //
        // Save transfer scheduled date and prev registrar
        //
        // Create todoitem in whmcs' todolist
        //
        // Abort the renew with success, because our operation was a success
        try {
            Capsule::table('tbldomains')
                ->where('id', $domainId)
                ->update([
                    'registrar' => 'openprovider'
                ]);

            $whmcsDomain = Capsule::table('tbldomains')
                ->where('id', $domainId)
                ->first();

            Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->where('domain_id', $domainId)
                ->where('domain', $domainName)
                ->update([
                    'scheduled_at' => $scheduledTransferDate,
                    'prev_registrar' => $params['registrar'],
                    'expiration_date' => $whmcsDomain->expirydate
                ]);

            $today = Carbon::today()->format('Y-m-d');
            Capsule::table('tbltodolist')
                ->insert([
                    'date' => $today,
                    'title' => 'Transfer between registrars',
                    'description' => "{$domainName} has been triggered for transfer into Openprovider, and the registrar in whmcs has been changed to Openprovider.",
                    'status' => 'Pending',
                    'duedate' => $today,
                ]);
        } catch (\Exception $e) {
            return [
                'abortWithError' => 'Openprovider Transfers: ' . $e->getMessage(),
            ];
        }

        return [
            'abortWithSuccess' => true,
        ];
    }

    public function removeScheduledTransferDomains()
    {
        try {
            Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->truncate();
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }

        return [];
    }

    public function getScheduledTransferDomains($page = 1, $numberPerPage = 30)
    {
        $scheduledTransferDomains = [];
        try {
            $page = (int) $page;
            $numberPerPage = (int) $numberPerPage;
            $limit = ($page - 1) * $numberPerPage;
            $scheduledTransferDomains = Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->whereNotNull('domain_id')
                ->orderBy('scheduled_at', 'ASC')
                ->orderBy('domain', 'ASC')
                ->skip($limit)
                ->take($numberPerPage)
                ->get();
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }

        return $this->addonHelper->fromCollectionOrObjectToArray($scheduledTransferDomains);
    }

    public function getScheduledTransferDomainsNumber()
    {
        try {
            return Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->whereNotNull('domain_id')
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getRequestedTransferDomains($page = 1, $numberPerPage = 30)
    {
        try {
            $page = (int) $page;
            $numberPerPage = (int) $numberPerPage;
            $offset = ($page - 1) * ($numberPerPage);
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
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }

        return $this->addonHelper->fromCollectionOrObjectToArray($scheduledTransferDomains);
    }

    public function getRequestedTransferDomainsNumber()
    {
        try {
            return Capsule::select("
                select count(domain_id) as quantity from mod_openprovider_transfers_scheduled_domain_transfer
                where (op_status = 'SCH' or op_status = 'REQ') and domain_id
                in (
                    select id from tbldomains where expirydate > CURRENT_DATE()) 
                ")[0]->quantity;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getFailedTransferDomains($page = 1, $numberPerPage = 30)
    {
        try {
            $page = (int) $page;
            $numberPerPage = (int) $numberPerPage;
            $offset = ($page - 1) * ($numberPerPage);
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
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }

        return $this->addonHelper->fromCollectionOrObjectToArray($scheduledTransferDomains);
    }

    public function getFailedTransferDomainsNumber()
    {
        try {
            $untilDate = Carbon::now()->addDays(14)->format('Y-m-d');

            return Capsule::select("
                    select count(domain_id) as quantity from mod_openprovider_transfers_scheduled_domain_transfer
                    where op_status = 'FAI' or op_status = 'REQ'
                    and domain_id
                    in (
                        select id from tbldomains where expirydate < '{$untilDate}' and expirydate > CURRENT_DATE()
                    )
                ")[0]->quantity;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function getCompletedTransferDomains($page = 1, $numberPerPage = 30)
    {
        try {
            $page = (int) $page;
            $numberPerPage = (int) $numberPerPage;
            $offset = ($page - 1) * ($numberPerPage);
            // Select all domains that have expiry date bigger than today
            $scheduledTransferDomains = Capsule::select("
                select * from mod_openprovider_transfers_scheduled_domain_transfer
                where op_status = 'ACT'
                and domain_id
                order by finished_transfer_date, domain
                limit {$numberPerPage} offset {$offset}
            ");
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }

        return $this->addonHelper->fromCollectionOrObjectToArray($scheduledTransferDomains);
    }

    public function getCompletedTransferDomainsNumber()
    {
        try {
            return Capsule::select("
                    select count(domain_id) as quantity from mod_openprovider_transfers_scheduled_domain_transfer
                    where op_status = 'ACT'
                    and domain_id
                ")[0]->quantity;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * @return string[]
     */
    public function createTables()
    {
        // Create DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME table
        try {
            Capsule::schema()
                ->create(
                    self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME,
                    function (Blueprint $table) {
                        $table->increments('id');
                        $table->integer('domain_id')->nullable();
                        $table->string('domain', '100');
                        $table->date('scheduled_at')->nullable();
                        $table->date('finished_transfer_date')->nullable();
                        $table->string('op_status', '30')->default('SCH');
                        $table->date('expiration_date')->nullable();
                        $table->string('prev_registrar')->nullable();
                        $table->tinyInteger('informed_below_two_weeks', false, true)->default(0);
                        // Maybe it's not necessary
                        // $table->string('run_id', 100)->nullable();
                    });

                Capsule::schema()->table(
                    self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME,
                    function (Blueprint $table) {
                        $table->foreign('domain_id')->references('id')->on('tbldomains')
                            ->onDelete('cascade');
                });
        } catch (\Exception $e) {
            return [
                // Supported values here include: success, error or info
                'status' => 'error',
                'description' => 'Unable to create ' .
                    self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME .
                    ': ' . $e->getMessage(),
            ];
        }

        return [
            // Supported values here include: success, error or info
            'status' => 'success',
            'description' => '',
        ];
    }

    /**
     * @return string[]
     */
    public function dropTables()
    {
        try {
            Capsule::schema()->dropIfExists(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME);
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'description' => 'Unable to drop ' .
                    self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME .
                    ': ' . $e->getMessage(),
            ];
        }

        return [
            'status' => 'success',
            'description' => '',
        ];
    }

    public function saveDataToCsv()
    {
        $header = [
            'Domain',
            'Current registrar',
            'Due date',
            'Scheduled transfer date',
            'Transfer status',
        ];
        $transferStatuses = [
            'SCH' => 'scheduled',
            'ACT' => 'successfully transferred',
            'FAI' => 'failed',
            'REQ' => 'transfer requested',
        ];
        $systemUrl = explode('/', localAPI('GetConfigurationValue', [
            'setting' => 'systemURL'
        ])['value']);
        unset($systemUrl[count($systemUrl) - 1]);

        $lastElement = end($systemUrl);
        $whmcsAddress = substr(
            $_SERVER['HTTP_REFERER'],
            0,
            strpos($_SERVER['HTTP_REFERER'], $lastElement) + strlen($lastElement)
        );

        $csvFileAddress = $whmcsAddress . '/modules/addons/openprovider_transfers/tmp/';
        $relativeFolderToSave = '/../tmp/';

        $delimiter = ',';
        $filename = "data_" . date('Y-m-d') . ".csv";

        $fileAbsolutePath = $csvFileAddress . $filename;

        if (!is_dir(__DIR__ . $relativeFolderToSave)) {
            mkdir(__DIR__ . $relativeFolderToSave);
        }

        $f = fopen(__DIR__ . $relativeFolderToSave . $filename, 'w');

        fputcsv($f, $header, $delimiter);

        $dataToSaveAsCsv = Capsule::select("
            select motsdt.domain, motsdt.scheduled_at, motsdt.op_status,
                   tbldomains.nextduedate, tbldomains.registrar
            from mod_openprovider_transfers_scheduled_domain_transfer as motsdt
            inner join tbldomains
            on motsdt.domain_id = tbldomains.id
        ");

        foreach ($dataToSaveAsCsv as $item) {
            $fields = [
                $item->domain,
                $item->registrar,
                $item->nextduedate,
                $item->scheduled_at,
                $transferStatuses[$item->op_status]
            ];
            fputcsv($f, $fields, $delimiter);
        }

        // Move back to beginning of file
        fseek($f, 0);
        fclose($f);

        return $fileAbsolutePath;
    }

    public function removeAllFAIDomains()
    {
        Capsule::table('mod_openprovider_transfers_scheduled_domain_transfer')
            ->where('op_status', 'FAI')
            ->delete();
    }

    private function _updateFailedDomains($failedDomains)
    {
        $syncedAt = Carbon::now()->toDateString();

        foreach ($failedDomains as $domain) {
            $domainName = sprintf(
                '%s.%s',
                $domain['domain']['name'], $domain['domain']['extension']
            );

            $scheduledTransferDomain = Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->where('domain', $domainName)
                ->first();

            if (is_null($scheduledTransferDomain)) {
                continue;
            }

            if ($scheduledTransferDomain->op_status == self::OPENPROVIDER_STATUS_FAI) {
                continue;
            }

            Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->where('id', $scheduledTransferDomain->id)
                ->update([
                    'op_status' => $domain['status']
                ]);

            Capsule::table('tbldomains')
                ->where('id', $scheduledTransferDomain->domain_id)
                ->update([
                    'status' => 'Active',
                    'registrar' => $scheduledTransferDomain->prev_registrar,
                    'expirydate' => $scheduledTransferDomain->expiration_date
                ]);

            Capsule::table('tbltodolist')
                ->insert([
                    'title' => 'Domain transfer to Openprovider failed',
                    'description' => "{$scheduledTransferDomain->domain} has status FAI in Openprovider",
                    'status' => 'Pending',
                    'date' => $syncedAt,
                    'duedate' => $syncedAt,
                ]);
        }
    }

    private function _updateRequestedDomains($requestedDomains)
    {
        $syncedAt = Carbon::now()->toDateString();

        foreach ($requestedDomains as $domain) {
            $domainName = sprintf(
                '%s.%s',
                $domain['domain']['name'], $domain['domain']['extension']);

            $scheduledTransferDomainUpdate = Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->where('domain', $domainName)
                ->update([
                    'op_status' => $domain['status'],
                ]);

            if ($scheduledTransferDomainUpdate) {
                $scheduledTransferDomain = Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                    ->where('domain', $domainName)
                    ->first();

                Capsule::table('tbldomains')
                    ->where('domain', $domainName)
                    ->update([
                        'status' => 'Pending Transfer',
                        'expirydate' => $scheduledTransferDomain->expiration_date,
                    ]);

                if ($scheduledTransferDomain->informed_below_two_weeks) {
                    continue;
                }

                if ($syncedAt >
                    Carbon::createFromFormat(
                        'Y-m-d',
                        $scheduledTransferDomain->expiration_date
                    )->subDays(14)->toDateString()
                ) {
                    Capsule::table('tbltodolist')
                        ->insert([
                            'title' => 'Check transfer completed',
                            'description' => "{$scheduledTransferDomain->domain} is still in the pending stage in Openprovider.",
                            'status' => 'Pending',
                            'date' => $syncedAt,
                            'duedate' => $syncedAt,
                        ]);

                    Capsule::table('mod_openprovider_transfers_scheduled_domain_transfer')
                        ->where('domain_id', $scheduledTransferDomain->domain_id)
                        ->update([
                            'informed_below_two_weeks' => 1
                        ]);
                }
            }
        }
    }

    private function _updateActiveDomains($activeDomains)
    {
        $syncedAt = Carbon::now()->toDateString();
        foreach ($activeDomains as $domain) {
            $domainName = sprintf(
                '%s.%s',
                $domain['domain']['name'], $domain['domain']['extension']);
            $scheduledTransferDomainUpdate = Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->where('domain', $domainName)
                ->update([
                    'op_status' => $domain['status'],
                ]);

            if ($scheduledTransferDomainUpdate) {
                Capsule::table('tbldomains')
                    ->where('domain', $domainName)
                    ->update([
                        'status' => 'Active',
                    ]);

                Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                    ->where('domain', $domainName)
                    ->update([
                        'finished_transfer_date' => $syncedAt,
                    ]);
            }
        }
    }

    private function _updateScheduledTransferDomains($scheduledTransferDomains)
    {
        foreach ($scheduledTransferDomains as $scheduledTransferDomain) {
            $domainDataToInsert = [
                'domain' => sprintf(
                    '%s.%s',
                    $scheduledTransferDomain['domain']['name'], $scheduledTransferDomain['domain']['extension']),
            ];
            $domainDataToUpdate = [
                'scheduled_at' => $scheduledTransferDomain['scheduledAt'],
            ];

            Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->updateOrInsert($domainDataToInsert, $domainDataToUpdate);
        }
    }

    private function getOpenproviderDomains(
        $customFunctionToInteractWithDomains,
        $status = self::OPENPROVIDER_STATUS_SCH,
        $offset = 0,
        $limit = 1000
    )
    {
        $filters ['offset'] = $offset;
        $filters ['limit'] = $limit;
        $filters ['status'] = $status;

        try {
            $results = $this->addonHelper->sendRequest('searchDomainRequest', $filters)['results'];
            call_user_func([$this, $customFunctionToInteractWithDomains], $results);
        } catch (\Exception $e) {
            return;
        }

        if (!is_null($results) && count($results) == $limit) {
            $this->getOpenproviderDomains(
                $customFunctionToInteractWithDomains,
                $status,
                $offset + $limit,
                $limit);
        }
    }
}
