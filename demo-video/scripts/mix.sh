#!/bin/bash
# mix.sh <name> [dir]   raw/<name>.webm + voice/<name>/*.mp3 at the cue times in raw/<name>.cues + voice/music.mp3 -> <name>.mp4
# The music ducks while the narrator speaks and fades at both ends. MUSIC=0.5 sets its level (0 = no music).
# The company logo (logo/corner.png — a small plate that reads on any background) sits in the bottom-right corner for the
# whole video. It is required: without it the mix stops. LOGO=other.png picks another file, LOGO=0 leaves it out on purpose.
# Mixing is separate from recording on purpose: change the music, its level or the logo without recording again.
set -e; cd "${2:-.}"; NAME=$1; V=voice/$NAME; IN=(); F=""; M=""; N=0
while read -r _ ms file; do N=$((N+1)); IN+=(-i "$V/$file"); F="$F[$N:a]adelay=$ms:all=1[a$N];"; M="$M[a$N]"; done < raw/$NAME.cues
[ "$N" -gt 0 ] || { echo "no cues in raw/$NAME.cues"; exit 1; }
LOGO=${LOGO:-logo/corner.png}
if [ "$LOGO" != 0 ] && [ ! -f "$LOGO" ]; then
  echo "no company logo: $LOGO is missing (put the logo plate there, or LOGO=0 to mix without it on purpose)"; exit 1
fi
# Playwright's webm carries no duration in its header: measure it by decoding.
D=$(ffmpeg -i raw/$NAME.webm -f null - 2>&1 | grep -o 'time=[0-9:.]*' | tail -1 | awk -F'[=:]' '{print $2*3600+$3*60+$4}')
# sidechaincompress fails with "could not choose their formats" unless both of its inputs are given one explicit format.
FMT="aformat=sample_fmts=fltp:sample_rates=44100:channel_layouts=stereo"
# apad: silence after the last sentence, so the music (and its fade-out) runs to the end of the video.
VOICE="$F${M}amix=inputs=$N:normalize=0,loudnorm=I=-16:TP=-1.5,$FMT,apad"
MUS=(); AUDIO="$VOICE[a]"; NEXT=$((N + 1))
if [ "${MUSIC:-0.5}" != 0 ] && [ -f voice/music.mp3 ]; then
  MUS=(-stream_loop -1 -i voice/music.mp3)
  AUDIO="$VOICE,asplit[v1][v2];[$NEXT:a]$FMT,volume=${MUSIC:-0.5},atrim=0:$D,afade=t=in:d=1.5,afade=t=out:st=$(echo "$D - 3" | bc):d=3[mu];[mu][v2]sidechaincompress=threshold=0.02:ratio=8:attack=40:release=700[duck];[v1][duck]amix=inputs=2:normalize=0,alimiter=limit=0.95[a]"
  NEXT=$((NEXT + 1))
fi
LOG=(); VIDEO="[0:v]null[v]"
if [ "$LOGO" != 0 ]; then
  # Logo plate: 6% of the video height, 2.5% from the edges, slightly transparent
  H=$(ffprobe -v error -select_streams v:0 -show_entries stream=height -of csv=p=0 raw/$NAME.webm)
  LH=$(( H * 6 / 100 / 2 * 2 )); MG=$(( H * 25 / 1000 ))
  LOG=(-loop 1 -i "$LOGO")
  VIDEO="[$NEXT:v]scale=-2:$LH,format=rgba,colorchannelmixer=aa=0.92[lg];[0:v][lg]overlay=W-w-$MG:H-h-$MG:shortest=1[v]"
fi
ffmpeg -y -loglevel error -i raw/$NAME.webm "${IN[@]}" "${MUS[@]}" "${LOG[@]}" -filter_complex "$AUDIO;$VIDEO" -map "[v]" -map "[a]" -t $D \
  -c:v libx264 -crf 21 -pix_fmt yuv420p -c:a aac -b:a 160k -movflags +faststart $NAME.mp4
echo "$NAME.mp4 $(ffprobe -v error -show_entries format=duration -of csv=p=0 $NAME.mp4)s $(du -h $NAME.mp4 | cut -f1), $N narration clips, logo: $LOGO"
