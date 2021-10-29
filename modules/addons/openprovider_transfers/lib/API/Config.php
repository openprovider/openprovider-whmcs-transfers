<?php

namespace OpenproviderTransfers\API;

class Config
{
    static public $moduleVersion = 'whmcs-transfer-v1';
    static public $encoding = 'UTF-8';
    static public $curlTimeout = 1000;

    /**
     * Check what is generating the API call.
     *
     * @return string
     */
    public static function getInitiator()
    {
        if (strpos($_SERVER['SCRIPT_NAME'], 'api.php'))
            return 'api';
        elseif (isset($_SESSION['adminid']))
            return 'admin';
        elseif (isset($_SESSION['uid']))
            return 'customer';
        else
            return 'system';
    }

    /**
     * Get the module version.
     * @return string|string[]
     */
    public static function getModuleVersion()
    {
        $moduleVersion = str_replace('whmcs-', 'v', self::$moduleVersion);
        return $moduleVersion;
    }
}
