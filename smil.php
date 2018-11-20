<?php
class smil
{

    private $error; # last error
    public function __construct()
    {
        $this->error = '';
    }

    public static function writeToLog($message)
    {
        echo "$message\n";
        #fwrite(STDERR, "$message\n");
    }

/*
 * date2unix
 * this function translate time in format 00:00:00.00 to seconds
 *
 * @param    string $t
 * @return    float
 */
    public function date2unix($dateStr)
    {
        $time = strtotime($dateStr);
        if (!$time) {
            $this->error = "Incorrect date format for string '$dateStr'";
        }
        return ($time);

    }

/**
 * time2float
 * this function translate time in format 00:00:00.00 to seconds
 *
 * @param    string $t
 * @return    float
 */

    public static function time2float($t)
    {
        $matches = preg_split("/:/", $t, 3);
        if (array_key_exists(2, $matches)) {
            list($h, $m, $s) = $matches;
            return ($s + 60 * $m + 3600 * $h);
        }
        $h = 0;
        list($m, $s) = $matches;
        return ($s + 60 * $m);
    }

/**
 * float2time
 * this function translate time from seconds to format 00:00:00.00
 *
 * @param    float $i
 * @return    string
 */
    public function float2time($i)
    {
        $h = intval($i / 3600);
        $m = intval(($i - 3600 * $h) / 60);
        $s = $i - 60 * floatval($m) - 3600 * floatval($h);
        return sprintf("%02d:%02d:%05.2f", $h, $m, $s);
    }

    public static function readJson($configFile)
    {
        $json = file_get_contents($configFile);
        if (!$json) {
            self::writeToLog("Cannot read file '$configFile'");
            return (false);
        }
        $out = json_decode($json, true);
        if (!$out) {
            self::writeToLog("Incorrect json string in json file '$configFile'");
            return (false);
        }
        return ($out);
    }
}
