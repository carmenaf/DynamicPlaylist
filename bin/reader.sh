#!/bin/bash

TMP_DIR="/tmp/smil"
[ -d $TMP_DIR ] || mkdir $TMP_DIR
EXTERNAL_FIFO="${TMP_DIR}/concat.fifo";

if [ ! -p "$EXTERNAL_FIFO" ]; then
    mkfifo "${EXTERNAL_FIFO:?}"
fi

while true
do
    if read filename; then
        if [ "x$filename" = "xEOF" ]; then
            break
        fi
        echo $filename
        sleep 20
    fi
done <"$EXTERNAL_FIFO"