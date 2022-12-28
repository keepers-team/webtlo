<?php
/*
TorrentEditor.com API - Simple API to modify torrents
Copyright (C) 2009  Tyler Alvord

Web: http://torrenteditor.com
IRC: #torrenteditor.com on efnet.org  

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

class BEncode
{
    public static function decode(&$raw, &$offset = 0)
    {
        if ($offset >= strlen($raw)) {
            return new BEncode_Error("Decoder exceeded max length.");
        }

        $char = $raw[$offset];
        switch ($char) {
            case 'i':
                $int = new BEncode_Int();
                $int->decode($raw, $offset);
                return $int;

            case 'd':
                $dict = new BEncode_Dictionary();

                if ($check = $dict->decode($raw, $offset)) {
                    return $check;
                }
                return $dict;

            case 'l':
                $list = new BEncode_List();
                $list->decode($raw, $offset);
                return $list;

            case 'e':
                return new BEncode_End();

            case '0':
            case is_numeric($char):
                $str = new BEncode_String();
                $str->decode($raw, $offset);
                return $str;

            default:
                return new BEncode_Error("Decoder encountered unknown char '$char' at offset $offset.");
        }
    }
}

class BEncode_End
{
    public function get_type()
    {
        return 'end';
    }
}

class BEncode_Error
{
    private $error;

    public function __construct($error)
    {
        $this->error = $error;
    }

    public function get_plain()
    {
        return $this->error;
    }

    public function get_type()
    {
        return 'error';
    }
}

class BEncode_Int
{
    private $value;

    public function __construct($value = null)
    {
        $this->value = $value;
    }

    public function decode(&$raw, &$offset)
    {
        $end = strpos($raw, 'e', $offset);
        ++$offset;
        $this->value = substr($raw, $offset, $end - $offset);
        $offset += ($end - $offset);
    }

    public function get_plain()
    {
        return $this->value;
    }

    public function get_type()
    {
        return 'int';
    }

    public function encode()
    {
        return "i{$this->value}e";
    }

    public function set($value)
    {
        $this->value = $value;
    }
}

class BEncode_Dictionary
{
    public $value = array();

    public function decode(&$raw, &$offset)
    {
        $dictionary = array();

        while (true) {
            ++$offset;
            $name = BEncode::decode($raw, $offset);

            if ($name->get_type() == 'end') {
                break;
            } else if ($name->get_type() == 'error') {
                return $name;
            } else if ($name->get_type() != 'string') {
                return new BEncode_Error("Key name in dictionary was not a string.");
            }

            ++$offset;
            $value = BEncode::decode($raw, $offset);

            if ($value->get_type() == 'error') {
                return $value;
            }

            $dictionary[$name->get_plain()] = $value;
        }

        $this->value = $dictionary;
    }

    public function get_value($key)
    {
        if (isset($this->value[$key])) {
            return $this->value[$key];
        } else {
            return null;
        }
    }

    public function encode()
    {
        $this->sort();

        $encoded = 'd';
        foreach ($this->value as $key => $value) {
            $bstr = new BEncode_String();
            $bstr->set($key);
            $encoded .= $bstr->encode();
            $encoded .= $value->encode();
        }
        $encoded .= 'e';
        return $encoded;
    }

    public function get_type()
    {
        return 'dictionary';
    }

    public function remove($key)
    {
        unset($this->value[$key]);
    }

    public function set($key, $value)
    {
        $this->value[$key] = $value;
    }

    private function sort()
    {
        ksort($this->value);
    }

    public function count()
    {
        return count($this->value);
    }
}

class BEncode_List
{
    private $value = array();

    public function add($bval)
    {
        array_push($this->value, $bval);
    }

    public function decode(&$raw, &$offset)
    {
        $list = array();

        while (true) {
            ++$offset;
            $value = BEncode::decode($raw, $offset);

            if ($value->get_type() == 'end') {
                break;
            } else if ($value->get_type() == 'error') {
                return $value;
            }
            array_push($list, $value);
        }

        $this->value = $list;
    }

    public function encode()
    {
        $encoded = 'l';

        for ($i = 0; $i < count($this->value); ++$i) {
            $encoded .= $this->value[$i]->encode();
        }
        $encoded .= 'e';
        return $encoded;
    }

    public function get_plain()
    {
        return $this->value;
    }

    public function get_type()
    {
        return 'list';
    }
}

class BEncode_String
{
    private $value;

    public function __construct($value = null)
    {
        $this->value = $value;
    }

    public function decode(&$raw, &$offset)
    {
        $end = strpos($raw, ':', $offset);
        $len = substr($raw, $offset, $end - $offset);
        $offset += ($len + ($end - $offset));
        $end++;
        $this->value = substr($raw, $end, $len);
    }

    public function get_plain()
    {
        return $this->value;
    }

    public function get_type()
    {
        return 'string';
    }

    public function encode()
    {
        $len = strlen($this->value);
        return  "$len:{$this->value}";
    }

    public function set($value)
    {
        $this->value = $value;
    }
}
