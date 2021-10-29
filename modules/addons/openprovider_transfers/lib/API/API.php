<?php

namespace OpenproviderTransfers\API;

class API
{
    protected $api;
    protected $request;
    protected $url;
    protected $error = null;
    protected $timeout = null;
    protected $debug = null;
    protected $username = null;
    protected $password = null;
    protected $cache; // Cache responses made in this request.

    /**
     * API constructor.
     */
    public function __construct()
    {
        $this->timeout = Config::$curlTimeout;
        $this->request = new Request();
        $this->url = 'https://api.cte.openprovider.eu';
    }

    /**
     * @param array $params ['username' => username, 'password' => password,]
     * @param int $debug
     */
    public function setParams($params, $debug = 0)
    {
        $this->request->setAuth(array(
            'username' => $params["username"],
            'password' => $params["password"],
        ));

        $this->username = $params['username'];
        $this->password = $params['password'];

        $this->debug = $debug;
    }

    public function sendRequest($requestCommand, $args = null)
    {
        // prepare request
        $this->request->setCommand($requestCommand);

        $this->request->setArgs(null);

        // prepare args
        if (isset($args) && !is_null($args)) {
            $args = json_decode(json_encode($args), true);

            // idn
            if (isset($args['domain']['name']) && isset($args['domain']['extension'])) {
                // UTF-8 encoding
                if (!preg_match('//u', $args['domain']['name'])) {
                    $args['domain']['name'] = utf8_encode($args['domain']['name']);
                }

                $args['domain']['name'] = idn_to_ascii($args['domain']['name']);
            } elseif (isset ($args['namePattern'])) {
                $namePatternArr = explode('.', $args['namePattern'], 2);
                $tmpDomainName = $namePatternArr[0];

                // UTF-8 encoding
                if (!preg_match('//u', $tmpDomainName)) {
                    $tmpDomainName = utf8_encode($tmpDomainName);
                }

                $tmpDomainName = idn_to_ascii($tmpDomainName);
                $args['namePattern'] = $tmpDomainName . '.' . $namePatternArr[1];
            } elseif (isset ($args['name']) && !is_array($args['name'])) {
                // UTF-8 encoding
                if (!preg_match('//u', $args['name'])) {
                    $args['name'] = utf8_encode($args['name']);
                }

                $args['name'] = idn_to_ascii($args['name']);
            }

            $this->request->setArgs($args);
        }

        // send request
        $result = $this->process($this->request);

        $resultValue = $result->getValue();

        $faultCode = $result->getFaultCode();

        if ($faultCode != 0) {
            $msg = $result->getFaultString();
            if ($value = $result->getValue()) {
                if (is_array($value)) {
                    if (isset($value['description']))
                        $msg .= ':<br> ' . $value['description'] . ' ' . (isset($value['options']) ? '(' . implode(',', $value['options']) . ')' : '');
                    else
                        $msg .= implode(', ', $value);
                } else {
                    $msg .= ':<br> ' . $value;
                }
            }

            throw new \Exception($msg, $faultCode);
        }

        return $resultValue;
    }

    protected function process(Request $r)
    {
        if ($this->debug) {
            echo $r->getRaw() . "\n";
        }

        $postValues = $r->getRaw();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postValues);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $ret = curl_exec($ch);

        $errno = curl_errno($ch);
        $this->error = curl_error($ch);

        // log message
        logModuleCall(
            'Openprovider Transfer Addon',
            $r->getCommand(),
            array(
                'postValues' => $postValues,
            ),
            array(
                'curlResponse' => $ret,
                'curlErrNo' => $errno,
                'errorMessage' => $this->error,
            ),
            null,
            array(
                $this->password,
                htmlentities($this->password)
            )
        );

        if (!$ret) {
            throw new \Exception('Bad reply');
        }

        curl_close($ch);

        if ($errno) {
            return false;
        }

        if ($this->debug) {
            echo $ret . "\n";
        }

        return new Reply($ret);
    }
}
