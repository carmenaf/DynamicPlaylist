<?php

$today = date("F j, Y, g:i a");
$dt = date("U");
$options = getopt('f:p:');

$fileName = isset($options['f']) ? $options['f'] : '';
#$parameter = isset($options['p']) ? $options['p'] : '';

if (!$fileName) {
    help("Please set the video filename");
}
if (!file_exists($fileName)) {
    #  we can get info about rtmp or hls streams
    #help("File $fileName do not exists");
}

#if (!$parameter) {
#   help("Please set the parameter");
#}

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

$data = getVideoInfo($fileName);
if (!$data) {
    exit(1);
}
foreach ($data as $key => $value) {
    print "$key=$value" . PHP_EOL;
}

exit(0);

/**
 * getStreamInfo
 * function get info about video or audio stream in the file
 *
 * @param    string $fileName
 * @return    array 1 for success, 0 for any error
 */
function getVideoInfo($fileName)
{
    # parameter - 'audio' or 'video'
    $ffprobe = "ffprobe";
    $duration = array();
    $data = array();

    if (!$probeJson = json_decode(`"$ffprobe" $fileName -v quiet -hide_banner -show_streams -show_format -of json `, true)) {
        writeToLog("Cannot get info about file $fileName");
        return (false);
    }
    if (empty($probeJson["streams"])) {
        writeToLog("Cannot get info about streams in file $fileName");
        return (false);
    }
    foreach ($probeJson["streams"] as $stream) {

        if ('video' == $stream["codec_type"]) {
            if (empty($stream["height"]) || !intval($stream["height"]) || empty($stream["width"]) || !intval($stream["width"])) {
                writeToLog("File $fileName : invalid or corrupt dimensions");
                return (false);
            }
            $data["height"] = $stream["height"];
            $data["width"] = $stream["width"];
        }
        if (isset($stream['duration']) && $stream['duration'] > 0) {
            $duration[] = $stream['duration'];
        }
        if (isset($stream['tags']['DURATION']) && time2float($stream['tags']['DURATION']) > 0) {
            $duration[] = time2float($stream['tags']['DURATION']);
        }
    }

    if (empty($duration)) {
        writeToLog("Error! File $fileName have incorrect format");
        return (false);
    }
    $data['duration'] = min($duration);
    $rate = $data["width"] / $data["height"];
    $data["widthHD"] = round($data["width"] * 16 / 9 / $rate);
    $data["heightHD"] = $data["height"];

    if ($data['width'] / $data['height'] > 16 / 9) {
        $data["widthHD"] = $data["width"];
        $data["heightHD"] = round($data["height"] * 16 / 9 / $rate);
    }
    return ($data);
}

/**
 * time2float
 * this function translate time in format 00:00:00.00 to seconds
 *
 * @param    string $t
 * @return    float
 */

function time2float($t)
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

function writeToLog($message)
{
    #echo "$message\n";
    fwrite(STDERR, "$message\n");
}

function help($msg)
{
    $script = basename(__FILE__);
    fwrite(STDERR,
        "$msg

    This utility return next inforamtion about video file:
    width, height, duration of shortest stream

	Usage: $script -f video.mp4
	where:
	video.mp4 - video file
	Example: $script -f /path/video.mp4
	\n");
    exit(-1);
}
