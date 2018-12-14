<?php
include_once "smil.php";

$today = date("Y-m-d H:i:s");
$dt = date("U");
$options = getopt('f:o:');

$fileName = isset($options['f']) ? $options['f'] : '';

if (!$fileName) {
    help("Please set the smil filename");
}
if (!file_exists($fileName)) {
    help("File $fileName do not exists");
}

if (!file_exists($overlayFileName)) {
    help("File $fileName do not exists");
}



$smil = new smil($fileName, false);

$basedir = dirname(__FILE__);
$binDir = "$basedir/bin";
$logDir = "$basedir/logs";
$logUrl = "./logs";

$dataDir = "$basedir/data";
$configFile = "$dataDir/config.json";

$processingBin = "$binDir/playlist2stream.sh";

$delta = 1; // 10 sec
$debug = true;

$config = $smil->readJson($configFile);
if (!$config) {
    $smil->writeToLog("Cannot get required parameters from config file");
    exit(1);
}

$timeZone = isset($config["timezone"]) ? $config["timezone"] : "Europe/Moscow";
date_default_timezone_set($timeZone);

$mp4Basedir = isset($config["mp4Basedir"]) ? $config["mp4Basedir"] : "/opt/streaming/mp4";
$hlsBasedir = isset($config["hlsBasedir"]) ? $config["hlsBasedir"] : "/opt/streaming/video/hls";
$preparedVideoBasedir = isset($config["preparedVideoBasedir"]) ? $config["preparedVideoBasedir"] : "/opt/streaming/video/prepared";


$streamName = $smil->getStreamName();
if (!$streamName) {
    exit(1);
}

$tmpDir = "/tmp/smil/$streamName";
$concatFifoPath = "$tmpDir/concat.fifo";
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir);
}
if (!$smil->makeFifo($concatFifoPath)) {
    exit(1);
}

if (!doConcatListFile($tmpDir, $smil, 100)) {
    exit(1);
}

// run ffmpeg processing
$command = "/bin/bash $processingBin $hlsBasedir/$streamName $tmpDir >>$logDir/processing.log 2>&1 &";
$smil->doExec($command);

while (true) {
    $dt = date("U");
    //$now = date("Y-m-d H:i:s");
    $record = $smil->getNextRecordByTime($dt);

    if ($debug) {
        print "Got the record:" . PHP_EOL;
        print var_dump($record);
    }

    if (!$record) {
        // something wrong
        break;
    }

    if (!isset($record->video["src"])) {
        // playlist ended
        break;
    }

    $scheduled = $smil->date2unix($record["scheduled"]);
    $start = floatval($record->video["start"]);
    $length = floatval($record->video["length"]);

    if( $scheduled-$dt >1 ) {
        usleep( intval(($scheduled-$dt-$delta)*1000000 )) ;
    }

    if (preg_match('/^mp4:\/(.+)$/', $record->video["src"], $matches)) {
        $mp4File = "$mp4Basedir/" . $matches[1];
        if (file_exists($mp4File)) {
            // prepare file ( transcode to specified size, add pad, mpegts, stereo, etc )
            $command = "echo '$mp4File' > $concatFifoPath";
            if ($debug) {
                print "Command:" . PHP_EOL;
                print var_dump($command);
            }
            $smil->doExec($command);
            #fwrite($fifo, $mp4File);
            $smil->writeToLog("Send command for processing file '$mp4File'");
        } else {
            $smil->writeToLog("Error: File '$mp4File' do not exists");
        }
    }
    $dt = date("U");
    if ($debug) {
        print "Sleep:" . PHP_EOL;
        print var_dump($scheduled);
        print var_dump($dt);
        print var_dump($length);
        print var_dump($delta);
        print var_dump($scheduled - $dt + $length - $delta);
    }
    //sleep($scheduled - $dt + $length - $delta);
    usleep( intval($length*1000000 )) ;
}

$command = "echo 'EOF' > $concatFifoPath";
if ($debug) {
    print "Command:" . PHP_EOL;
    print var_dump($command);
}
$smil->doExec($command);

#fwrite($fifo, "EOF"); // magic string, finish processing on daemon

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
sleep(1);
@unlink($concatFifoPath);
@unlink($prepareFifoPath);

function help($msg)
{
    $script = basename(__FILE__);
    fwrite(STDERR,
        "$msg
	Usage: $script -f file.smil -o overlay_description.json
  where:
    file.smil - input file in SMIL format
    overlay_description.json - input file in json format
	\n");
    exit(-1);
}

function doConcatListFile($tmpDir, $smil, $count = 100)
{
    $listFile = "$tmpDir/list.txt";
    $listFileBody = "ffconcat version 1.0" . PHP_EOL;
    for ($i = 0; $i < 100; $i++) {
        $fifoName = "$tmpDir/fifo_${i}.tmp";
        if (!$smil->makeFifo($fifoName)) {
            return (false);
        }
        $listFileBody .= "file '$fifoName'" . PHP_EOL;
    }
    file_put_contents($listFile, $listFileBody);
    return (true);
}
