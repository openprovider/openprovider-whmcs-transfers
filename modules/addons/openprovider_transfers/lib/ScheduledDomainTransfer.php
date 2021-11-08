<?php

namespace OpenproviderTransfers;

use Carbon\Carbon;
use WHMCS\Database\Capsule;
use Illuminate\Database\Schema\Blueprint;

class ScheduledDomainTransfer
{
    const DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME = 'mod_openprovider_transfers_scheduled_domain_transfer';

    const LIMIT_NUMBER_OF_DOMAINS_TO_UPDATE = 30;

    /**
     * @var OpenproviderTransfersAddonHelper
     */
    private $addonHelper;

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

    public function updateScheduledTransferTable()
    {
        $scheduledTransferDomains = $this->getOpenproviderScheduledTransfers();

        $result = [];
        try {
            foreach ($scheduledTransferDomains as $scheduledDomain) {
                $domainDataToInsert = [
                    'domain' => sprintf(
                        '%s.%s',
                        $scheduledDomain['domain']['name'], $scheduledDomain['domain']['extension']),
                ];
                $domainDataToUpdate = [
                    'scheduled_at' => $scheduledDomain['scheduledAt'],
                ];

                Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                    ->updateOrInsert([
                        'domain' => sprintf(
                            '%s.%s',
                            $scheduledDomain['domain']['name'], $scheduledDomain['domain']['extension']),
                    ], [
                        'scheduled_at' => $scheduledDomain['scheduledAt'],
                    ]);

                $result[] = array_merge($domainDataToInsert, $domainDataToUpdate);
            }
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
        }
    }

    public function linkDomainsToWhmcsDomains()
    {
        try {
            $unlinkedDomains = Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->where('domain_id', null)
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
                ->where('op_status', 'SCH')
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

            Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->where('domain_id', $domainId)
                ->where('domain', $domainName)
                ->update([
                    'scheduled_at' => $scheduledTransferDate,
                    'prev_registrar' => $params['registrar']
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
                        $table->date('finished_transfer_date');
                        $table->string('op_status', '30')->default('SCH');
                        $table->string('prev_registrar')->nullable();
                        $table->tinyInteger('informed_below_two_weeks', false, true)->default(0);
                        $table->dateTime('synced_at')->nullable();
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

    /**
     * Get scheduled domains with statuses not equals ACT/FAI and synced_at older than 2 hours.
     * Limit is 30 rows per time.
     *
     *
     */
    public function updateStatuses()
    {
        // Get scheduled domains with statuses not equals ACT/FAI and synced_at older than 2 hours.
        // Limit is 30 rows per time.
        $limitNumberOfDomains = self::LIMIT_NUMBER_OF_DOMAINS_TO_UPDATE;
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
            limit {$limitNumberOfDomains};
        ");

        foreach ($scheduledDomains as $scheduledDomain) {
            $syncedAt = Carbon::now();
            $domainOp = $this->addonHelper->sendRequest('retrieveDomainRequest', [
                'domain' => $this->addonHelper->getDomainArray($scheduledDomain->domain)
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
    }

    public function removeAllFAIDomains()
    {
        Capsule::table('mod_openprovider_transfers_scheduled_domain_transfer')
            ->where('op_status', 'FAI')
            ->delete();
    }

    public function getRequestedTransfersDomains()
    {
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
    }

    private function getOpenproviderScheduledTransfers($offset = 0, $limit = 1000)
    {
        $filters ['offset'] = $offset;
        $filters ['limit'] = $limit;
        $filters ['status'] = 'SCH';

        try {
            $results = $this->addonHelper->sendRequest('searchDomainRequest', $filters)['results'];
        } catch (\Exception $e) {
            return [];
        }

        if (!is_null($results) && count($results) == $limit) {
            return $results + $this->getOpenproviderScheduledTransfers($offset + $limit, $limit);
        }

        return $results;
    }
}
