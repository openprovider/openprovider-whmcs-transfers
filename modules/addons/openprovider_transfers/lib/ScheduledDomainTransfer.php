<?php

namespace OpenproviderTransfers;

use Carbon\Carbon;
use WHMCS\Database\Capsule;
use Illuminate\Database\Schema\Blueprint;

class ScheduledDomainTransfer
{
    const DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME = 'mod_openprovider_transfers_scheduled_domain_transfer';

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
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ];

                Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                    ->updateOrInsert([
                        'domain' => sprintf(
                            '%s.%s',
                            $scheduledDomain['domain']['name'], $scheduledDomain['domain']['extension']),
                    ], [
                        'scheduled_at' => $scheduledDomain['scheduledAt'],
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
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
        // Update status to Pending Transfer
        // Abort the renew because our operation was a success
        try {
            Capsule::update(
                "update `tbldomains` set registrar='openprovider', 
                        status='Pending Transfer'
                    where id={$domainId}"
            );

            Capsule::table(self::DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME)
                ->where('domain_id', $domainId)
                ->where('domain', $domainName)
                ->update([
                    'scheduled_at' => $scheduledTransferDate
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

        if (!empty($scheduledTransferDomains)) {
            return array_map(function ($item) {
                return (array) $item;
            }, $scheduledTransferDomains);
        }

        return $scheduledTransferDomains;
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
                        // Maybe it's not necessary
                        // $table->string('run_id', 100)->nullable();
                        $table->timestamps();
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
