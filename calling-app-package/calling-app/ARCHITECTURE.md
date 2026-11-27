# Telephony architecture review

## What is present in this workspace

The application contains a PHP/MySQL agent portal and a browser SIP client using JsSIP. This app folder has no Asterisk or FreePBX configuration, SIP trunk definitions, queue/dialplan rules, server reverse-proxy settings, or deployment logs. The separate IVR/audio package is in `../../freepbx_out`. This source review cannot verify the cellular gateway, tunnel, PBXs, firewall, or live calls.

The web application is server-side PHP. It is not a static website: `agent-console.php`, `api/*.php`, PHP sessions, and MySQL are required. Opening files with `file://`, using a static VS Code preview, or publishing the project to GitHub Pages cannot execute PHP or authenticate users. Run and deploy the application on a PHP-enabled web server over HTTPS. Keep the web app and API on the same origin so the session cookie works. Never expose PHP source as text.

On a PHP-enabled server, a request to `agent-console.php` without a valid session redirects to `index.html`; after signup/login, the app sends the authenticated browser to the protected PHP route. A 404 or a page displaying `<?php ...` is a PHP hosting/handler problem, not an expected unauthenticated response. On Nginx, `.htaccess` is ignored; configure PHP-FPM explicitly as shown in `nginx.conf.example`. The `agent-console.html` link is a compatibility gate: it checks `api/auth.php?action=me`, redirects unauthenticated users to sign-in, and gives a setup message when PHP is not running.

The supplied screenshot identifies an iBall 3.5GH-72 USB modem in its Windows connection utility. The displayed COM port and a successful call placed in that utility confirm the modem is connected to its Windows software; they do not establish that it exposes a SIP trunk, an Asterisk channel driver, or a WSS endpoint. The cellular voice path needs a compatible GSM-to-SIP gateway or a host-side telephony driver/service verified to expose calls to Asterisk. The browser's WebRTC client connects to the PBX over WSS; it cannot directly use a USB COM port. This workspace has no modem driver, GSM gateway, PBX installation, or device-specific configuration, so the iBall path still needs verification on the host before live calls can be routed.

## Recommended call path

```text
Cellular SIM / voice-capable GSM device
       │ compatible GSM-to-SIP gateway or verified host telephony driver
       ▼
Local or VPS Asterisk/PBX ───── PJSIP trunk ───── VPS WebRTC edge
       GSM gateway/driver                           ├─ HTTPS app/API (PHP + MySQL)
                                                    └─ WSS/WebRTC ── Agent browsers
```

1. Verify the cellular device/gateway exposes voice calls—not only a modem COM port or its manufacturer's dialer—to a supported telephony host. Test inbound and outbound calls through that interface before involving the web app.
2. Route cellular calls from the gateway-side Asterisk/PBX over a restricted PJSIP trunk to the PBX/SIP edge. If the PBXs are on separate private networks, secure that trunk over a private tunnel; do not expose PBX management or unrestricted SIP to the Internet.
3. The VPS PBX hosts the browser-facing PJSIP WebRTC endpoints and the requested sequential inbound ring group for the 2–3 agents. Browsers connect to the VPS's public TLS WebSocket endpoint. The web application is not itself a PBX and does not implement queue/ring logic.
4. Anchor browser media at the VPS PBX. Permit the configured public WebRTC RTP/ICE range at the VPS firewall and RTP for the PBX-to-PBX trunk only through WireGuard. Configure STUN and, where clients' NAT requires it, a TURN service. Do not send browser media directly to a private WireGuard address unless each agent device is intentionally a WireGuard peer.
5. Test failover, simultaneous inbound calls, busy agents, and which party releases the cellular call. Validate audio in both directions and DTMF. Use an Asterisk-side recording feature (for example `MixMonitor`) if recordings must survive closed tabs, browser failures, or agent tampering. Browser recording in this app is best-effort client-side recording, not an authoritative PBX recording.

An alternative topology can place the only PBX locally, but then the VPS must securely proxy WSS to that PBX and there must be a working media route between every browser and the PBX. Merely forwarding SIP signaling over WireGuard does not make browser RTP reachable.

## Application configuration and behavior

Set `SIP_WSS_URL` to the reachable **WSS** URL for the WebRTC PBX and `SIP_DOMAIN` to its actual SIP domain in the server-only `.env`. The authenticated PHP console injects these values into the page; the browser now reports an actionable error instead of silently attempting a hard-coded domain. Configure a TLS certificate and a reverse proxy/PBX WebSocket listener for that exact path.

The app only issues a SIP `REGISTER` when an agent explicitly connects from the console, using the extension and SIP secret entered there. Account passwords and SIP secrets are separate credentials. Create each agent endpoint and assign one unique extension/password on the PBX. The account signup extension selector does not create, provision, or validate a PBX endpoint.

Incoming SIP calls ring only if the PBX dialplan/queue calls registered agent endpoints. This UI displays an incoming call and the agent must answer it. The browser recorder starts after answer and after audio tracks become available; it cannot start on an unanswered ringing call. The app does not set up an inbound cellular route or a multi-agent queue.

## Operational checks before production

- Confirm the server runs PHP (not a static hosting service) and can reach MySQL; import `database.sql`, install the production `.env` outside public source control, and test unauthenticated console redirect plus authenticated sign-in.
- From a browser on the public Internet, confirm the configured WSS path upgrades successfully, each SIP endpoint registers, and `OPTIONS`/ICE connectivity produces two-way audio.
- From the local Asterisk host, confirm the WireGuard peer is up, the VPS private tunnel address is reachable, and the SIP trunk can call in both directions.
- Place a call through the verified cellular gateway to the configured inbound number. Confirm Asterisk receives it, the VPS queue rings all eligible browsers, answering one stops the others, audio works both ways, and hangup releases both the cellular and SIP legs.
- Repeat with two or three agents, a disconnected agent, busy/no-answer conditions, a dropped tunnel, and browser refresh. Check PBX logs and packet captures on the tunnel instead of exposing SIP/RTP to the public Internet.
- Agree a customer recording notice/consent process and retention/access policy. Current browser recordings are uploaded as files and only their metadata is listed in the console.

If a private tunnel is used, choose its addresses only after the actual VPS and gateway route tables have been checked for overlap. Generate tunnel keys on the hosts and keep them out of source control. The iBall modem is shown connected to its Windows utility; verify whether the iBall 3.5GH-72 exposes cellular voice to a supported host-side driver or GSM-to-SIP gateway before selecting the PBX integration. A successful call in the vendor utility does not confirm that a compatible Asterisk channel or SIP interface is available.
