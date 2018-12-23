<?php
include_once "smil.php";

$today = date("Y-m-d H:i:s");
$dt = date("U");
$options = getopt('f:n:');

$fileName = isset($options['f']) ? $options['f'] : '';
$count = isset($options['n']) ? $options['n'] : 4;
$durationPart = isset($options['d']) ? $options['d'] : 0;

if (!$fileName) {
    help("Please set the smil filename");
}

$streamSources = [];
$streamSources[] = "http://demo.cdn.mangomolo.com/vod/_definst_/mp4:2018-03-04/vidq87YMk2e3I.mp4/playlist.m3u8";
$streamSources[] = "FZBo2wBH0zE.mp4";
$streamSources[] = "rtmp://alaan.mangomolo.com/alaansrc/udp1.stream";
$streamSources[] = "2ft954vXPa4.mp4";
$streamSources[] = "http://demo.cdn.mangomolo.com/vod/_definst_/mp4:2018-01-17/vidJf9W0omDvi.mp4/playlist.m3u8";
$streamSources[] = "AxoriYVxK5U.mp4";
$streamSources[] = "http://demo.cdn.mangomolo.com/vod/_definst_/mp4:2018-08-16/vidBF9u2zEase.mp4/playlist.m3u8";
$streamSources[] = "http://demo.cdn.mangomolo.com/vod/_definst_/mp4:2018-11-17/vidpQLJMlIexv.mp4/playlist.m3u8";

$smil = new smil($fileName);

$basedir = dirname(__FILE__);
$binDir = "$basedir/bin";
$tmpDir = "/tmp/smil";
$logDir = "$basedir/logs";
$logUrl = "./logs";

$delay = 10; // 60 sec

$dataDir = "$basedir/data";
$configFile = "$dataDir/config.json";

$config = $smil->readJson($configFile);
if (!$config) {
    $smil->writeToLog("Cannot get required parameters from config file");
    exit(1);
}

$timeZone = isset($config["timezone"]) ? $config["timezone"] : "Europe/Moscow";
date_default_timezone_set($timeZone);

$mp4Basedir = isset($config["mp4Basedir"]) ? $config["mp4Basedir"] : "/opt/streaming/mp4";

$videos = array();
/*
foreach (glob("$mp4Basedir/*.mp4") as $videoFileName) {
$path_parts = pathinfo($videoFileName);
$data = $smil->getVideoInfo($videoFileName);
if ($data) {
if ($data['duration'] < 10) {
continue;
}
$videos[] = array('filename' => "mp4:/" . $path_parts['basename'], 'duration' => 10);
}
}
 */

foreach ($streamSources as $videoFileName) {
    if (file_exists("$mp4Basedir/$videoFileName")) {
        $data = $smil->getVideoInfo("$mp4Basedir/$videoFileName");
        if ($data) {
            if ($data['duration'] < 10) {
                continue;
            }
            $videos[] = array('filename' => "mp4:/$videoFileName", 'duration' => 10);
        }
    } else {
        $liveStream = false;
        if (preg_match('/^(rtmp:\/\/.+)$/', $videoFileName, $matches)) {
            $liveStream = true;
        }
        $data = $smil->getVideoInfo($videoFileName, $liveStream);
        if ($data) {
            if (preg_match('/^(rtmp:\/\/.+)$/', $videoFileName, $matches)) {
                $data['duration'] = 10;
            }
            if ($data['duration'] < 10) {
                continue;
            }
            $videos[] = array('filename' => $videoFileName, 'duration' => 10);
        }
    }
}

$body = '<?xml version="1.0" encoding="UTF-8"?>
<smil>
    <head>
    </head>
    <body>
      <stream name="myStream"></stream>';
$i = 303997902;
$dt = date("U") + $delay;
$k = 1;
foreach ($videos as $item) {
    $filename = $item['filename'];
    $duration = $item['duration'];

    $scheduled = date("Y-m-d H:i:s", $dt);
    $body .= "
      <playlist name=\"p_${i}\" playOnStream=\"myStream\" repeat=\"false\" scheduled=\"$scheduled\">
      <video src=\"${filename}\" start=\"0\" length=\"$duration\"/>
      </playlist>" . PHP_EOL;
    $i++;
    $dt += $duration;
    $k++;
    if ($k > $count) {
        break;
    }
}

$body .= '</body>
</smil>';

file_put_contents($fileName, $body);

exit(0);

function help($msg)
{
    $script = basename(__FILE__);
    fwrite(STDERR,
        "$msg
	Usage: $script -f file.smil [-n count_of_records ]
  where:
    file.smil - output file in SMIL format
    count_of_records - how many records will be added into smil file. Default - 4
	\n");
    exit(-1);
}
