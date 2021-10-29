<?php

use OpenproviderTransfers\API\API;
use WHMCS\Database\Capsule;

class OpenproviderTransfersAddonHelper
{
    const DATABASE_TRANSFER_SCHEDULED_DOMAINS_NAME = 'mod_openprovider_transfers_scheduled_domain_transfer';

    private $username;
    private $password;
    private $apiClient;

    private $paramsMeaning = [
        'username' => 'option1',
        'password' => 'option2',
    ];

    public function __construct()
    {
        $this->loadCredentialsFromDatabase();
    }

    /**
     * @param array $params
     */
    public function loadCredentialsFromParams($params)
    {
        $this->username = $params[$this->paramsMeaning['username']];
        $this->password = $params[$this->paramsMeaning['password']];
    }

    /**
     * @param string $requestCommand
     * @param array $args
     *
     * @throws Exception
     *
     * @return array
     */
    public function sendRequest($requestCommand, $args)
    {
        if (is_null($this->apiClient)) {
            $this->apiClient = new API();
        }

        $this->apiClient->setParams([
            'username' => $this->username,
            'password' => $this->password,
        ]);

        return $this->apiClient->sendRequest($requestCommand, $args);
    }

    public function loadCredentialsFromDatabase()
    {
        $params = Capsule::table('tbladdonmodules')
            ->where('module', 'openprovider_transfers')
            ->get();

        foreach ($params as $param) {
            if ($param->setting == $this->paramsMeaning['username']) {
                $this->username = $param->value;
            } else if ($param->setting == $this->paramsMeaning['password']) {
                $this->password = $param->value;
            }
        }
    }
}
