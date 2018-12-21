<?php
class Ffmpeg_processing
{

    private $error; // last error
    //private $time; //
    private $ffprobe;
    private $ffmpeg;
    private $logLevel;

    private $logStdout;
    private $debug;
    private $tmpFiles;
    private $tmpDir;

    public function __construct($debug = false, $tmpDir = '/tmp/smil', $ffprobe = 'ffprobe', $ffmpeg = 'ffmpeg', $logLevel = 'warning')
    {
        $this->error = '';
        $this->ffprobe = $ffprobe;
        $this->ffmpeg = $ffmpeg;
        $this->logLevel = $logLevel;
        $this->logStdout = true;
        $this->debug = $debug;
        $this->tmpFiles = [];
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir);
        }
        $this->tmpDir = $tmpDir;
    }

/**
 * getLastError
 * return last error description
 *
 * @return    string
 */
    public function getLastError()
    {
        return ($this->error);
    }

/**
 * setLastError
 * set last error description
 *
 * @param    string  $err
 * @return    string
 */
    private function setLastError($err)
    {
        $this->error = $err;
        return (true);
    }

    public function writeToLog($message)
    {
        #echo "$message\n";
        $date = date("Y-m-d H:i:s");
        if ($this->logStdout) {
            echo "$date $message" . PHP_EOL;
            return (true);
        }
        fwrite(STDERR, "$date   $message" . PHP_EOL);
    }

    public function readJson($configFile)
    {
        $out = array();
        if (!file_exists($configFile)) {
            $this->writeToLogwriteToLog("File '$configFile' do not exists");
            return ($out);
        }
        $json = file_get_contents($configFile);
        if (!$json) {
            $this->writeToLog("Cannot read file '$configFile'");
            return ($out);
        }
        $out = json_decode($json, true);
        if (!$out) {
            $this->writeToLog("Incorrect json string in json file '$configFile'");
            return (array());
        }
        return ($out);
    }

    public function reStreamToFile($fileName, $outputFile , $duration )
    {
        // this function copy PREPARED (!!!) file or stream to fifo for concatenation
        // $fileNmae can be file or stream like rtmp://localhost/mystream
        $logLevel = $this->logLevel;
        $data = join(' ', array(
            $this->ffmpeg,
            " -y -loglevel ${logLevel} -i \"${fileName}\" -ss 0 -t ${duration} ",
            "-c copy  -bsf:a aac_adtstoasc  -bsf:v h264_mp4toannexb ",
            "-f mpegts - | cat > ${outputFile}",
        ));
        return ($data);
    }


    public function streamPreProcessingWithoutAudio($fileName, $outputFile, $duration, $widthHD = 1280, $heightHD = 720)
    {
        // if source don't have audio stream
        // output can be usual file and FIFO file
        $logLevel = $this->logLevel;

        $data = join(' ', array(
            $this->ffmpeg,
            " -y -loglevel ${logLevel} -f lavfi -i \"anullsrc=channel_layout=stereo:sample_rate=44100\" -i \"${fileName}\" -ss 0 -t ${duration} ",
            "-vf \"scale=w=min(iw*${heightHD}/ih\,${widthHD}):h=min(${heightHD}\,ih*${widthHD}/iw), ",
            "pad=w=${widthHD}:h=${heightHD}:x=(${widthHD}-iw)/2:y=(${heightHD}-ih)/2, setsar=1, setpts=PTS-STARTPTS \" ",
            "-c:a aac -bsf:a aac_adtstoasc -b:a 96k -ac 2  ",
            "-c:v h264 -bsf:v h264_mp4toannexb -crf 21 -preset veryfast -b:v 800k -maxrate 856k -bufsize 1200k -r 25 ",
            "-shortest ",
            "-f mpegts - | cat > ${outputFile}",
        ));
        return ($data);
    }

    public function streamPreProcessing($fileName, $outputFile, $duration, $widthHD = 1280, $heightHD = 720)
    {
        $logLevel = $this->logLevel;

        // output can be usual file and FIFO file
        $data = join(' ', array(
            $this->ffmpeg,
            "-y -loglevel ${logLevel} -i \"${fileName}\" -ss 0 -t ${duration} ",
            "-vf \"scale=w=min(iw*${heightHD}/ih\,${widthHD}):h=min(${heightHD}\,ih*${widthHD}/iw), ",
            "pad=w=${widthHD}:h=${heightHD}:x=(${widthHD}-iw)/2:y=(${heightHD}-ih)/2, setsar=1, setpts=PTS-STARTPTS \" ",
            "-af \"aresample=44100, asetpts=PTS-STARTPTS\" ",
            "-c:a aac -bsf:a aac_adtstoasc -b:a 96k -ac 2  ",
            "-c:v h264 -bsf:v h264_mp4toannexb -crf 21 -preset veryfast -b:v 800k -maxrate 856k -bufsize 1200k -r 25 ",
            "-shortest ",
            "-f mpegts - | cat > ${outputFile} ",
        ));
        return ($data);
    }

    public function concatStreams($fileName, $hlsDir, $overlayDescriptionFile)
    {
        $logLevel = $this->logLevel;

        $overlaysData = $this->readJson($overlayDescriptionFile);

        $overlayImage = array();
        $scaleFilter = array();
        $overlayFilters = array();
        $overlayText = array();
        $includeTextOverlay = 'null';
        $i = 1;

        foreach ($overlaysData['overlay'] as $overlay) {
//          if (isset($overlay['type']) && $overlay['type'] == 'image' && file_exists($overlay['properties']['file'])) {
            if (isset($overlay['type']) && $overlay['type'] == 'image') {
                if (!isset($overlay['properties']['file']) || !file_exists($overlay['properties']['file'])) {
                    $this->writeToLog("Overlay file" . $overlay['properties']['file'] . "do not exists");
                    //continue;
                }
                $x = $overlay['properties']['x'];
                $y = $overlay['properties']['y'];
                $width = $overlay['properties']['width'];
                $height = $overlay['properties']['height'];
                $start = $overlay['properties']['start'];
                $end = $overlay['properties']['end'];
                $duration = $end - $start;
                $overlayImage[] = " -loop 1 -ss 0 -t $duration -i " . $overlay['properties']['file'];
                $fadeIn = 'null';
                $fadeOut = 'null';
                if (isset($overlay['properties']['fade_in'])) {
                    $fadeIn = "fade=in:alpha=1:st=0:duration=" . $overlay['properties']['fade_in'];
                }
                if (isset($overlay['properties']['fade_out'])) {
                    $fadeOutStart = $duration - $overlay['properties']['fade_out'];
                    $fadeOut = "fade=out:alpha=1:st=$fadeOutStart:duration=" . $overlay['properties']['fade_out'];
                }
                $n = $i - 1;
                $overlayFilters[] = "[v_${n}] [overlay_${i}] overlay=x=${x}:y=${y}:enable='between(t, ${start}, ${end})' [v_${i}] ;";

                $scaleFilter[] = "[$i:v] scale=w=${width}:h=${height}:force_original_aspect_ratio=decrease, $fadeIn, $fadeOut, setpts=PTS+$start/TB [overlay_${i}]; ";

#

                $i++;
                continue;
            }

//          if (isset($overlay['type']) && $overlay['type'] == 'image' && file_exists($overlay['properties']['file'])) {
            if (isset($overlay['type']) && $overlay['type'] == 'video') {
                if (!isset($overlay['properties']['file']) || !file_exists($overlay['properties']['file'])) {
                    $this->writeToLog("Overlay file" . $overlay['properties']['file'] . "do not exists");
                    //continue;
                }
                $x = $overlay['properties']['x'];
                $y = $overlay['properties']['y'];
                $width = $overlay['properties']['width'];
                $height = $overlay['properties']['height'];
                $start = $overlay['properties']['start'];
                $end = $overlay['properties']['end'];

                $chromakey = "null";
                if (isset($overlay['properties']['chromakey'])) {
                    $blend = $overlay['properties']['chromakey']['blend'];
                    $similarity = $overlay['properties']['chromakey']['similarity'];
                    $color = $overlay['properties']['chromakey']['color'];

                    $chromakey = "chromakey=color=$color:similarity=$similarity:blend=$blend";
                }
                $duration = $end - $start;
                $overlayImage[] = " -ss 0 -t $duration -i " . $overlay['properties']['file'];
                $fadeIn = 'null';
                $fadeOut = 'null';
                if (isset($overlay['properties']['fade_in'])) {
                    $fadeIn = "fade=in:alpha=1:st=0:duration=" . $overlay['properties']['fade_in'];
                }
                if (isset($overlay['properties']['fade_out'])) {
                    $fadeOutStart = $duration - $overlay['properties']['fade_out'];
                    $fadeOut = "fade=out:alpha=1:st=$fadeOutStart:duration=" . $overlay['properties']['fade_out'];
                }
                $n = $i - 1;
                $overlayFilters[] = "[v_${n}] [overlay_${i}] overlay=x=${x}:y=${y}:enable='between(t, ${start}, ${end})' [v_${i}] ;";

                $scaleFilter[] = "[$i:v] scale=w=${width}:h=${height}:force_original_aspect_ratio=decrease, $chromakey, $fadeIn, $fadeOut, setpts=PTS+$start/TB [overlay_${i}]; ";
                $i++;
                continue;
            }
            if (isset($overlay['type']) && $overlay['type'] == 'text') {
                $overlayText[] = $overlay['properties'];
                continue;
            }

        }
        if (!empty($overlayText)) {
            $temporaryAssFile = time() . sha1(rand(100, 1000000)) . ".ass";
            if ($this->prepareSubtitles(1920, 1080, $temporaryAssFile, $overlayText)) {
                $includeTextOverlay = "ass=$temporaryAssFile";
            }
        }

        $n = $i - 1;
        // output can be usual file and FIFO file
        $data = join(' ', array(
            $this->ffmpeg,
            "-y -loglevel ${logLevel} -f concat -safe 0  -i ${fileName}",
            join(" ", $overlayImage),
            "-filter_complex \"[0:v] scale=w=640:h=360, setsar=1, setpts=PTS-STARTPTS [v_0]; ",
            join(" ", $scaleFilter),
            join(" ", $overlayFilters),
            "[v_${n}] ${includeTextOverlay} [v] ;",
            "[0:a]asetrate=44100 , asetpts=PTS-STARTPTS [a] \"",
            "-map \"[v]\" -map \"[a]\" ",
            "-c:v h264 -bsf:v h264_mp4toannexb -crf 21 -preset veryfast -b:v 800k -maxrate 856k ",
            "-sc_threshold 0 ",
            "-g 48 -keyint_min 48 ",
            "-bufsize 1200k -r 25",
            "-c:a aac -bsf:a aac_adtstoasc -ar 48000 -b:a 192k -ac 2 ",
            "-shortest ",
            "-hls_time 4  ",
            "-hls_flags append_list ",
            "-hls_playlist_type event ",
            "-hls_allow_cache 1 ",
            "-hls_segment_type mpegts ",
            " output.ts",
            //"-hls_segment_filename '${hlsDir}/360p_%04d.ts' '${hlsDir}/360p.m3u8'",
            //"&",
        ));
        return ($data);
    }

/**
 * prepareSubtitles
 * prepare ASS subtitles file
 *
 * @param    integer   $width - video width
 * @param    integer   $height - video height
 * @param    string    $temporaryAssFile
 * @param    array   $textProprtiesArray
 *
 * @return   boolean
 */
    public function prepareSubtitles(
        $width = 1920,
        $height = 1080,
        $temporaryAssFile,
        $textProprtiesArray
    ) {
        $styles = '';
        $dialog = '';
        $i = 0;
        foreach ($textProprtiesArray as $record) {
            $start = $this->float2time($record['start']);
            $end = $this->float2time($record['end']);
            $text = preg_replace('/\s*$/', '', $record['text']); // remove \n and spaces in the end of text
            $fixedText = preg_replace('/\s*\n\s*/', '\N', $text);
            //$fixedText = preg_replace('/\s*\N\s*$/', '', $fixedText);
            $lines = substr_count($text, "\N");

            $font = $record['font'];
            $fontColor = $this->getKlmColor($record['font_color'], $record['font_opacity']);
            $fontSize = $record['font_size'];
            $outLine = $record['out_line'];
            $outLineColor = $this->getKlmColor($record['out_line_color'], $record['out_line_opacity']);

            $styleBold = 0;
            $styleItalic = 0;

            $fadeIn = 1000 * $record['fade_in'];
            $fadeOut = 1000 * $record['fade_out'];

            $x = $record['x'];
            $y = $record['y'];

            $styleMarginL = 10;
            $styleMarginR = 10;

            switch ($record['align']) {
                case "left":
                    $alignment = 7;
                    break;
                case "right":
                    $alignment = 9;
                    break;
                case "center":
                    $alignment = 8;

                    break;
                default:
                    $alignment = 7;
            }

            $wrapWidth = 50;
            try {
                $textWidthinPx = $this->getWidthOfTextinPx($fontSize, $font, $text);
                $wrapWidth = $textWidthinPx / $width + 1;
            } catch (Exception $e) {
                $this->writeToLog("Exception " . $e->getMessage());
            }

            $text = wordwrap($text, $wrapWidth);
            if ($record['bar']) {
                // todo
            }
            $useStyle = "Style_${i}";
            $styles .= "Style: $useStyle,$font,$fontSize,$fontColor,&H000000FF,$outLineColor,&H00919198,$styleBold,$styleItalic,0,0,100,100,0,0,1,$outLine,0,$alignment,$styleMarginL,$styleMarginR,10,1" . PHP_EOL;
            $dialog .= "Dialogue: $i,$start,$end,$useStyle,,0,0,0,,{\\1c$fontColor \\fad($fadeIn, $fadeOut) \\pos($x,$y) }$fixedText" . PHP_EOL;
        }
        $content = "[Script Info]
; Aegisub 3.2.2
; http://www.aegisub.org/
; FfmpegEffects php lib
; korolev-ia [at] yandex.ru
ScriptType: v4.00+
PlayResX: $width
PlayResY: $height
WrapStyle: 2
YCbCr Matrix: TV.601


[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: AdditionalText,Arial,28,&H00FFFFFF,&H9D000000,&H00000000,&H00000000,0,0,0,0,100,100,0,0,3,4,0,9,0,0,0,1
$styles

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
$dialog
";

        if (!file_put_contents($temporaryAssFile, $content)) {
            $this->writeToLog("Cannot save temporary subtitles file '$temporaryAssFile'");
            return (false);
        }
        return (true);
    }

    public function getFontProperties($fontFile, $defaultFamily = 'Sans')
    {
        $cmd = "/usr/bin/fc-list | /bin/grep $fontFile | /usr/bin/head -1 2>/dev/null";
        $fontProperties = array();
        $fontProperties['family'] = $defaultFamily;
        $fontProperties['bold'] = 0;
        $fontProperties['italic'] = 0;
        $data = ` $cmd `;
        if ($data) {
            if (preg_match('/\s*(.+)\s*:\s*(.+)\s*:style=.*Bold/', $data, $matches)) {
                $fontProperties['family'] = $matches[2];
                $fontProperties['bold'] = -1;
            }
            if (preg_match('/\s*(.+)\s*:\s*(.+)\s*:style=.*Italic/', $data, $matches)) {
                $fontProperties['family'] = $matches[2];
                $fontProperties['italic'] = -1;
            }
        }
        return ($fontProperties);
    }

// RRGGBB to AABBGGRR
    public function getKlmColor($htmlColor, $alpha = '00')
    {
        $color = strtoupper("&H${alpha}" . substr($htmlColor, 4, 2) . substr($htmlColor, 2, 2) . substr($htmlColor, 0, 2));
        return ($color);
    }

    public function time2float($t)
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
        return sprintf("%01d:%02d:%05.2f", $h, $m, $s);
    }

    private function getWidthOfTextinPx($fontSize, $font, $text)
    {
        $bbox = imagettfbbox($fontSize, 0, $font, $text);
        $xcorr = 0 - $bbox[6]; //northwest X
        $ycorr = 0 - $bbox[7]; //northwest Y
        $tmp_bbox['left'] = $bbox[6] + $xcorr;
        $tmp_bbox['top'] = $bbox[7] + $ycorr;
        $tmp_bbox['width'] = $bbox[2] + $xcorr;
        $tmp_bbox['height'] = $bbox[3] + $ycorr;
        return ($tmp_bbox['width']);
    }

    public function getTempioraryFile($extension)
    {
        $tmp = $this->tmpDir . "/" . time() . sha1(rand(100, 1000000)) . ".$extension";
        $this->tmpFiles[] = $tmp;
        return ($tmp);
    }

    public function removeTempioraryFiles()
    {
        foreach ($this->tmpFiles as $tmpFile) {
            @unlink($tmpFile);
        }
        return (true);
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

    public function getFifoName($number)
    {
        $fifoName = $this->tmpDir . "/fifo_${number}.tmp";
        return ($fifoName);
    }

    public function doConcatListFile($count = 100)
    {
        $listFile = $this->tmpDir . "/list.txt";
        $listFileBody = "ffconcat version 1.0" . PHP_EOL;
        for ($i = 0; $i < $count; $i++) {
            $fifoName = $this->getFifoName($i);
            // $fifoName = $this->tmpDir . "/fifo_${i}.tmp";
            if (!$this->makeFifo($fifoName)) {
                $this->writeToLogwriteToLog("Cannot prepare list of fifo files");
                return (false);
            }
            $listFileBody .= "file '$fifoName'" . PHP_EOL;
        }
        file_put_contents($listFile, $listFileBody);
        return ($listFile);
    }

    public function getVideoInfo($fileName)
    {
        $ffprobe = $this->ffprobe;
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
            if (isset($stream['tags']['DURATION']) && $this->time2float($stream['tags']['DURATION']) > 0) {
                $duration[] = $this->time2float($stream['tags']['DURATION']);
            }
            if ('audio' == $stream["codec_type"]) {
                $data["audioCodecName"] = $stream["codec_name"];
                $data["audioSampleRate"] = $stream["sample_rate"];
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

}
