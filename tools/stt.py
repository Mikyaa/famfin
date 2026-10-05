# Speech to text for voice messages (offline, Vosk). Prints the recognised text.
# Usage: venv/bin/python stt.py voice.ogg /path/to/vosk-model-small-ru-0.22
import json
import subprocess
import sys

import imageio_ffmpeg
from vosk import KaldiRecognizer, Model, SetLogLevel

SetLogLevel(-1)
src, model_dir = sys.argv[1], sys.argv[2]
# Telegram voice is OGG/Opus: decode to 16 kHz mono PCM with the bundled ffmpeg
pcm = subprocess.run(
    [imageio_ffmpeg.get_ffmpeg_exe(), "-loglevel", "quiet", "-i", src, "-ar", "16000", "-ac", "1", "-f", "s16le", "-"],
    capture_output=True, timeout=90, check=False,
).stdout
rec = KaldiRecognizer(Model(model_dir), 16000)
for i in range(0, len(pcm), 8000):
    rec.AcceptWaveform(pcm[i:i + 8000])
print(json.loads(rec.FinalResult()).get("text", "").strip())
