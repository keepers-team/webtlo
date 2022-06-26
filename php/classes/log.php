<?php
include_once dirname(__FILE__) . "/../common/storage.php";

class Log
{

    private static $log;

    public static function append($message = "")
    {
        if (!empty($message)) {
            self::$log[] = date('d.m.Y H:i:s') . ' ' . $message;
        }
    }

    public static function get($break = '<br />')
    {
        if (!empty(self::$log)) {
            return implode($break, self::$log) . $break;
        }
    }

    public static function write($filelog)
    {
		$dataDirname = getStorageDir();
        $dir = $dataDirname . DIRECTORY_SEPARATOR . "logs";
        $result = is_writable($dir) || mkdir($dir);
        if (!$result) {
            echo "Нет или недостаточно прав для доступа к каталогу logs";
        }

        $filelog = "$dir/$filelog";
        self::move($filelog);
        if ($filelog = fopen($filelog, "a")) {
            fwrite($filelog, self::get("\n"));
            fwrite($filelog, " -- DONE --\n");
            fclose($filelog);
        } else {
            echo "Не удалось создать файл лога.";
        }
    }

    private static function move($filelog)
    {
        // переименовываем файл лога, если он больше 5 Мб
        if (file_exists($filelog) && filesize($filelog) >= 5242880) {
            if (!rename($filelog, preg_replace('|.log$|', '.1.log', $filelog))) {
                echo "Не удалось переименовать файл лога.";
            }
        }
    }

    public static function clean()
    {
        self::$log = array();
    }
}
