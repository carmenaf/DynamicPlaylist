#!/bin/bash

cd $( dirname "$0")
BASEDIR=$( pwd )
TMP_DIR="/tmp/smil"
VIDEO_BASE_DIR="/opt/streaming/video"
LOGLEVEL='warning'

LOG="${TMP_DIR}/processing.log"


[ -d $TMP_DIR ] || mkdir $TMP_DIR
EXTERNAL_FIFO="${TMP_DIR}/concat.fifo";

if [ ! -p "$EXTERNAL_FIFO" ]; then
    mkfifo "${EXTERNAL_FIFO:?}"
fi

DATA_FIFO="${TMP_DIR}/data1.fifo";
if [ ! -p "$DATA_FIFO" ]; then
    mkfifo "${DATA_FIFO:?}"
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
    concat_pls=$( mktemp --dry-run  tmp.XXXXXXXXXX )
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
        echo "#"
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

ffmpeg -y -loglevel ${LOGLEVEL} -re -f concat -safe 0  -i "${concat_pls:?}" \
-vf "scale=w=min(iw*360/ih\,640):h=min(360\,ih*640/iw), pad=w=640:h=360:x=(640-iw)/2:y=(360-ih)/2, setsar=1, setpts=PTS-STARTPTS" \
-c:v h264 -bsf:v h264_mp4toannexb -crf 21 -preset veryfast -b:v 800k -maxrate 856k -bufsize 1200k -r 25 -shortest \
-af "apad, asetpts=PTS-STARTPTS" \
-c:a aac -bsf:a aac_adtstoasc -ar 48000 -b:a 192k -ac 2 \
-sc_threshold 0 \
-g 48 -keyint_min 48 \
-f flv  rtmp://localhost/myapp/stream 2>>${LOG} \
&

ffplaypid=$!

################# START #################
i=0
files_to_remove=""
while true
do
    if read -r -u 3 filename; then
        if [ "x$filename" = "xEOF" ]; then
            break
        fi
        
        for k in $( ${GET_INFO_SCRIPT} -f ${filename}  ); do
            if [ $? -ne 0 ]; then
                continue
            fi
            # script GET_INFO_SCRIPT return valuses of duration, width, height
            # export those variables
            export $k
        done
        
        #DATA_FIFO=$( mktemp --dry-run  tmp.XXXXXXXXXX --tmpdir=${TMP_DIR} )
        DATA_FIFO=$( mktemp --dry-run  tmp.XXXXXXXXXX  )
        mkfifo "${DATA_FIFO:?}"
        
        tmp_filename="${SUBDIR}/${filename}"
        ffmpeg -y -loglevel ${LOGLEVEL} -i "${filename}" -ss 0 -t ${duration} \
        -vf "scale=w=min(iw*${heightHD}/ih\,${widthHD}):h=min(${heightHD}\,ih*${widthHD}/iw), pad=w=${widthHD}:h=${heightHD}:x=(${widthHD}-iw)/2:y=(${heightHD}-ih)/2, setsar=1, setpts=N/FRAME_RATE/TB " \
        -af "apad , asetpts=PTS-STARTPTS" \
        -c:a aac -bsf:a aac_adtstoasc -ar 48000 -b:a 96k -ac 2 \
        -c:v h264 -bsf:v h264_mp4toannexb -crf 21 -preset slow -b:v 800k -maxrate 856k -bufsize 1200k -r 25 \
        -f flv - | cat > $DATA_FIFO 2>>${LOG} &
        
        prepare_pid=$!
        
        fn_concat_feed "${DATA_FIFO}"
        
        wait "${prepare_pid:?}"
        
        if [ $? -eq 0 ]; then
            echo "Processing file $i : $filename"
        else
            rm "${tmp_filename}" 2>/dev/null
            echoerr "Something wrong while processing file $filename"
        fi
        files_to_remove="${files_to_remove} ${DATA_FIFO}"
    fi
done

rm $files_to_remove 2>/dev/null
echo "concat done"

fn_concat_end

wait "${ffplaypid:?}"

echo "done"