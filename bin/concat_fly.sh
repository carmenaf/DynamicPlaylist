#!/bin/bash

cd $( dirname "$0")
BASEDIR=$( pwd )
TMP_DIR="/tmp/smil"
VIDEO_BASE_DIR="/opt/streaming/video"


[ -d $TMP_DIR ] || mkdir $TMP_DIR
EXTERNAL_FIFO="${TMP_DIR}/concat.fifo";

if [ ! -p "$EXTERNAL_FIFO" ]; then
    mkfifo "${EXTERNAL_FIFO:?}"
fi

GET_INFO_SCRIPT="/usr/bin/php ${BASEDIR}/get_video_info.php "




echoerr() {
    #echo `date` "$@" >> $LOG
    echo "Error:" "$@" 1>&2
}
help_usage() {
    echoerr "This script take filename from pipe $EXTERNAL_FIFO and generate hls streams in folder ( parameter subdir )"
    echoerr "Usage: $0 subdir"
    echoerr "Sample: $0 my_playlist"
    exit 1
}

SUBDIR=$1
if [ "x${SUBDIR}" = "x" ] ; then
    help_usage
fi

[ -d ${SUBDIR} ] || mkdir ${SUBDIR}
if [ ! -d ${SUBDIR} ]; then
    echoerr "Cannot create required directory '${SUBDIR}'"
fi

################# START #################
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

echo "launching ffmpeg ...concat videos"
#timeout 60s ffmpeg -y -re -loglevel warning -i "${concat_pls:?}" -pix_fmt yuv422p all.mkv &
#-c:a aac -bsf:a aac_adtstoasc \
#-c:v h264 -bsf:v h264_mp4toannexb -profile:v main -crf 20 -preset veryfast -b:v 800k -maxrate 856k -bufsize 1200k \

ffmpeg -y -loglevel info -i  "${concat_pls:?}" \
-vf "setpts=PTS-STARTPTS" \
-c:a aac -bsf:a aac_adtstoasc -ar 48000 -b:a 196k \
-c:v h264 -bsf:v h264_mp4toannexb -crf 21 -preset veryfast -b:v 5000k -maxrate 5350k -bufsize 7500k -r 25 \
-sc_threshold 0 \
-g 48 -keyint_min 48 \
-hls_time 3  \
-hls_flags append_list \
-hls_playlist_type event \
-hls_allow_cache 1 \
-hls_segment_type mpegts \
-hls_segment_filename "${SUBDIR}/1080p_%04d.ts" "${SUBDIR}/1080p.m3u8" \
\
-vf "scale=w=1280:h=720, setsar=1" \
-c:a aac -bsf:a aac_adtstoasc -ar 48000 -b:a 128k \
-c:v h264 -bsf:v h264_mp4toannexb -crf 21 -preset veryfast -b:v 2800k -maxrate 2996k -bufsize 4200k -r 25 \
-sc_threshold 0 \
-g 48 -keyint_min 48 \
-hls_time 3  \
-hls_flags append_list \
-hls_playlist_type event \
-hls_allow_cache 1 \
-hls_segment_type mpegts \
-hls_segment_filename "${SUBDIR}/720p_%04d.ts" "${SUBDIR}/720p.m3u8" \
\
-vf "scale=w=842:h=480, setsar=1" \
-c:a aac -bsf:a aac_adtstoasc -ar 48000 -b:a 128k \
-c:v h264 -bsf:v h264_mp4toannexb -crf 21 -preset veryfast -b:v 1400k -maxrate 1498k -bufsize 2100k -r 25 \
-sc_threshold 0 \
-g 48 -keyint_min 48 \
-hls_time 3  \
-hls_flags append_list \
-hls_playlist_type event \
-hls_allow_cache 1 \
-hls_segment_type mpegts \
-hls_segment_filename "${SUBDIR}/480p_%04d.ts" "${SUBDIR}/480p.m3u8"  \
\
-vf "scale=w=842:h=480, setsar=1" \
-c:a aac -bsf:a aac_adtstoasc -ar 48000 -b:a 96k \
-c:v h264 -bsf:v h264_mp4toannexb -crf 21 -preset veryfast -b:v 800k -maxrate 856k -bufsize 1200k -r 25 \
-sc_threshold 0 \
-g 48 -keyint_min 48 \
-hls_time 3  \
-hls_flags append_list \
-hls_playlist_type event \
-hls_allow_cache 1 \
-hls_segment_type mpegts \
-hls_segment_filename "${SUBDIR}/360p_%04d.ts" "${SUBDIR}/360p.m3u8" \
&

ffplaypid=$!

echo "Concat videos"
i=0
files_to_remove=""
while true
do
    if read -r -u 3 filename; then
        if [ "x$filename" = "xEOF" ]; then
            break
        fi
        
        for k in $( ${GET_INFO_SCRIPT} -f ${filename}  ); do
            # script GET_INFO_SCRIPT return valuses of duration, width, height
            # export those variables
            export $k
        done
        
        tmp_filename="${filename}.${i}.ts"
        ffmpeg -y -loglevel warning -i "${filename}" -ss 0 -t ${duration} \
        -vf "scale=w=min(iw*${heightHD}/ih\,${widthHD}):h=min(${heightHD}\,ih*${widthHD}/iw), pad=w=${widthHD}:h=${heightHD}:x=(${widthHD}-iw)/2:y=(${heightHD}-ih)/2, setsar=1, setpts=PTS-STARTPTS" \
        -c:a aac -bsf:a aac_adtstoasc  -b:a 192k \
        -c:v h264 -bsf:v h264_mp4toannexb -crf 18 -preset superfast -shortest -r 25 -f mpegts \
        "${tmp_filename}"
        if [ $? -eq 0 ]; then
            if [ -f "${tmp_filename}" ]; then
                fn_concat_feed "${tmp_filename}"
                ((i++));
                echo "Processing file $i"
                files_to_remove="${files_to_remove} ${tmp_filename}"
            fi
        else
            rm "${tmp_filename}" 2>/dev/null
            echo "Something wrong while processing file $filename"
        fi
    fi
done

rm $files_to_remove 2>/dev/null
echo "concat done"

fn_concat_end

wait "${ffplaypid:?}"

echo "done"