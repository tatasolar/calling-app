Tata Power Solarooof IVR package
================================

Files
-----
sounds/solarooof/{bn,en,hi}/  41 WAV prompts per language
sounds/solarooof/opening_bed.wav
sounds/solarooof/backround-music.wav
extensions_custom.conf       Generated dialplan contexts (included by installer)
menu_map.json                Structured routing and navigation map
prompts.csv                  Editable source: filename, language, text
prompts.json                 Generated JSON copy of the spoken text
build_ivr.py                 Rebuilds the maps, dialplan, and WAV files
install_solarooof.sh         Linux installer for sounds and dialplan include

WAV format
----------
All spoken prompts are 8,000 Hz, mono, signed 16-bit PCM, normalized to
-23 LUFS / -1.5 dBTP, with approximately 150 ms of silence at both ends.
Speech clips have the supplied music mixed quietly underneath and ducked
during speech. The eight-second opening bed is music-only and fades at both
ends. The supplied MP3 is also converted to an 8 kHz mono PCM WAV for Asterisk.

Dialplan integration
--------------------
The installer copies the dialplan to
/etc/asterisk/extensions_custom_solarooof.conf, adds an idempotent include to
the existing extensions_custom.conf (backing it up first), and reloads the
dialplan. It installs sounds under /var/lib/asterisk/sounds/solarooof and
sets ownership to asterisk:asterisk.

The entry context is solarooof-main-menu. Send calls to that context from the
FreePBX inbound route. The call is answered, plays eight seconds of music,
then the localized fraud warning and main menu. IVR_LANG defaults to English;
the language menu sets IVR_LANG and CHANNEL(language). Menu input waits 15
seconds; two consecutive no-input timeouts play the timeout prompt twice and
hang up. Three invalid selections play the invalid prompt each time, then the
maximum-attempts notice and hang up.

Run the installer as root on the Asterisk host after transferring this folder:

    sudo bash install_solarooof.sh

Business integrations
---------------------
Non-emergency leaves emit a SolarooofAction AMI UserEvent with action,
callerid, and language fields. A listener or a replacement dialplan action
must be configured to connect these events to the actual queues, ticketing,
SMS, email, and lookup services. Queue names, APIs, and service credentials
were not supplied, so the dialplan does not invent live destinations.

The emergency leaf follows the supplied sample and invokes
/usr/local/bin/create_ticket.sh "<callerid>" emergency. Install and test that
script on the Asterisk host before enabling this route.

Build
-----
On a connected Windows build machine, install Python package edge-tts and
ensure ffmpeg is on PATH, then run:

    py -m pip install edge-tts
    cd E:\ivr_project\freepbx_out
    python build_ivr.py --force

`--force` rebuilds all audio and music mixes. Without it, existing prompt
files are retained. Edit prompts.csv to change prompt wording; every filename
must have one row for each of bn, en, and hi. The Bengali voice is
bn-IN-TanishaaNeural (Indian Bengali), not a Bangladesh Bengali voice.
Translation wording is a natural localized draft; have native-language
reviewers approve it before production use. Building sends the CSV prompt text
to the configured online Edge speech endpoint; do not put credentials or
customer data in this prompt file.

Check the output on Windows
---------------------------
Count the generated clips:

    Get-ChildItem .\sounds\solarooof -Recurse -Filter *.wav | Measure-Object

Inspect a WAV's codec, sample rate, channel count, and bit depth:

    ffprobe -v error -show_entries stream=codec_name,sample_rate,channels,bits_per_sample -of default=noprint_wrappers=1 .\sounds\solarooof\en\main_menu.wav

The expected stream is pcm_s16le, 8000 Hz, mono, and 16 bits. Listen to the
clips, especially digit sequences, product-name pronunciation, and Bengali
technical terms, before deployment.

TTS choices
-----------
1. Microsoft Azure Speech: paid, supported service with neural voices and
   SSML controls; better suited to production SLAs, but needs an Azure resource
   and credentials supplied outside source control.
2. edge-tts (used by this builder): free and simple, with female Bengali,
   Indian English, and Hindi neural voices and no key in the script. It uses
   an unofficial Edge speech endpoint, so availability, licensing, and
   commercial-use suitability should be confirmed before production.

The selected neural voices are female (bn-IN-TanishaaNeural,
en-IN-NeerjaNeural, hi-IN-SwaraNeural) and use a measured speaking rate.
Voice age and warmth are subjective; listen to samples and get native-speaker
approval before deploying the prompts to customers.

USB dongle / WebRTC boundary
----------------------------
The supplied screenshot identifies an iBall 3.5GH-72 modem in its Windows
connection utility. A Windows COM port and a successful call from that
utility do not by themselves provide a SIP trunk or WebRTC/WSS endpoint.
Cellular voice must first be bridged to the PBX by a compatible GSM-to-SIP
gateway or a verified host-side telephony driver/service. This audio package
does not install that driver, configure the dongle, or deploy the PBX.
GitHub Pages is static hosting; it cannot run this project's PHP backend.
