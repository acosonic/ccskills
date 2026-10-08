#!/bin/bash
# check.sh <video.mp4>   what can be verified without ears: streams, loudness of speech against the music bed, and a contact sheet.
set -e; V=$1; OUT=${2:-${V%.mp4}-sheet.png}
ffprobe -v error -show_entries stream=codec_type,codec_name,duration -of csv=p=0 "$V"
ffmpeg -i "$V" -af "astats=metadata=1:reset=1,ametadata=print:key=lavfi.astats.Overall.RMS_level:file=-" -vn -f null - 2>/dev/null | grep RMS_level | cut -d= -f2 | sort -n \
  | awk '{a[NR]=$1} END {print "quietest tenth (music alone): " a[int(NR*0.1)] " dB, loudest tenth (speech): " a[int(NR*0.9)] " dB"}'
D=$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$V"); ffmpeg -y -loglevel error -i "$V" -vf "fps=12/$D,scale=480:-1,tile=4x3" -frames:v 1 "$OUT"; echo "contact sheet: $OUT"
