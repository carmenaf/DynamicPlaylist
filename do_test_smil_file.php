<?php
include_once "smil.php";

$today = date("Y-m-d H:i:s");
$dt = date("U");
$options = getopt('f:');

$fileName = isset($options['f']) ? $options['f'] : '';

if (!$fileName) {
    help("Please set the smil filename");
}

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
foreach (glob("$mp4Basedir/*.*") as $videoFileName) {
    $path_parts = pathinfo($videoFileName);

    #echo $path_parts['dirname'], "\n";
    #echo $path_parts['basename'], "\n";
    #echo $path_parts['extension'], "\n";
    #echo $path_parts['videoFileName'], "\n"; // начиная с PHP 5.2.0
    #echo "$filename размер " . filesize($filename) . "\n";
    $data = $smil->getVideoInfo($videoFileName);
    if ($data) {
        $videos[] = array('filename' => $path_parts['basename'], 'duration' => $data['duration']);
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
foreach ($videos as $item) {
    $filename = $item['filename'];
    $duration = $item['duration'];

    $scheduled = date("Y-m-d H:i:s", $dt);
    $body .= "
      <playlist name=\"p_${i}\" playOnStream=\"myStream\" repeat=\"false\" scheduled=\"$scheduled\">
      <video src=\"mp4:/${filename}\" start=\"0\" length=\"$duration\"/>
      </playlist>" . PHP_EOL;
    $i++;
    $dt += $duration;
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
	Usage: $script -f file.smil
  where:
    file.smil - output file in SMIL format
	\n");
    exit(-1);
}
