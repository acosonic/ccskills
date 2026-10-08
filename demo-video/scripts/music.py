#!/usr/bin/env python3
"""A plain, original background bed (soft pad chords, light arpeggio, quiet bass), so there is no licensing question.
  music.py [out.mp3]     needs numpy and ffmpeg. Replace the file with any track the user is allowed to use.
"""
import numpy as np, subprocess, sys, wave, os, tempfile
out = sys.argv[1] if len(sys.argv) > 1 else 'voice/music.mp3'
sr, bpm = 44100, 84; beat = 60 / bpm; bar = 4 * beat
f = lambda n: 440 * 2 ** ((n - 69) / 12)
chords = [[60, 64, 67, 71], [57, 60, 64, 67], [53, 57, 60, 64], [55, 59, 62, 64]]  # Cmaj7 Am7 Fmaj7 G6
bars = len(chords) * 2 * 3; total = int(bars * bar * sr)
L = np.zeros(total); R = np.zeros(total)
def add(buf, start, sig, gain):
    s = int(start * sr); e = min(total, s + len(sig)); buf[s:e] += sig[:e - s] * gain
for i in range(bars):
    ch = chords[(i // 2) % 4]; t0 = i * bar
    t = np.arange(int(bar * sr * 1.15)) / sr
    env = np.minimum(1, t / 0.9) * np.minimum(1, (t[-1] - t) / 0.9)
    for note in ch:
        for det, buf in ((-0.6, L), (0.6, R)):
            add(buf, t0, (np.sin(2 * np.pi * (f(note) + det) * t) + 0.25 * np.sin(4 * np.pi * (f(note) + det) * t)) * env, 0.045)
    bt = np.arange(int(bar * sr)) / sr
    bass = np.sin(2 * np.pi * f(ch[0] - 24) * bt) * np.minimum(1, bt / 0.05) * np.exp(-bt / 1.6)
    add(L, t0, bass, 0.16); add(R, t0, bass, 0.16)
    for k, idx in enumerate([0, 2, 1, 3, 2, 1, 3, 2]):
        pt = np.arange(int(0.9 * sr)) / sr; note = ch[idx] + 12
        pl = (np.sin(2 * np.pi * f(note) * pt) + 0.3 * np.sin(6 * np.pi * f(note) * pt) * np.exp(-pt / 0.12)) * np.exp(-pt / 0.28) * np.minimum(1, pt / 0.004)
        add(L if k % 2 else R, t0 + k * beat / 2, pl, 0.085); add(R if k % 2 else L, t0 + k * beat / 2, pl, 0.04)
mix = np.stack([L, R], 1); mix = mix / np.abs(mix).max() * 0.8
with tempfile.TemporaryDirectory() as d:
    raw = os.path.join(d, 'm.wav')
    w = wave.open(raw, 'wb'); w.setnchannels(2); w.setsampwidth(2); w.setframerate(sr); w.writeframes((mix * 32767).astype('<i2').tobytes()); w.close()
    os.makedirs(os.path.dirname(out) or '.', exist_ok=True)
    subprocess.check_call(['ffmpeg', '-y', '-loglevel', 'error', '-i', raw, '-af', 'aecho=0.8:0.7:90|180:0.25|0.18,lowpass=f=5200,highpass=f=60', '-b:a', '160k', out])
print(out, round(total / sr, 1), 's')
