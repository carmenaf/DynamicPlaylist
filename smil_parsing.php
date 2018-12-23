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
$debug = false;

// read command line options
$options = getopt('f:o:');
$fileName = isset($options['f']) ? $options['f'] : '';
$overlayDescriptionFile = isset($options['o']) ? $options['o'] : "$dataDir/empty.json";
if (!$fileName) {
    help("Please set the smil filename");
}
if (!file_exists($fileName)) {
    help("File $fileName do not exists");
}

// create new instance of Smil class
$smil = new Smil($fileName, false);

if (!file_exists($overlayDescriptionFile)) {
    $smil->writeToLog("Overlay file '$overlayDescriptionFile' do not exists");
    exit(1);
}

// read config and set required parameters
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
$maxConcatFiles = isset($config["maxConcatFiles"]) ? $config["maxConcatFiles"] : 100;
$outputResolution= isset($config["outputResolution"]) ? $config["outputResolution"] : array(360,480);

// read smil file, parse and start processing
$streamName = $smil->getStreamName();
if (!$streamName) {
    exit(1);
}

// clean tmpDir
$tmpDir = "/tmp/smil/$streamName";
if (is_dir($tmpDir)) {
    array_map('unlink', glob("$tmpDir/*.*"));
}
@mkdir($tmpDir, 0755, true);

// clean outputHlsDir
$outputHlsDir = "$hlsBasedir/$streamName";
if (is_dir($outputHlsDir)) {
    array_map('unlink', glob("$outputHlsDir/*.*"));
}
@mkdir($outputHlsDir, 0755, true);

// create instance of  ffmpeg_processing class
$ffmpeg_prcessing = new Ffmpeg_processing(false, $tmpDir , 'ffprobe', 'ffmpeg', 'warning', $outputResolution);

// create list of fifo-files for dynamic concatenation
$concatList = $ffmpeg_prcessing->doConcatListFile($maxConcatFiles);
if (!$concatList) {
    exit(1);
}

// run main ffmpeg command in background
$command = $ffmpeg_prcessing->concatStreams($concatList, $outputHlsDir, $overlayDescriptionFile);
$command = "$command >>$logDir/$streamName.log 2>&1 &";
if ($debug) {
    print "Command:" . PHP_EOL;
    print var_dump($command);
}
$ffmpeg_prcessing->doExec($command);

// start main loop for processing each video in smil file
$i = 0;
while (true) {
    $dt = date("U");
    if (isset($lastRecordName)) {
        // get next records by name
        $record = $smil->getNextRecordByName($lastRecordName);
    } else {
        // get first record by shedule
        $record = $smil->getNextRecordByTime($dt);
    }

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

    $lastRecordName = $record['name'];
    $scheduled = $smil->date2unix($record["scheduled"]);
    $start = floatval($record->video["start"]);
    $duration = floatval($record->video["length"]);

    /*
    if ($scheduled - $dt > $delta) {
    usleep(intval(($scheduled - $dt - $delta) * 1000000));
    }
     */

    // usual video files
    if (preg_match('/^mp4:\/(.+)$/', $record->video["src"], $matches)) {
        $videoSource = "$mp4Basedir/" . $matches[1];
        if (file_exists($videoSource)) {
            $outputFile = $ffmpeg_prcessing->getFifoName($i);

            $videoInfo['widthHD'] = 1920;
            $videoInfo['heightHD'] = 1080;
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
            $i++;
        } else {
            $smil->writeToLog("Error: File '$videoSource' do not exists");
        }
    }
    // live stream
    if (preg_match('/^(rtmp:\/\/.+)$/', $record->video["src"], $matches)) {
        $videoSource = $matches[1];
        $videoInfo = $ffmpeg_prcessing->getVideoInfo($videoSource, true);
        if ($videoInfo) {
            $outputFile = $ffmpeg_prcessing->getFifoName($i);

            $videoInfo['widthHD'] = 1920;
            $videoInfo['heightHD'] = 1080;
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
            $i++;
        } else {
            $smil->writeToLog("Error: File '$videoSource' do not available");
        }

    }
    // vod hls ( or dash ) file
    if (preg_match('/^(http:\/\/.+)$/', $record->video["src"], $matches)) {
        $videoSource = $matches[1];
        $videoInfo = $ffmpeg_prcessing->getVideoInfo($videoSource);
        if ($videoInfo) {
            $outputFile = $ffmpeg_prcessing->getFifoName($i);

            $videoInfo['widthHD'] = 1920;
            $videoInfo['heightHD'] = 1080;
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
            $i++;
        } else {
            $smil->writeToLog("Error: File '$videoSource' do not available");
        }

    }
    if (($i + 1) == $maxConcatFiles) {
        $smil->writeToLog("Reached limits of processing files ( $maxConcatFiles ). You can increase value in 'maxConcatFiles' in your config file");
        break;
    }
}

// send /dev/null in to fifo, this will close ffmpeg processing
$outputFile = $ffmpeg_prcessing->getFifoName($i);
$command = "echo /dev/null > $outputFile";
if ($debug) {
    print "Command:" . PHP_EOL;
    print var_dump($command);
}
$ffmpeg_prcessing->doExec($command);
$smil->writeToLog("Done");
exit(0);



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

