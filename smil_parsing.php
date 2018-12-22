<?php
include_once "smil.php";
include_once "ffmpeg_processing.php";

$today = date("Y-m-d H:i:s");
$dt = date("U");
$basedir = dirname(__FILE__);
$binDir = "$basedir/bin";
$logDir = "$basedir/logs";
$logUrl = "./logs";
$dataDir = "$basedir/data";
$configFile = "$dataDir/config.json";
$processingBin = "$binDir/playlist2stream.sh";
$delta = 2; // 10 sec
$debug = true;
$maxConcatFiles = 100;

$options = getopt('f:o:');

$fileName = isset($options['f']) ? $options['f'] : '';
$overlayDescriptionFile = isset($options['o']) ? $options['o'] : '';
if (!$fileName) {
    help("Please set the smil filename");
}
if (!file_exists($fileName)) {
    help("File $fileName do not exists");
}

$smil = new Smil($fileName, false);

if (!isset($overlayDescriptionFile)) {
    $smil->writeToLog("Overlay file do not set");
}

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

// clean tmpDir
if (is_dir($tmpDir)) {
    array_map('unlink', glob("$tmpDir/*.*"));
}
@mkdir($tmpDir);

$outputHlsDir = "$hlsBasedir/$streamName";
// clean outputHlsDir
if (is_dir($outputHlsDir)) {
    array_map('unlink', glob("$outputHlsDir/*.*"));
}
@mkdir($outputHlsDir);

if (!isset($overlayDescriptionFile)) {
    $overlayDescriptionFile = "$dataDir/empty.json";
}

// run ffmpeg processing
$ffmpeg_prcessing = new Ffmpeg_processing(false, $tmpDir);
$concatList = $ffmpeg_prcessing->doConcatListFile($maxConcatFiles);
if (!$concatList) {
    exit(1);
}

$command = $ffmpeg_prcessing->concatStreams($concatList, $outputHlsDir, $overlayDescriptionFile);
//$command = "/bin/bash $processingBin $hlsBasedir/$streamName $tmpDir >>$logDir/processing.log 2>&1 &";
$ffmpeg_prcessing->doExec("$command >>$logDir/$streamName.log 2>&1 &");

$i = 0;
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
    $duration = floatval($record->video["length"]);

    if ($scheduled - $dt > 1) {
        usleep(intval(($scheduled - $dt - $delta) * 1000000));
    }

    if (preg_match('/^mp4:\/(.+)$/', $record->video["src"], $matches)) {
        $videoSource = "$mp4Basedir/" . $matches[1];
        if (file_exists($videoSource)) {

            $videoInfo = $ffmpeg_prcessing->getVideoInfo($videoSource);
            $outputFile = $ffmpeg_prcessing->getFifoName($i);

            $videoInfo['widthHD'] = 1280;
            $videoInfo['heightHD'] = 720;
            $command = $ffmpeg_prcessing->streamPreProcessing($videoSource, $outputFile, $start, $duration, $videoInfo['widthHD'], $videoInfo['heightHD']);
            if (!isset($videoInfo['audioCodecName'])) {
                $command = $ffmpeg_prcessing->streamPreProcessingWithoutAudio($videoSource, $outputFile, $start, $duration, $videoInfo['widthHD'], $videoInfo['heightHD']);
            }
            if ($debug) {
                print "Command:" . PHP_EOL;
                print var_dump($command);
            }
            $smil->writeToLog("Send command for processing file '$videoSource'");
            $ffmpeg_prcessing->doExec("$command 2>>$logDir/$streamName.$i.log");
        } else {
            $smil->writeToLog("Error: File '$videoSource' do not exists");
        }
        $i++;
    }
    if (preg_match('/^(rtmp:\/\/(.+)$/', $record->video["src"], $matches)) {
        $videoSource =  $matches[1];
        $videoInfo = $ffmpeg_prcessing->getVideoInfo($videoSource);
        if ($videoInfo) {
            $videoInfo = $ffmpeg_prcessing->getVideoInfo($videoSource);
            $outputFile = $ffmpeg_prcessing->getFifoName($i);

            $videoInfo['widthHD'] = 1280;
            $videoInfo['heightHD'] = 720;
            $command = $ffmpeg_prcessing->streamPreProcessing($videoSource, $outputFile, $start, $duration, $videoInfo['widthHD'], $videoInfo['heightHD']);
            if (!isset($videoInfo['audioCodecName'])) {
                $command = $ffmpeg_prcessing->streamPreProcessingWithoutAudio($videoSource, $outputFile, $start, $duration, $videoInfo['widthHD'], $videoInfo['heightHD']);
            }
            if ($debug) {
                print "Command:" . PHP_EOL;
                print var_dump($command);
            }
            $smil->writeToLog("Send command for processing file '$videoSource'");
            $ffmpeg_prcessing->doExec("$command 2>>$logDir/$streamName.$i.log");
        } else {
            $smil->writeToLog("Error: File '$videoSource' do not available");
        }
        $i++;
    }    
    $dt = date("U");
    if ($debug) {
        print "Sleep:" . PHP_EOL;
        print var_dump($scheduled);
        print var_dump($dt);
        print var_dump($duration);
        print var_dump($delta);
        print var_dump($scheduled + $duration - $start - $dt - $delta);
    }
    //sleep($scheduled - $dt + $duration - $delta);
    //usleep(intval($duration * 1000000));
    if (($scheduled + $duration - $dt - $delta) > 0) {
        usleep(intval(($scheduled + $duration - $start - $dt - $delta) * 1000000));
    }
}

$outputFile = $ffmpeg_prcessing->getFifoName($i);
$command = "echo /dev/null > $outputFile";
if ($debug) {
    print "Command:" . PHP_EOL;
    print var_dump($command);
}
$ffmpeg_prcessing->doExec($command);

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

function old_doConcatListFile($tmpDir, $smil, $count = 100)
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
