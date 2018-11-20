#!/bin/bash

TMP_DIR="/tmp/smil"
[ -d $TMP_DIR ] || mkdir $TMP_DIR
EXTERNAL_FIFO="${TMP_DIR}/concat.fifo";

if [ ! -p "$EXTERNAL_FIFO" ]; then
    mkfifo "${EXTERNAL_FIFO:?}"
fi

exec 3< $EXTERNAL_FIFO 

fn_concat_init() {
    echo "fn_concat_init"
    concat_pls=`mktemp -u -p . concat.XXXXXXXXXX.txt`
    concat_pls="${concat_pls#./}"
    echo "concat_pls=${concat_pls:?}"
    mkfifo "${concat_pls:?}"
    echo
}

fn_concat_feed() {
    echo "fn_concat_feed ${1:?}"
    {
        >&2 echo "removing ${concat_pls:?}"
        rm "${concat_pls:?}"
        concat_pls=
        >&2 fn_concat_init
        echo 'ffconcat version 1.0'
        echo "file '${1:?}'"
        echo "file '${concat_pls:?}'"
    } >"${concat_pls:?}"
    echo
}

fn_concat_end() {
    echo "fn_concat_end"
    {
        >&2 echo "removing ${concat_pls:?}"
        rm "${concat_pls:?}"
        # not writing header.
    } >"${concat_pls:?}"
    echo
}

fn_concat_init

echo "launching ffmpeg ... all.mkv"
#timeout 60s ffmpeg -y -re -loglevel warning -i "${concat_pls:?}" -pix_fmt yuv422p all.mkv &
#-c:a aac -bsf:a aac_adtstoasc \
#-c:v h264 -bsf:v h264_mp4toannexb -profile:v main -crf 20 -preset veryfast -b:v 800k -maxrate 856k -bufsize 1200k \

ffmpeg -y -i  "${concat_pls:?}" \
-vf "setpts=PTS-STARTPTS" \
-c:a aac -bsf:a aac_adtstoasc -ar 48000 -b:a 128k \
-c:v h264 -bsf:v h264_mp4toannexb -crf 20 -preset veryfast -b:v 5000k -maxrate 5350k -bufsize 7500k -r 25 \
-sc_threshold 0 \
-g 48 -keyint_min 48 \
-hls_time 4  \
-hls_flags append_list \
-hls_playlist_type event \
-hls_allow_cache 1 \
-hls_segment_type mpegts \
-hls_segment_filename '1080p_%03d.ts' 1080p.m3u8 \
-vf "scale=w=1280:h=720, setsar=1" \
-c:a aac -bsf:a aac_adtstoasc -ar 48000 -b:a 128k \
-c:v h264 -bsf:v h264_mp4toannexb -crf 20 -preset veryfast -b:v 2800k -maxrate 2996k -bufsize 4200k -r 25 \
-sc_threshold 0 \
-g 48 -keyint_min 48 \
-hls_time 4  \
-hls_flags append_list \
-hls_playlist_type event \
-hls_allow_cache 1 \
-hls_segment_type mpegts \
-hls_segment_filename '720p_%03d.ts' 720p.m3u8 &

ffplaypid=$!

echo "Concat videos"
i=0
while true
do
    if read -r -u 3 filename; then
        if [ "x$filename" = "xEOF" ]; then
            break
        fi
        ffmpeg -y -i  $filename \
        -vf "scale=w=1920:h=1080, setsar=1, setpts=PTS-STARTPTS" \
        -c:a aac -bsf:a aac_adtstoasc \
        -c:v h264 -bsf:v h264_mp4toannexb -profile:v main -crf 18 -preset superfast -b:a 192k -r 25 \
        $filename.$i.mp4
        if [ $? -eq 0 ]; then
            fn_concat_feed $filename.$i.mp4
            rm $filename.$i.mp4
            ((i++));
            echo "Processing file $i"
        else
            echo "Something wrong while processing file $filename"
        fi
    fi
done 

#for filename in 3.mp4 1_30.mp4 4.mp4  2_30.mp4 5.mp4  ; do


echo "concat done"

fn_concat_end

wait "${ffplaypid:?}"

echo "done"
