<?php
include_once "smil.php";

$today = date("F j, Y, g:i a");
$dt = date("U");
$options = getopt('f:');

$fileName = isset($options['f']) ? $options['f'] : '';

if (!$fileName) {
    help("Please set the smil filename");
}
if (!file_exists($fileName)) {
    help("File $fileName do not exists");
}

$smil = new smil($fileName);

$basedir = dirname(__FILE__);
$binDir = "$basedir/bin";
$tmpDir = "/tmp/smil";
$logDir = "$basedir/logs";
$logUrl = "./logs";
$fifoPath = "$tmpDir/concat.fifo";
$dataDir = "$basedir/data";
$configFile = "$dataDir/config.json";
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir);
}

$timeWatrmark = 30;

$config = $smil->readJson($configFile);
if (!$config) {
    $smil->writeToLog("Cannot get required parameters from config file");
    exit(1);
}

$timeZone = isset($config["timezone"]) ? $config["timezone"] : "Europe/Moscow";
date_default_timezone_set($timeZone);

//$xmlString = file_get_contents($fileName);
//$xml=$smil->readXml();
//if( !$smil->readXml()) {
//exit(1);
//}

if (!$smil->makeFifo($fifoPath)) {
    exit(1);
}

$fifo = fopen($fifoPath, 'w');
$timeBorder = 0;
while (true) {
    $record = $smil->getNextRecord();

    if (!$record) {
        // something wrong
        break;
    }

    if (!isset($record->video["src"])) {
        // playlist ended
        break;
    }

    if (preg_match('/^mp4:(.+)$/', $record->video["src"], $matches)) {
        $mp4File = $config["mp4basedir"] . "/" . $matches[1];
        if (file_exists($mp4File)) {
            fwrite($fifo, $mp4File);
            $smil->writeToLog("Send command for processing file '$mp4File'");
        } else {
            $smil->writeToLog("Error: File '$mp4File' do not exists");
        }
    }

    $timeBorder += video["lenght"];
    if ($timeBorder < $timeWatrmark) {
        continue;
    }
    sleep($timeBorder - $timeWatrmark);
    $timeBorder = 0;
}
fwrite($fifo, "EOF"); // magic string, finish processing on daemon

/*
foreach ($xml->body->playlist as $record) {
#echo var_dump($record);
#echo var_dump($record->video["src"]);
echo $record["name"] . PHP_EOL;
echo $record["playOnStream"] . PHP_EOL;
echo $record["scheduled"] . PHP_EOL;
echo $record->video["src"] . PHP_EOL;
echo $record->video["start"] . PHP_EOL;
echo $record->video["length"] . PHP_EOL;
echo PHP_EOL;
}
 */

@unlink($fifoPath);

function help($msg)
{
    $script = basename(__FILE__);
    fwrite(STDERR,
        "$msg
	Usage: $script -f file.smil
  where:
    file.smil - input file in SMIL format
	\n");
    exit(-1);
}
