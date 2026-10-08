#!/usr/bin/env python3
"""Transcribe an audio or video file back to text, to check narration you cannot listen to.
  OPENAI_API_KEY=... stt.py <file> [language code]
"""
import json, os, subprocess, sys, tempfile, urllib.request, uuid
src, lang = sys.argv[1], (sys.argv[2] if len(sys.argv) > 2 else 'en')
with tempfile.TemporaryDirectory() as d:
    mp3 = os.path.join(d, 'a.mp3')
    subprocess.check_call(['ffmpeg', '-y', '-loglevel', 'error', '-i', src, '-vn', '-ac', '1', '-ar', '16000', '-b:a', '48k', mp3])
    b = uuid.uuid4().hex
    body = b''.join(('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (b, k, v)).encode() for k, v in (('model', 'gpt-4o-transcribe'), ('language', lang)))
    body += ('--%s\r\nContent-Disposition: form-data; name="file"; filename="a.mp3"\r\nContent-Type: audio/mpeg\r\n\r\n' % b).encode() + open(mp3, 'rb').read() + ('\r\n--%s--\r\n' % b).encode()
    req = urllib.request.Request('https://api.openai.com/v1/audio/transcriptions', data=body, headers={'Authorization': 'Bearer ' + os.environ['OPENAI_API_KEY'], 'Content-Type': 'multipart/form-data; boundary=' + b})
    print(json.load(urllib.request.urlopen(req, timeout=300))['text'])
