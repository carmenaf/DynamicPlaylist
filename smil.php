<?php
class smil
{

    private $error; // last error
    private $xml; 
    private $fileName;
    private $timeCounter; // last video started at this time
    private $timeDelta; // start processing for record no early this time
    //private $time; //

    public function __construct($fileName)
    {
        $this->error = '';
        $this->xml = null;
        $this->fileName = $fileName;
        $this->timeCounter = 0;
        $this->timeDelta = 30; // 30 sec
    }

    public function writeToLog($message)
    {
        echo "$message\n";
        #fwrite(STDERR, "$message\n");
    }

    public function getTimeCounter()
    {
        return ($this->$timeCounter);
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

/**
 * getStreamInfo
 * function get info about video or audio stream in the file
 *
 * @param    string $fileName
 * @param    string $streamType    must be  'audio' or 'video'
 * @param    array &$data          return data
 * @return    integer 1 for success, 0 for any error
 */
    public static function getStreamInfo($fileName, $streamType, &$data)
    {
        # parameter - 'audio' or 'video'
        $ffprobe = self::$ffprobe;

        if (!$probeJson = json_decode(`"$ffprobe" $fileName -v quiet -hide_banner -show_streams -of json`, true)) {
            self::writeToLog("Cannot get info about file $fileName");
            return 0;
        }
        if (empty($probeJson["streams"])) {
            self::writeToLog("Cannot get info about streams in file $fileName");
            return 0;
        }
        foreach ($probeJson["streams"] as $stream) {
            if ($stream["codec_type"] == $streamType) {
                $data = $stream;
                break;
            }
        }

        if (empty($data)) {
            self::writeToLog("File $fileName :  stream not found");
            return 0;
        }
        if ('video' == $streamType) {
            if (empty($data["height"]) || !intval($data["height"]) || empty($data["width"]) || !intval($data["width"])) {
                self::writeToLog("File $fileName : invalid or corrupt dimensions");
                return 0;
            }
        }

        return 1;
    }

    private function getXml($xmlString)
    {
        $xmlString = file_get_contents($this->fileName);
        try {
            $xml = simplexml_load_string($xmlString);
        } catch (Exception $e) {
            $smil->writeToLog('Exception: ', $e->getMessage());
            return (false);
        }
        return ($xml);
    }

    public function makeFifo($fifoPath)
    {
        if (!file_exists($fifoPath)) {
            if (!posix_mkfifo($fifoPath, 0644)) {
                $this->writeToLog("Cannot create a pipe '$fifoPath'");
                return (false);
            }
        }
        return (true);
    }

    public function getNextRecord()
    {
        if (!$this->readXml()) {
            $this->writeToLog("Cannot get next record in playlist");
            return (false);
        }
        foreach ($this->xml->body->playlist as $record) {
            /*
            echo $record["name"] . PHP_EOL;
            echo $record["playOnStream"] . PHP_EOL;
            echo $record["scheduled"] . PHP_EOL;
            echo $record->video["src"] . PHP_EOL;
            echo $record->video["start"] . PHP_EOL;
            echo $record->video["length"] . PHP_EOL;
            echo PHP_EOL;
             */
            $start = $this->date2unix($record->video["start"]);
            if (!$start) {
                return (false);
            }
            if ($start < $this->$timeCounter) {
                continue;
            }
            $this->$timeCounter = $start;
            $this->$videoLength = $record->video["length"];
            return ($record);

        }
        return (array());
    }

    private function readXml()
    {
        for ($i = 0; $i < 5; $i++) {
            // try read xml file
            $xmlString = file_get_contents($this->fileName);
            if ($xmlString) {
                break;
            }
            sleep(1);
        }
        if (!$xmlString) {
            $this->writeToLog("Cannot read content of file " . $this->fileName);
            return (false);
        }
        $this->xml = $this->getXml($xmlString);
        if (!$this->xml) {
            return (false);
        }
        return (true);
    }
}
