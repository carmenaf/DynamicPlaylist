<?php
include_once "smil.php";
$smil = new smil();

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

$basedir = dirname(__FILE__);
$binDir = "$basedir/bin";
$tmpDir = "/tmp/smil";
$logDir = "$basedir/logs";
$logUrl = "./logs";
$fifoPath="$tmpDir/concat.fifo";
$dataDir = "$basedir/data";
$configFile = "$dataDir/config.json";



if (!is_dir($tmpDir)) {
    @mkdir($tmpDir);
}


$config = $smil->readJson($configFile);
if (!$config) {
    $smil->writeToLog("Cannot get required parameters from config file");
    exit(1);
}

$timeZone = isset($config["timezone"]) ? $config["timezone"] : "Europe/Moscow";
date_default_timezone_set($timeZone);

$xmlString = file_get_contents($fileName);
try {
    $xml = simplexml_load_string($xmlString);
} catch (Exception $e) {
  $smil->writeToLog( 'Exception: ', $e->getMessage());
    exit(1);
}

if (!file_exists($fifoPath)) {
  if( !posix_mkfifo($fifoPath, 0644)) {
    $smil->writeToLog( "Cannot create a pipe '$fifoPath'");
    exit(1);
  }
}

$fifo = fopen($fifoPath, 'w'); 
foreach ($xml->body->playlist as $playlist) {
    #echo var_dump($playlist);
    #echo var_dump($playlist->video["src"]);
    echo $playlist["name"] . PHP_EOL;
    echo $playlist["playOnStream"] . PHP_EOL;
    echo $playlist["scheduled"] . PHP_EOL;
    echo $playlist->video["src"] . PHP_EOL;
    echo $playlist->video["start"] . PHP_EOL;
    echo $playlist->video["length"] . PHP_EOL;
    echo PHP_EOL;

    if( preg_match( '/^mp4:(.+)$/', $playlist->video["src"], $matches) ) {
        $mp4File=$config["mp4basedir"]."/".$matches[1]; 
        if( file_exists($mp4File )) {
            fwrite($fifo, $mp4File); 
            $smil->writeToLog( "Send command for processing file '$mp4File'");
        } else {
            $smil->writeToLog( "Error: File '$mp4File' do not exists");
        }
    }

}

unlink($fifoPath );

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
