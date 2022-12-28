<?php

namespace KeepersTeam\Webtlo;

class TIniFileEx
{
    protected $rcfg;
    protected $wcfg;

    public string $filename;

    public function __construct(string $configDirname)
    {
        $this->filename = $configDirname . DIRECTORY_SEPARATOR . "config.ini";
        $this->loadFromFile();
    }

    private function loadFromFile()
    {
        $this->rcfg = is_readable($this->filename) ? parse_ini_file($this->filename, true) : [];
    }

    public function read($section, $key, $def = "")
    {
        if (!isset($this->rcfg)) {
            self::loadFromFile();
        }
        return $this->rcfg[$section][$key] ?? $def;
    }

    public function write($section, $key, $value)
    {
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }
        $this->wcfg[$section][$key] = $value;
    }

    public function updateFile()
    {
        $_BR_ = chr(13) . chr(10);

        if (empty($this->wcfg)) {
            return;
        }
        if (!isset($this->rcfg)) {
            self::loadFromFile();
        }
        $this->rcfg = array_replace_recursive($this->rcfg, $this->wcfg);
        $result = "";
        foreach ($this->rcfg as $sname => $section) {
            $result .= '[' . $sname . ']' . $_BR_;
            foreach ($section as $key => $value) {
                $result .= $key . '="' . str_replace('\\', '\\\\', $value) . '"' . $_BR_;
            }
            $result .= $_BR_;
        }
        // FIXME
//        Log::append(file_put_contents($this->filename, $result)
//            ? 'Настройки успешно сохранены в файл.'
//            : 'Не удалось записать настройки в файл.');
    }
}
