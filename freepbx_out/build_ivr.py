import argparse
import asyncio
import csv
import json
import re
import subprocess
import tempfile
import wave
from pathlib import Path

import edge_tts


ROOT = Path(__file__).resolve().parent
SOUND_ROOT = ROOT / "sounds" / "solarooof"
VOICES = {
    "bn": "bn-IN-TanishaaNeural",
    "en": "en-IN-NeerjaNeural",
    "hi": "hi-IN-SwaraNeural",
}
FFMPEG = "ffmpeg"
MUSIC_SOURCE = SOUND_ROOT / "backround-music.mp3"
MUSIC_WAV = SOUND_ROOT / "backround-music.wav"
OPENING_BED = SOUND_ROOT / "opening_bed.wav"

MENU_CONTEXTS = {
    "solarooof-main-menu": ("main_menu", None, {"1": "solarooof-support-menu", "2": "solarooof-service-menu", "3": "solarooof-install-menu", "4": "solarooof-partner-menu", "5": "solarooof-netmeter-menu", "6": "solarooof-subsidy-menu"}),
    "solarooof-support-menu": ("opt1_support_menu", "solarooof-main-menu", {"1": "solarooof-billing", "2": "solarooof-portal", "3": "solarooof-warranty"}),
    "solarooof-service-menu": ("opt2_service_menu", "solarooof-main-menu", {"1": "solarooof-service-existing", "2": "solarooof-service-new-customer", "3": "solarooof-service-maintenance"}),
    "solarooof-service-existing": ("opt2_1_existing_menu", "solarooof-service-menu", {"1": "solarooof-service-emergency", "2": "solarooof-service-netmeter", "3": "solarooof-service-inverter"}),
    "solarooof-service-new-customer": ("opt2_2_new_cust_menu", "solarooof-service-menu", {"1": "solarooof-service-onboarding", "2": "solarooof-service-vendor"}),
    "solarooof-service-maintenance": ("opt2_3_maint_menu", "solarooof-service-menu", {"1": "solarooof-service-cleaning", "2": "solarooof-service-visit-status"}),
    "solarooof-install-menu": ("opt3_install_menu", "solarooof-main-menu", {"1": "solarooof-install-residential", "2": "solarooof-install-commercial", "3": "solarooof-install-survey"}),
    "solarooof-partner-menu": ("opt4_partner_menu", "solarooof-main-menu", {"1": "solarooof-partner-apply", "2": "solarooof-partner-eligibility", "3": "solarooof-partner-status"}),
    "solarooof-netmeter-menu": ("opt5_netmeter_menu", "solarooof-main-menu", {"1": "solarooof-netmeter-pincode", "2": "solarooof-netmeter-process", "3": "solarooof-netmeter-export"}),
    "solarooof-subsidy-menu": ("opt6_subsidy_menu", "solarooof-main-menu", {"1": "solarooof-subsidy-schemes", "2": "solarooof-subsidy-eligibility", "3": "solarooof-subsidy-status"}),
    "solarooof-lang-menu": ("opt7_lang_menu", "LANG_RETURN_CTX", {"1": "bn", "2": "en", "3": "hi"}),
}

LEAF_CONTEXTS = {
    "solarooof-billing": ("opt1_1_billing", "solarooof-support-menu", "billing_queue"),
    "solarooof-portal": ("opt1_2_portal", "solarooof-support-menu", "portal_link_agent"),
    "solarooof-warranty": ("opt1_3_warranty", "solarooof-support-menu", "documentation_team"),
    "solarooof-service-emergency": ("opt2_1_1_emergency_reconn", "solarooof-service-existing", "emergency"),
    "solarooof-service-netmeter": ("opt2_1_2_netmeter_report", "solarooof-service-existing", "netmeter_report_sms_email"),
    "solarooof-service-inverter": ("opt2_1_3_inverter_check", "solarooof-service-existing", "inverter_check_request"),
    "solarooof-service-onboarding": ("opt2_2_1_onboarding", "solarooof-service-new-customer", "onboarding_whatsapp_sms"),
    "solarooof-service-vendor": ("opt2_2_2_vendor_verify", "solarooof-service-new-customer", "vendor_verification"),
    "solarooof-service-cleaning": ("opt2_3_1_cleaning_slot", "solarooof-service-maintenance", "cleaning_slot_request"),
    "solarooof-service-visit-status": ("opt2_3_2_visit_status", "solarooof-service-maintenance", "visit_status_sms"),
    "solarooof-install-residential": ("opt3_1_residential", "solarooof-install-menu", "residential_installation"),
    "solarooof-install-commercial": ("opt3_2_commercial", "solarooof-install-menu", "commercial_installation"),
    "solarooof-install-survey": ("opt3_3_site_survey", "solarooof-install-menu", "site_survey"),
    "solarooof-partner-apply": ("opt4_1_new_apply", "solarooof-partner-menu", "partner_application"),
    "solarooof-partner-eligibility": ("opt4_2_eligibility_docs", "solarooof-partner-menu", "partner_eligibility_docs"),
    "solarooof-partner-status": ("opt4_3_app_status", "solarooof-partner-menu", "partner_application_status"),
    "solarooof-netmeter-pincode": ("opt5_1_pincode_check", "solarooof-netmeter-menu", "netmeter_pincode_check"),
    "solarooof-netmeter-process": ("opt5_2_conn_process", "solarooof-netmeter-menu", "netmeter_connection_process"),
    "solarooof-netmeter-export": ("opt5_3_export_rules", "solarooof-netmeter-menu", "netmeter_export_rules"),
    "solarooof-subsidy-schemes": ("opt6_1_schemes", "solarooof-subsidy-menu", "subsidy_schemes"),
    "solarooof-subsidy-eligibility": ("opt6_2_eligibility", "solarooof-subsidy-menu", "subsidy_eligibility"),
    "solarooof-subsidy-status": ("opt6_3_payout_status", "solarooof-subsidy-menu", "subsidy_payout_status"),
}


def enforce_edge_silence(path, padding_ms=150):
    with wave.open(str(path), "rb") as source:
        params = source.getparams()
        if (params.framerate, params.nchannels, params.sampwidth) != (8000, 1, 2):
            raise ValueError(f"Unexpected WAV format for edge padding: {path}")
        frame_count = params.nframes
        audio = bytearray(source.readframes(frame_count))
    padding_bytes = round(params.framerate * padding_ms / 1000) * params.nchannels * params.sampwidth
    if len(audio) < padding_bytes * 2:
        raise ValueError(f"WAV is too short for edge padding: {path}")
    audio[:padding_bytes] = b"\x00" * padding_bytes
    audio[-padding_bytes:] = b"\x00" * padding_bytes
    temp_path = path.with_name(path.stem + ".padding.wav")
    with wave.open(str(temp_path), "wb") as target:
        target.setparams(params)
        target.writeframes(audio)
    temp_path.replace(path)


def menu_map():
    return {
        "brand": "Tata Power Solarooof",
        "default_language": "en",
        "languages": {"1": "bn", "2": "en", "3": "hi"},
        "navigation": {"0": "previous menu (main menu repeats)", "8": "main menu", "7": "language menu"},
        "opening": {"audio": "opening_bed", "duration_seconds": 8, "then": "fraud_warning"},
        "max_timeouts": 2,
        "max_invalid_inputs": 3,
        "menu_timeout_seconds": 15,
        "contexts": {
            "main_menu": {
                "context": "solarooof-main-menu",
                "audio": "main_menu",
                "options": {
                    "1": "opt1_support_menu",
                    "2": "opt2_service_menu",
                    "3": "opt3_install_menu",
                    "4": "opt4_partner_menu",
                    "5": "opt5_netmeter_menu",
                    "6": "opt6_subsidy_menu",
                    "7": "opt7_lang_menu",
                },
            },
            "menus": {
                context: {"audio": prompt, "previous": previous, "options": routes}
                for context, (prompt, previous, routes) in MENU_CONTEXTS.items()
                if context != "solarooof-main-menu"
            },
            "leaves": {
                context: {"audio": prompt, "previous": previous, "action_hook": action}
                for context, (prompt, previous, action) in LEAF_CONTEXTS.items()
            },
        },
        "action_hook_note": "UserEvent actions require an AMI/event consumer or dialplan integration to perform queue, SMS, email, ticket, and lookup work.",
    }


def _common_error_lines():
    return [
        "exten => i,1,Set(INVALIDS=$[${INVALIDS}+1])",
        " same => n,Playback(solarooof/${IVR_LANG}/invalid_key)",
        " same => n,GotoIf($[${INVALIDS}>=3]?invalid_exceeded:invalid_retry)",
        " same => n(invalid_exceeded),Playback(solarooof/${IVR_LANG}/max_attempts)",
        " same => n,Hangup()",
        " same => n(invalid_retry),Goto(s,menu)",
        "exten => t,1,Set(TIMEOUTS=$[${TIMEOUTS}+1])",
        " same => n,Playback(solarooof/${IVR_LANG}/timeout_msg)",
        " same => n,GotoIf($[${TIMEOUTS}>=2]?timeout_hang:timeout_retry)",
        " same => n(timeout_hang),Hangup()",
        " same => n(timeout_retry),Goto(s,menu)",
    ]


def make_dialplan():
    out = [
        "; Generated by build_ivr.py. Include from extensions_custom.conf.",
        "; Configure the UserEvent action hooks for queues, tickets, SMS, email, and lookups.",
        "",
    ]
    for context, (prompt, previous, routes) in MENU_CONTEXTS.items():
        out.append(f"[{context}]")
        if context == "solarooof-main-menu":
            out.append("exten => s,1,Answer()")
            out.append(' same => n,Set(IVR_LANG=${IF($["${IVR_LANG}"=""]?en:${IVR_LANG})})')
            out.append(" same => n,Set(CHANNEL(language)=${IVR_LANG})")
            out.append(" same => n,Set(TIMEOUTS=0)")
            out.append(" same => n,Set(INVALIDS=0)")
            out.append(' same => n,GotoIf($["${INTRO_PLAYED}"="yes"]?menu)')
            out.append(" same => n,Set(INTRO_PLAYED=yes)")
            out.append(" same => n,Playback(solarooof/opening_bed)")
            out.append(" same => n,Playback(solarooof/${IVR_LANG}/fraud_warning)")
            out.append(" same => n(menu),Background(solarooof/${IVR_LANG}/main_menu)")
        elif context == "solarooof-lang-menu":
            out.append("exten => s,1,Set(PREV_CTX=${LANG_RETURN_CTX})")
            out.append(" same => n,Set(TIMEOUTS=0)")
            out.append(" same => n,Set(INVALIDS=0)")
            out.append(f" same => n(menu),Background(solarooof/${{IVR_LANG}}/{prompt})")
        else:
            out.append("exten => s,1,Set(PREV_CTX=" + previous + ")")
            out.append(" same => n,Set(TIMEOUTS=0)")
            out.append(" same => n,Set(INVALIDS=0)")
            out.append(f" same => n(menu),Background(solarooof/${{IVR_LANG}}/{prompt})")
        out.append(" same => n,WaitExten(15)")
        for key, target in routes.items():
            if context == "solarooof-lang-menu":
                out += [
                    f"exten => {key},1,Set(LANG_CHOICE={target})",
                    " same => n,Goto(solarooof-set-language,${LANG_CHOICE},1)",
                ]
            else:
                out.append(f"exten => {key},1,Goto({target},s,1)")
        if context == "solarooof-main-menu":
            out.append("exten => 0,1,Goto(s,menu)")
            out.append("exten => 8,1,Goto(s,menu)")
        elif context == "solarooof-lang-menu":
            out.append("exten => 0,1,Goto(${LANG_RETURN_CTX},s,1)")
            out.append("exten => 8,1,Goto(solarooof-main-menu,s,1)")
        else:
            out.append("exten => 0,1,Goto(" + previous + ",s,1)")
            out.append("exten => 8,1,Goto(solarooof-main-menu,s,1)")
        if context == "solarooof-lang-menu":
            out += ["exten => 7,1,Goto(s,menu)"]
        else:
            out += [
                "exten => 7,1,Set(LANG_RETURN_CTX=" + context + ")",
                " same => n,Goto(solarooof-lang-menu,s,1)",
            ]
        out += _common_error_lines()
        out.append("")

    for context, (prompt, previous, action) in LEAF_CONTEXTS.items():
        out += [
            f"[{context}]",
            f"exten => s,1,Set(PREV_CTX={previous})",
            " same => n,Set(TIMEOUTS=0)",
            " same => n,Set(INVALIDS=0)",
        ]
        if action == "emergency":
            out += [
                " same => n,Set(SAFE_CALLERID=${FILTER(0-9,${CALLERID(num)})})",
                ' same => n,GotoIf($["${SAFE_CALLERID}"=""]?ticket_failed)',
                " same => n,System(/usr/local/bin/create_ticket.sh ${SAFE_CALLERID} emergency)",
                ' same => n,GotoIf($["${SYSTEMSTATUS}"="SUCCESS"]?menu:ticket_failed)',
                " same => n(ticket_failed),Log(WARNING,Solarooof emergency ticket creation failed; caller ID was missing or the ticket script returned ${SYSTEMSTATUS})",
                " same => n,Playback(solarooof/${IVR_LANG}/action_unavailable)",
                f" same => n,Goto({previous},s,1)",
            ]
        else:
            out.append(f' same => n,UserEvent(SolarooofAction,action: {action}\\ncallerid: ${{CALLERID(num)}}\\nlanguage: ${{IVR_LANG}})')
        out += [
            f" same => n(menu),Playback(solarooof/${{IVR_LANG}}/{prompt})",
            " same => n,WaitExten(15)",
            "exten => 0,1,Goto(" + previous + ",s,1)",
            "exten => 8,1,Goto(solarooof-main-menu,s,1)",
            f"exten => 7,1,Set(LANG_RETURN_CTX={context})",
            " same => n,Goto(solarooof-lang-menu,s,1)",
        ]
        out += _common_error_lines()
        out.append("")

    out += [
        "[solarooof-set-language]",
        "exten => bn,1,Set(IVR_LANG=bn)",
        " same => n,Set(CHANNEL(language)=bn)",
        " same => n,Playback(solarooof/${IVR_LANG}/lang_set_bn)",
        " same => n,Goto(solarooof-main-menu,s,1)",
        "exten => en,1,Set(IVR_LANG=en)",
        " same => n,Set(CHANNEL(language)=en)",
        " same => n,Playback(solarooof/${IVR_LANG}/lang_set_en)",
        " same => n,Goto(solarooof-main-menu,s,1)",
        "exten => hi,1,Set(IVR_LANG=hi)",
        " same => n,Set(CHANNEL(language)=hi)",
        " same => n,Playback(solarooof/${IVR_LANG}/lang_set_hi)",
        " same => n,Goto(solarooof-main-menu,s,1)",
        "",
    ]
    return "\n".join(out) + "\n"


async def render_clip(text, voice, output, force=False):
    output.parent.mkdir(parents=True, exist_ok=True)
    if output.exists() and not force:
        return
    if not MUSIC_WAV.is_file():
        raise FileNotFoundError(f"Background music file not found: {MUSIC_WAV}")
    with tempfile.TemporaryDirectory() as temp_dir:
        source = Path(temp_dir) / "source.mp3"
        last_error = None
        for attempt in range(3):
            try:
                await edge_tts.Communicate(text, voice, rate="-5%").save(str(source))
                last_error = None
                break
            except Exception as error:
                last_error = error
                if attempt < 2:
                    await asyncio.sleep(2 * (attempt + 1))
        if last_error:
            raise RuntimeError(f"TTS failed for {output.name}: {last_error}") from last_error
        duration_result = subprocess.run(
            [
                "ffprobe", "-v", "error", "-show_entries", "format=duration",
                "-of", "default=noprint_wrappers=1:nokey=1", str(source),
            ],
            check=True,
            capture_output=True,
            text=True,
        )
        voice_duration = float(duration_result.stdout.strip())
        subprocess.run(
            [
                FFMPEG, "-hide_banner", "-loglevel", "error", "-y",
                "-stream_loop", "-1", "-i", str(MUSIC_WAV),
                "-i", str(source),
                "-filter_complex",
                f"[1:a]adelay=150:all=1,asplit=2[voice][side];"
                f"[0:a]atrim=duration={voice_duration:.3f},adelay=150:all=1,volume=0.18[bed];"
                "[bed][side]sidechaincompress=threshold=0.02:ratio=8:attack=15:release=350[ducked];"
                "[voice][ducked]amix=inputs=2:duration=first:dropout_transition=0:normalize=0,"
                "apad=pad_dur=0.15,"
                "loudnorm=I=-23:TP=-1.5:LRA=7:linear=true,"
                "aresample=8000,aformat=sample_fmts=s16:channel_layouts=mono[out]",
                "-map", "[out]", "-ar", "8000", "-ac", "1", "-c:a", "pcm_s16le",
                str(output),
            ],
            check=True,
        )
        enforce_edge_silence(output)


def prepare_music_assets(force=False):
    if not MUSIC_SOURCE.is_file():
        raise FileNotFoundError(f"Required background music file not found: {MUSIC_SOURCE}")
    if force or not MUSIC_WAV.exists():
        subprocess.run(
            [
                FFMPEG, "-hide_banner", "-loglevel", "error", "-y",
                "-i", str(MUSIC_SOURCE), "-vn",
                "-af", "loudnorm=I=-23:TP=-1.5:LRA=7:linear=true",
                "-ar", "8000", "-ac", "1", "-c:a", "pcm_s16le",
                str(MUSIC_WAV),
            ],
            check=True,
        )
    if force or not OPENING_BED.exists():
        subprocess.run(
            [
                FFMPEG, "-hide_banner", "-loglevel", "error", "-y",
                "-i", str(MUSIC_WAV), "-t", "8",
                "-af", "afade=t=in:st=0:d=0.4,afade=t=out:st=7.2:d=0.8,loudnorm=I=-28:TP=-2:LRA=7:linear=true",
                "-ar", "8000", "-ac", "1", "-c:a", "pcm_s16le",
                str(OPENING_BED),
            ],
            check=True,
        )


async def generate_audio(prompts, force=False):
    semaphore = asyncio.Semaphore(3)

    async def limited(text, voice, target):
        async with semaphore:
            await render_clip(text, voice, target, force=force)

    tasks = [
        limited(texts[language], VOICES[language], SOUND_ROOT / language / f"{name}.wav")
        for name, texts in prompts.items()
        for language in VOICES
    ]
    await asyncio.gather(*tasks)


def load_prompts(csv_path):
    prompts = {}
    with csv_path.open("r", encoding="utf-8-sig", newline="") as source:
        reader = csv.DictReader(source)
        if reader.fieldnames != ["filename", "language", "text"]:
            raise ValueError("prompts.csv must have columns: filename,language,text")
        for line_number, row in enumerate(reader, start=2):
            name = row["filename"].strip()
            language = row["language"].strip().lower()
            text = row["text"].strip()
            if not name or not text:
                raise ValueError(f"prompts.csv line {line_number} has an empty filename or text")
            if not re.fullmatch(r"[a-z0-9][a-z0-9_]*", name):
                raise ValueError(f"prompts.csv line {line_number} has an unsafe filename {name!r}")
            if language not in VOICES:
                raise ValueError(f"prompts.csv line {line_number} has unsupported language {language!r}")
            if name in prompts and language in prompts[name]:
                raise ValueError(f"prompts.csv line {line_number} duplicates {name}/{language}")
            prompts.setdefault(name, {})[language] = text
    if not prompts:
        raise ValueError("prompts.csv contains no prompt rows")
    for name, translations in prompts.items():
        missing = set(VOICES) - set(translations)
        if missing:
            raise ValueError(f"Prompt {name!r} is missing language rows: {', '.join(sorted(missing))}")
    return prompts


def main():
    parser = argparse.ArgumentParser(description="Build Solarooof FreePBX menus and prompts.")
    parser.add_argument("--no-audio", action="store_true", help="Write scripts and dialplan without generating WAV files.")
    parser.add_argument("--force", action="store_true", help="Regenerate all WAV files, including the music mixes.")
    parser.add_argument("--repair-padding", action="store_true", help="Zero the 150 ms edge padding in existing prompt WAVs.")
    args = parser.parse_args()
    ROOT.mkdir(parents=True, exist_ok=True)
    prompts = load_prompts(ROOT / "prompts.csv")
    (ROOT / "prompts.json").write_text(
        json.dumps(prompts, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    (ROOT / "menu_map.json").write_text(
        json.dumps(menu_map(), ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )
    (ROOT / "extensions_custom.conf").write_text(make_dialplan(), encoding="utf-8")
    if args.repair_padding:
        for name in prompts:
            for language in VOICES:
                path = SOUND_ROOT / language / f"{name}.wav"
                if not path.is_file():
                    raise FileNotFoundError(f"Prompt WAV not found: {path}")
                enforce_edge_silence(path)
        print(f"Repaired edge padding in {len(prompts) * len(VOICES)} prompt WAV files")
        return
    if not args.no_audio:
        prepare_music_assets(force=args.force)
        asyncio.run(generate_audio(prompts, force=args.force))
    print(f"Prompt definitions: {len(prompts)} x {len(VOICES)} languages = {len(prompts) * len(VOICES)} WAV files")


if __name__ == "__main__":
    main()
