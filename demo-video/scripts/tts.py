#!/usr/bin/env python3
"""One narration clip per scene with OpenAI text-to-speech.

  OPENAI_API_KEY=... tts.py <set> [--voice onyx] [--dir .]
  OPENAI_API_KEY=... tts.py --samples "One sentence to compare." --lang sr onyx ash echo cedar ballad verse

Reads <dir>/voice/narration.json ({"<set>": {"<scene>": "<text>", ...}}) and <dir>/voice/style.json
({"sr": "...", "en": "..."}; the set name's last two letters pick the language). Writes voice/<set>/NN.mp3 and
voice/<set>/durations.json (scene -> file, seconds), which the recording uses to pace each scene.
The key is read from the environment only and never printed.
"""
import argparse, json, os, subprocess, sys, urllib.request, urllib.error

def speak(text, voice, style, out):
    req = urllib.request.Request('https://api.openai.com/v1/audio/speech', method='POST',
        headers={'Authorization': 'Bearer ' + os.environ['OPENAI_API_KEY'], 'Content-Type': 'application/json'},
        data=json.dumps({'model': 'gpt-4o-mini-tts', 'voice': voice, 'input': text, 'instructions': style, 'response_format': 'mp3'}).encode())
    try:
        with urllib.request.urlopen(req, timeout=180) as r, open(out, 'wb') as f:
            f.write(r.read())
    except urllib.error.HTTPError as e:
        sys.exit('HTTP %s from the speech API for %s' % (e.code, out))

def seconds(path):
    return round(float(subprocess.check_output(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', path])), 2)

p = argparse.ArgumentParser()
p.add_argument('names', nargs='*'); p.add_argument('--voice', default='onyx'); p.add_argument('--dir', default='.')
p.add_argument('--samples'); p.add_argument('--lang', default='en')
a = p.parse_args()
if 'OPENAI_API_KEY' not in os.environ: sys.exit('set OPENAI_API_KEY')
voice_dir = os.path.join(a.dir, 'voice')
styles = json.load(open(os.path.join(voice_dir, 'style.json'), encoding='utf-8'))

if a.samples:
    os.makedirs(os.path.join(voice_dir, 'samples'), exist_ok=True)
    for v in a.names:
        speak(a.samples, v, styles[a.lang], os.path.join(voice_dir, 'samples', v + '.mp3')); print(v, 'ok')
    sys.exit()

narration = json.load(open(os.path.join(voice_dir, 'narration.json'), encoding='utf-8'))
for name in a.names:
    out = os.path.join(voice_dir, name); os.makedirs(out, exist_ok=True)
    for old in os.listdir(out):
        if old.endswith('.mp3'): os.remove(os.path.join(out, old))
    durations = {}
    for n, (scene, text) in enumerate(narration[name].items(), 1):
        f = '%02d.mp3' % n
        speak(text, a.voice, styles.get(name[-2:], styles['en']), os.path.join(out, f))
        durations[scene] = {'file': f, 'seconds': seconds(os.path.join(out, f))}
    json.dump(durations, open(os.path.join(out, 'durations.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('%s: %d clips, %d s of speech' % (name, len(durations), sum(d['seconds'] for d in durations.values())))
