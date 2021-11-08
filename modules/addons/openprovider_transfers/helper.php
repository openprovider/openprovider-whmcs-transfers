<?php

use OpenproviderTransfers\API\API;
use WHMCS\Database\Capsule;

class OpenproviderTransfersAddonHelper
{
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

    /**
     * Transform domain name from string to array:
     *
     * [
     *      'name' => domain name,
     *      'extension' => domain extension
     * ]
     *
     * For example:
     *
     * any-domain.something.com => [ 'name' => 'any-domain', 'extension' => 'something.com' ]
     *
     * @param string $domainName
     *
     * @return array
     */
    public function getDomainArray($domainName)
    {
        $explodeDomain = explode('.', $domainName);

        return [
            'name' => $explodeDomain[0],
            'extension' => str_replace($explodeDomain[0] . '.', '', $domainName)
        ];
    }

    /**
     * Transform Collection object to array.
     * If argument is array, do nothing
     *
     * @param array|Illuminate\Support\Collection $collectionOrObject
     *
     * @return array[]
     */
    public function fromCollectionOrObjectToArray($collectionOrObject)
    {
        // If $collectionOrObject is Illuminate\Support\Collection
        if (!is_array($collectionOrObject)) {
            $collectionOrObject = $collectionOrObject->toArray();
        }

        return array_map(function ($item) {
            return (array) $item;
        }, $collectionOrObject);
    }
}
