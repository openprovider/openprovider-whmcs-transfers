<?php

namespace OpenproviderTransfers\API;

class APITools
{
    /*
     * converts php-structure to DOM-object.
     *
     * @param array $arr php-structure
     * @param SimpleXMLElement $node parent node where new element to attach
     * @param DOMDocument $dom DOMDocument object
     * @return SimpleXMLElement
     */
    public static function convertPhpObjToDom($arr, $node, $dom)
    {
        //Convert to array
        if(is_object($arr))
        {
            $arr    =   json_decode(json_encode($arr), true);
        }
        
        if (is_array($arr))
        {
            /**
             * If arr has integer keys, this php-array must be converted in
             * xml-array representation (<array><item>..</item>..</array>)
             */
            $arrayParam = array();
            foreach ($arr as $k => $v)
            {
                if (is_integer($k))
                {
                    $arrayParam[] = $v;
                }
            }
            if (0 < count($arrayParam))
            {
                $node->appendChild($arrayDom = $dom->createElement("array"));
                foreach ($arrayParam as $key => $val)
                {
                    $new = $arrayDom->appendChild($dom->createElement('item'));
                    self::convertPhpObjToDom($val, $new, $dom);
                }
            }
            else
            {
                foreach ($arr as $key => $val)
                {
                    $new = $node->appendChild($dom->createElement(mb_convert_encoding($key, Config::$encoding)));
                    self::convertPhpObjToDom($val, $new, $dom);
                }
            }
        }
        else
        {
            $node->appendChild($dom->createTextNode(mb_convert_encoding($arr, Config::$encoding)));
        }
    }

    public static function convertXmlToPhpArray($xml)
    {
        $simplexml = simplexml_load_string($xml);

        $array = self::convertObjToArray($simplexml);

        return $array;
    }

    public static function convertObjToArray($obj)
    {
        if(!is_object($obj))
            return false;

        $returnArray = [];

        foreach($obj as $key => $value)
        {
            $key = mb_convert_encoding($key, Config::$encoding);

            if($key == 'array')
                return self::convertObjToArray($value);

            // Check if we have children.
            if(count($value) != 0)
            {
                $array = self::convertObjToArray($value);
                $value = $array;
            }
            else
            {
                $value = mb_convert_encoding((string) $value, Config::$encoding);
            }

            if($key == 'item')
            {
                $returnArray[] = $value;
            }
            else
            {
                $returnArray[$key] = $value;
            }

        }

        return $returnArray;
    }
}
