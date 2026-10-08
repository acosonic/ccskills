#!/bin/bash
# mix.sh <name> [dir]   raw/<name>.webm + voice/<name>/*.mp3 at the cue times in raw/<name>.cues + voice/music.mp3 -> <name>.mp4
# The music ducks while the narrator speaks and fades at both ends. MUSIC=0.5 sets its level (0 = no music).
# Mixing is separate from recording on purpose: change the music or its level without recording again.
set -e; cd "${2:-.}"; NAME=$1; V=voice/$NAME; IN=(); F=""; M=""; N=0
while read -r _ ms file; do N=$((N+1)); IN+=(-i "$V/$file"); F="$F[$N:a]adelay=$ms:all=1[a$N];"; M="$M[a$N]"; done < raw/$NAME.cues
[ "$N" -gt 0 ] || { echo "no cues in raw/$NAME.cues"; exit 1; }
# Playwright's webm carries no duration in its header: measure it by decoding.
D=$(ffmpeg -i raw/$NAME.webm -f null - 2>&1 | grep -o 'time=[0-9:.]*' | tail -1 | awk -F'[=:]' '{print $2*3600+$3*60+$4}')
# sidechaincompress fails with "could not choose their formats" unless both of its inputs are given one explicit format.
FMT="aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo"
VOICE="$F${M}amix=inputs=$N:normalize=0,loudnorm=I=-16:TP=-1.5,$FMT"
if [ "${MUSIC:-0.5}" = 0 ] || [ ! -f voice/music.mp3 ]; then
  ffmpeg -y -loglevel error -i raw/$NAME.webm "${IN[@]}" -filter_complex "$VOICE[a]" -map 0:v -map "[a]" -t $D -c:v libx264 -crf 21 -pix_fmt yuv420p -c:a aac -b:a 160k -movflags +faststart $NAME.mp4
else
  ffmpeg -y -loglevel error -i raw/$NAME.webm "${IN[@]}" -stream_loop -1 -i voice/music.mp3 -filter_complex "$VOICE,asplit[v1][v2];[$((N+1)):a]$FMT,volume=${MUSIC:-0.5},atrim=0:$D,afade=t=in:d=1.5,afade=t=out:st=$(echo "$D - 3" | bc):d=3[mu];[mu][v2]sidechaincompress=threshold=0.02:ratio=8:attack=40:release=700[duck];[v1][duck]amix=inputs=2:normalize=0,alimiter=limit=0.95[a]" -map 0:v -map "[a]" -t $D -c:v libx264 -crf 21 -pix_fmt yuv420p -c:a aac -b:a 160k -movflags +faststart $NAME.mp4
fi
echo "$NAME.mp4 $(ffprobe -v error -show_entries format=duration -of csv=p=0 $NAME.mp4)s $(du -h $NAME.mp4 | cut -f1), $N narration clips"
