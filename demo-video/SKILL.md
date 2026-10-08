---
name: demo-video
description: Make a narrated demo video of a web application — a real browser walks through the app while a text-to-speech narrator explains it, with title cards and quiet background music. Use this whenever the user asks for a demo video, product walkthrough, screen recording, "snimi video", "demo snimak", a video with voice-over or narration, or wants voice or music added to a recording of their app, even if they only say "record how it works". Covers the script structure (say what it is, say what you will show, show it, recap), pacing scenes to the voice, mobile and desktop viewports, several languages, and checking audio you cannot hear.
---

# Narrated demo video of a web app

A demo video here is a Playwright recording of the real application, a voice clip per scene, and a music bed, joined
with ffmpeg. The scripts in `scripts/` do the mechanical parts; your work is the story, the scenario and the checks.

Needs: Node with Playwright (Chromium), ffmpeg/ffprobe, Python 3 with numpy, and an `OPENAI_API_KEY` for speech.
Take the key from the environment or the project's own config. Never print it, and never write it into a script.

## 1. Write the story before any code

A good presentation: **tell them what you are going to tell them, tell them, tell them what you told them.** A viewer
who lands on a login screen with no context does not know what they are looking at, so every video opens and closes
with title cards:

1. **About** — what this is, what it does, who it is for. One card, three bullet points, about fifteen seconds.
2. **In this video** — the three things they are about to see, numbered.
3. **The scenes** — grouped so they match those three things.
4. **Recap** — the same three points under "You have seen", then a thank-you.

Keep to three points. If the product needs more, make more videos (one per audience works well: the back-office for
the person who runs it, the app for the person who uses it).

Write the narration as `voice/narration.json`: one set per video, one entry per scene, in order. The set name ends in
the language code (`panel-en`, `client-sr`), which selects the narrator style. See `examples/narration.json`.

Writing for a synthetic voice:
- One or two sentences per scene. The scene lasts as long as its clip, so long text makes a slow video.
- Say what the viewer gains, not what the cursor does ("Clients book appointments themselves", not "now I click Appointments").
- Spell out anything the voice may misread, in the target language's own spelling: abbreviations ("QR" → "kju-ar" in
  Serbian), brand names ("WhatsApp" → "Votsap"), numbers in words. Check the result with `stt.py` (step 5).
- Any question typed into the product on screen (a chat, a search) should fit the story. Do not demonstrate a problem
  the product is supposed to have prevented.

## 2. Choose the voice with the user

You cannot hear the result, and voice is taste. Generate one sentence in several voices and let the user pick:

```bash
python3 scripts/tts.py --samples "Dobro došli. Ovo je kratak prikaz aplikacije." --lang sr onyx ash echo cedar ballad verse
```

The narrator's manner is in `voice/style.json` (copy `examples/style.json`): describe the voice, tone, pace and how
sentences end, in the language being spoken. This matters more than the voice name. Then generate the clips:

```bash
python3 scripts/tts.py panel-sr client-sr client-en --voice onyx
```

This writes `voice/<set>/NN.mp3` and `durations.json`. Regenerate whenever the text changes, before recording.

## 3. Write the scenario

Copy `examples/scenario.js`. `scripts/demo.js` gives you `step`, `titleCard`, `show`, `scrollBy`, `type`,
`stampCanvas` and `finish`.

`await demo.step('scene name')` is the heart of it. It waits until the previous scene's clip has finished, then logs
the cue time for this scene's clip. So speech never overlaps and no timing is tuned by hand. Rules that follow:

- Call `step('name')` once the scene is on screen (after navigation and `waitForSelector`), with the name used in
  `narration.json`. A name with no narration (`'(sign in)'`) simply waits for the previous clip — use it before
  navigating away, so the picture does not change while the narrator is still talking about the last one.
- Put the scene's actions (scrolling, ticking, typing) after `step`, so they happen under the voice.
- If a scene may not exist in some data set, skip the `step` call as well, or its clip plays over the wrong picture.

Viewports: record the back-office at computer size (1280×800), and anything the end user holds in their hand with
`mobile: true` (412×892). Do not show an admin panel in a phone-sized window just because the rest is mobile.

Make the recording repeatable:
- Reset whatever the scenario changes before every take (new sign-ups, messages, today's entries), or the second take
  fails or shows leftovers. Keep that reset next to the recording command.
- Select by stable ids and names, not by position or by today's date.
- Use made-up people and data. Stamp anything a viewer could act on, such as a payment QR code built from a fake
  account (`stampCanvas`).
- If the scenario waits for something slow (an AI reply), wait for the element, not for a fixed time.

Languages: the narration, the title cards and the application on screen should be in the same language. If the app
has no translation for part of it, say so to the user instead of quietly shipping English voice over foreign screens.

## 4. Record, then mix

```bash
node scenario.js                 # -> raw/<name>.webm and raw/<name>.cues
python3 scripts/music.py         # once: voice/music.mp3, a plain original bed with no licensing question
scripts/mix.sh <name>            # -> <name>.mp4   (MUSIC=0.3 for quieter music, MUSIC=0 for none)
```

Recording and mixing are separate on purpose. A recording takes minutes; a mix takes seconds. A new music track or
level needs only `mix.sh`. A new voice or new text changes clip lengths, so it needs a new recording.

If the user has a track they are allowed to use, put it at `voice/music.mp3`. Do not download music.

## 5. Check what you can, and say what you cannot

You cannot listen to the video. Do these instead, and report them as what they are:

```bash
scripts/check.sh <name>.mp4                  # streams, speech-vs-music level, contact sheet
python3 scripts/stt.py <name>.mp4 sr         # narration transcribed back to text
```

- Read the contact sheet image: the first frames are the title cards at a readable size, the last is the recap, and
  no scene is an error page or a blank screen.
- Compare the transcript with `narration.json`. Words that come back wrong were probably mispronounced: respell them
  and regenerate.
- The music level between sentences should sit well below speech (20 dB or more).

Tell the user plainly that accent, warmth and whether the music suits are theirs to judge, and where the text and
samples are so they can ask for changes.

## Things that went wrong before

- **Title card tiny on a phone recording.** `page.setContent` on a mobile context renders at desktop width unless the
  page has a viewport meta tag. `titleCard` includes it; keep it if you write your own card.
- **`sidechaincompress` "could not choose their formats".** Both inputs need an explicit `aformat`. `mix.sh` does this.
- **Video duration unknown.** Playwright's webm has no duration in its header; `mix.sh` measures it by decoding.
- **A failed mix destroyed the take.** Never delete the raw recording as part of the mix step.
- **Narration cut off at the end.** `finish()` waits for the last clip; do not close the context yourself.
- **Voice over the wrong screen.** A scene was skipped but its `step` still ran, or `step` was called before the page
  had loaded.
