#!/bin/bash

cd $( dirname "$0")
BASEDIR=$( pwd )
LOGLEVEL='warning'
GET_INFO_SCRIPT="/usr/bin/php ${BASEDIR}/get_video_info.php "


echoerr() {
    #echo `date` "$@" >> $LOG
    echo "Error:" "$@" 1>&2
}
help_usage() {
    echoerr "This script take filename from pipe $EXTERNAL_FIFO and generate hls streams in folder ( parameter HLS_DIR )"
    echoerr "Usage: $0 hls_output_dir temp_dir"
    echoerr "Sample: $0 /opt/streaminv/video/myStream /tmp/smil/myStream"
    exit 1
}


HLS_DIR=$1
if [ "x${HLS_DIR}" = "x" ] ; then
    help_usage
fi
TMP_DIR=$2
if [ "x${TMP_DIR}" = "x" ] ; then
    help_usage
fi

[ -d ${HLS_DIR} ] || mkdir ${HLS_DIR}
if [ ! -d ${HLS_DIR} ]; then
    echoerr "Cannot create required directory '${HLS_DIR}'"
fi


[ -d $TMP_DIR ] || mkdir $TMP_DIR
if [ ! -d ${TMP_DIR} ]; then
    echoerr "Cannot create required directory '${TMP_DIR}'"
fi

EXTERNAL_FIFO="${TMP_DIR}/concat.fifo";
LOG="${TMP_DIR}/processing.log"
LIST_CONCAT_FILE="${TMP_DIR}/list.txt";

echo "External fifo: $EXTERNAL_FIFO"

if [ ! -p "$EXTERNAL_FIFO" ]; then
    mkfifo "${EXTERNAL_FIFO:?}"
fi

APID=$TMP_DIR/`basename $0`.pid
if [ -f $APID ]; then
    ps --pid `cat $APID` -o cmd h | grep `basename $0` >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        echoerr "Process $0 alredy running"
        exit 1
    fi
fi
echo $$ > $APID


# clean hls dir
rm ${HLS_DIR}/*

################# START #################
exec 3< $EXTERNAL_FIFO


echo "launching ffmpeg ...concat videos"
#timeout 60s ffmpeg -y -re -loglevel warning -i "${concat_pls:?}" -pix_fmt yuv422p all.mkv &
#-c:a aac -bsf:a aac_adtstoasc \
#-c:v h264 -bsf:v h264_mp4toannexb -profile:v main -crf 20 -preset veryfast -b:v 800k -maxrate 856k -bufsize 1200k \

ffmpeg -y -loglevel ${LOGLEVEL} -f concat -safe 0  -i "${LIST_CONCAT_FILE:?}" \
-vf "scale=w=640:h=360, setsar=1, setpts=PTS-STARTPTS" \
-c:v h264 -bsf:v h264_mp4toannexb -crf 21 -preset veryfast -b:v 800k -maxrate 856k -bufsize 1200k -r 25 -shortest \
-af "asetrate=44100 , asetpts=PTS-STARTPTS" \
-c:a aac -bsf:a aac_adtstoasc -ar 48000 -b:a 192k -ac 2 \
-vsync 1 \
-shortest \
-sc_threshold 0 \
-g 48 -keyint_min 48 \
-hls_time 4  \
-hls_flags append_list \
-hls_playlist_type event \
-hls_allow_cache 1 \
-hls_segment_type mpegts \
-hls_segment_filename "${HLS_DIR}/360p_%04d.ts" "${HLS_DIR}/360p.m3u8" \
\
-vf "scale=w=842:h=480, setsar=1, setpts=PTS-STARTPTS" \
-c:v h264 -bsf:v h264_mp4toannexb -crf 21 -preset veryfast -b:v 800k -maxrate 856k -bufsize 1200k -r 25 -shortest \
-af "asetrate=44100 , asetpts=PTS-STARTPTS" \
-c:a aac -bsf:a aac_adtstoasc -ar 48000 -b:a 192k -ac 2 \
-vsync 1 \
-shortest \
-sc_threshold 0 \
-g 48 -keyint_min 48 \
-hls_time 4  \
-hls_flags append_list \
-hls_playlist_type event \
-hls_allow_cache 1 \
-hls_segment_type mpegts \
-hls_segment_filename "${HLS_DIR}/480p_%04d.ts" "${HLS_DIR}/480p.m3u8"  \
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
        #DATA_FIFO=$( mktemp --dry-run  tmp.XXXXXXXXXX  )
        DATA_FIFO="${TMP_DIR}/fifo_${i}.tmp"
        
        #tmp_filename="${HLS_DIR}/${filename}"
        heightHD=480
        widthHD=842
        ffmpeg -y -loglevel ${LOGLEVEL} -i "${filename}" -ss 0 -t ${duration} \
        -vf "scale=w=min(iw*${heightHD}/ih\,${widthHD}):h=min(${heightHD}\,ih*${widthHD}/iw), pad=w=${widthHD}:h=${heightHD}:x=(${widthHD}-iw)/2:y=(${heightHD}-ih)/2, setsar=1, setpts=PTS-STARTPTS " \
        -af "aresample=async=1:first_pts=0 , asetrate=44100 , asetpts=PTS-STARTPTS" \
        -c:a aac -bsf:a aac_adtstoasc -b:a 96k -ac 2 -async 1 -vsync 1 \
        -c:v h264 -bsf:v h264_mp4toannexb -crf 21 -preset slow -b:v 800k -maxrate 856k -bufsize 1200k -r 25 \
        -shortest \
        -f mpegts - | cat > $DATA_FIFO
        
        #prepare_pid=$!
        
        #fn_concat_feed "${DATA_FIFO}"
        
        #wait "${prepare_pid:?}"
        
        #if [ $? -eq 0 ]; then
        ((i++));
        echo "Processing file $i : $filename"
        #else
        #rm "${tmp_filename}" 2>/dev/null
        #echoerr "Something wrong while processing file $filename"
        #fi
        #files_to_remove="${files_to_remove} ${DATA_FIFO}"
    fi
done

DATA_FIFO="${TMP_DIR}/fifo_${i}.tmp"
echo /dev/null > "${DATA_FIFO}"

#for k in $( seq $i 99)l do
#    DATA_FIFO=$( ${TMP_DIR}/fifo_${i}.tmp )
#    echo /dev/null > "${DATA_FIFO}"
#    files_to_remove="${files_to_remove} ${DATA_FIFO}"
#done

rm $files_to_remove 2>/dev/null
echo "concat done"

#fn_concat_end

wait "${ffplaypid:?}"
rm  "${APID:?}"

echo "done"