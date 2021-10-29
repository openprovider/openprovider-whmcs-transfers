<?php

namespace OpenproviderTransfers\API;

class Request
{
    protected $cmd;
    protected $args;
    protected $username;
    protected $password;
    protected $client;

    public function __construct()
    {
        $this->client = Config::$moduleVersion;
    }

    public function getRaw()
    {
        $dom = new \DOMDocument('1.0', Config::$encoding);

        $credentialsElement = $dom->createElement('credentials');
        $usernameElement = $dom->createElement('username');
        $usernameElement->appendChild(
                $dom->createTextNode(mb_convert_encoding($this->username, Config::$encoding))
        );
        $credentialsElement->appendChild($usernameElement);

        $passwordElement = $dom->createElement('password');
        $passwordElement->appendChild(
                $dom->createTextNode(mb_convert_encoding($this->password, Config::$encoding))
        );
        $credentialsElement->appendChild($passwordElement);

        $clientElement = $dom->createElement('client');
        $clientElement->appendChild(
                $dom->createTextNode(mb_convert_encoding($this->client, Config::$encoding))
        );
        $credentialsElement->appendChild($clientElement);

        $initiator = Config::getInitiator();
        $clientElement = $dom->createElement('initiator');
        $clientElement->appendChild(
            $dom->createTextNode(mb_convert_encoding($initiator, Config::$encoding))
        );
        $credentialsElement->appendChild($clientElement);

        $rootElement = $dom->createElement('openXML');
        $rootElement->appendChild($credentialsElement);

        $rootNode = $dom->appendChild($rootElement);
        $cmdNode = $rootNode->appendChild(
                $dom->createElement($this->getCommand())
        );

        APITools::convertPhpObjToDom($this->args, $cmdNode, $dom);

        return $dom->saveXML();
    }
    
    public function setArgs($args)
    {
        $this->args = $args;
        return $this;
    }

    public function setCommand($cmd)
    {
        $this->cmd = $cmd;
        return $this;
    }

    public function getCommand()
    {
        return $this->cmd;
    }

    public function setAuth($args)
    {
        $this->username = isset($args["username"]) ? $args["username"] : null;
        $this->password = isset($args["password"]) ? $args["password"] : null;

        return $this;
    }
}
