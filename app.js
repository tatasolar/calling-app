const API_BASE = "https://tatapowersolar.shop/api";
const WSS_URL = window.AGENT_CONFIG?.sipWssUrl || "";
const SIP_DOMAIN = window.AGENT_CONFIG?.sipDomain || "";

let ua = null;
let activeSession = null;
let mediaRecorder = null;
let recordedChunks = [];
let callStartTime = 0;
let callTypeForRecording = "direct";
let recordingUploadPromise = Promise.resolve();
let activeCallTarget = "";
let recordingAudioContext = null;
let recordingStarting = false;

function showBanner(elId, text, isError = false) {
  const el = document.getElementById(elId);
  if (!el) return;
  el.textContent = text;
  el.style.borderLeftColor = isError ? "#b91c1c" : "#ea580c";
}

async function apiRequest(url, options = {}) {
  const res = await fetch(url, { credentials: "include", ...options });
  let data;
  try {
    data = await res.json();
  } catch (_) {
    throw new Error(`The server returned an invalid response (${res.status}).`);
  }
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `Request failed (${res.status}).`);
  }
  return data;
}

function triggerAlertCue(type = "sound") {
  const soundOn = document.getElementById("soundEnabled")?.checked ?? true;
  const alarmOn = document.getElementById("alarmEnabled")?.checked ?? true;
  if ((type === "sound" && !soundOn) || (type === "alarm" && !alarmOn)) return;
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    const ctx = new AudioCtx();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = type === "alarm" ? "sawtooth" : "sine";
    osc.frequency.value = type === "alarm" ? 880 : 587.33;
    gain.gain.value = 0.08;
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    setTimeout(() => {
      osc.stop();
      ctx.close();
    }, type === "alarm" ? 450 : 180);
  } catch (_) {}
}

async function handleSignup(e) {
  e.preventDefault();
  try {
    const data = await apiRequest(`${API_BASE}/auth.php?action=signup`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        full_name: document.getElementById("signupName").value.trim(),
        email: document.getElementById("signupEmail").value.trim(),
        sip_extension: document.getElementById("signupExt").value,
        password: document.getElementById("signupPassword").value
      })
    });
    showBanner("authBanner", data.message);
    setTimeout(() => { window.location.href = "agent-console.html"; }, 900);
  } catch (error) {
    showBanner("authBanner", error.message || "Sign up failed", true);
  }
}

async function handleLogin(e) {
  e.preventDefault();
  try {
    await apiRequest(`${API_BASE}/auth.php?action=login`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        email: document.getElementById("loginEmail").value.trim(),
        password: document.getElementById("loginPassword").value
      })
    });
    window.location.href = "agent-console.html";
  } catch (error) {
    showBanner("authBanner", error.message || "Login failed", true);
  }
}

async function handleLogout() {
  const recorder = mediaRecorder;
  const recordingStopped = recorder && recorder.state === "recording"
    ? new Promise((resolve) => recorder.addEventListener("stop", resolve, { once: true }))
    : Promise.resolve();
  if (ua) {
    try { ua.stop(); } catch (_) {}
  }
  if (recorder && recorder.state === "recording") {
    recorder.stop();
  }
  await recordingStopped;
  await recordingUploadPromise;
  await fetch(`${API_BASE}/auth.php?action=logout`, {
    method: "POST",
    credentials: "include"
  });
  window.location.href = "index.html";
}

async function sendVerificationCode() {
  try {
    const data = await apiRequest(`${API_BASE}/auth.php?action=send_verification`, {
      method: "POST",
      credentials: "include"
    });
    showBanner("pwdBanner", data.message || "Verification code sent.");
  } catch (error) {
    showBanner("pwdBanner", error.message, true);
  }
}

async function verifyEmailCode(e) {
  e.preventDefault();
  try {
    const data = await apiRequest(`${API_BASE}/auth.php?action=verify_email`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ code: document.getElementById("verifyCode").value.trim() })
    });
    showBanner("pwdBanner", data.message);
  } catch (error) {
    showBanner("pwdBanner", error.message, true);
  }
}

async function changePassword(e) {
  e.preventDefault();
  try {
    const data = await apiRequest(`${API_BASE}/auth.php?action=change_password`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        current_password: document.getElementById("currentPassword").value,
        new_password: document.getElementById("newPassword").value
      })
    });
    showBanner("pwdBanner", data.message);
  } catch (error) {
    showBanner("pwdBanner", error.message, true);
  }
}

async function loadAgentProfile() {
  let data;
  try {
    data = await apiRequest(`${API_BASE}/auth.php?action=me`, { credentials: "include" });
  } catch (_) {
    window.location.href = "index.html";
    return;
  }
  const a = data.agent;
  if (document.getElementById("profileName")) document.getElementById("profileName").value = a.full_name;
  if (document.getElementById("profileExt")) document.getElementById("profileExt").value = a.sip_extension;
  if (document.getElementById("verifiedBadge")) {
    document.getElementById("verifiedBadge").textContent = a.email_verified ? "Verified Email" : "Unverified Email";
  }
  if (document.getElementById("soundEnabled")) document.getElementById("soundEnabled").checked = Boolean(Number(a.sound_enabled));
  if (document.getElementById("alarmEnabled")) document.getElementById("alarmEnabled").checked = Boolean(Number(a.alarm_enabled));
  if (a.avatar_url && document.getElementById("avatarImg")) {
    document.getElementById("avatarImg").src = a.avatar_url;
  }
  loadNotes();
  loadGroupChat();
  loadRecordings();
}

async function saveProfileSettings(e) {
  e.preventDefault();
  const fd = new FormData();
  fd.append("full_name", document.getElementById("profileName").value.trim());
  fd.append("sip_extension", document.getElementById("profileExt").value);
  fd.append("sound_enabled", document.getElementById("soundEnabled").checked ? "1" : "0");
  fd.append("alarm_enabled", document.getElementById("alarmEnabled").checked ? "1" : "0");
  const fileInput = document.getElementById("avatarFile");
  if (fileInput && fileInput.files[0]) {
    fd.append("avatar", fileInput.files[0]);
  }
  try {
    const data = await apiRequest(`${API_BASE}/profile.php`, {
      method: "POST",
      credentials: "include",
      body: fd
    });
    if (data.avatar_url && document.getElementById("avatarImg")) {
      document.getElementById("avatarImg").src = data.avatar_url + "?t=" + Date.now();
    }
    showBanner("consoleBanner", "Profile & alert preferences saved.");
  } catch (error) {
    showBanner("consoleBanner", error.message, true);
  }
}

function connectWebRtc() {
  const ext = document.getElementById("sipExtInput").value.trim() || "1001";
  const secret = document.getElementById("sipPassInput").value;
  if (!WSS_URL || !SIP_DOMAIN) {
    showBanner("consoleBanner", "SIP is not configured. Set SIP_WSS_URL and SIP_DOMAIN in the server .env file.", true);
    return;
  }
  if (!secret) {
    showBanner("consoleBanner", "Enter the SIP password assigned to this extension.", true);
    return;
  }
  if (!window.JsSIP) {
    showBanner("consoleBanner", "JsSIP library failed to load.", true);
    return;
  }
  const socket = new JsSIP.WebSocketInterface(WSS_URL);
  ua = new JsSIP.UA({
    sockets: [socket],
    uri: `sip:${ext}@${SIP_DOMAIN}`,
    password: secret,
    register: true
  });

  ua.on("registered", () => {
    showBanner("consoleBanner", `WebRTC Registered as Extension ${ext}`);
    triggerAlertCue("sound");
  });
  ua.on("registrationFailed", (ev) => showBanner("consoleBanner", `SIP Registration Failed: ${ev.cause}`, true));
  ua.on("newRTCSession", (data) => {
    activeSession = data.session;
    callStartTime = 0;
    activeCallTarget = activeSession.remote_identity.uri.user || "";
    callTypeForRecording = "direct";
    const audioEl = document.getElementById("remoteAudio");
    activeSession.connection.addEventListener("track", (e) => {
      if (e.streams && e.streams[0] && audioEl) {
        audioEl.srcObject = e.streams[0];
      }
      if (data.session === activeSession && data.session.isEstablished()) {
        startAutomaticRecording(data.session);
      }
    });
    if (data.originator === "remote") {
      triggerAlertCue("alarm");
      showBanner("consoleBanner", `Incoming call from ${activeSession.remote_identity.uri.user}`);
    } else {
      showBanner("consoleBanner", "Outgoing WebRTC call initiated...");
    }
    activeSession.on("accepted", () => {
      callStartTime = Date.now();
      showBanner("consoleBanner", "Call connected. Automatic recording is starting.");
      startAutomaticRecording(activeSession);
    });
    activeSession.on("ended", () => {
      finishCall(activeSession, "Call ended.");
    });
    activeSession.on("failed", (event) => {
      finishCall(activeSession, `Call failed${event.cause ? `: ${event.cause}` : "."}`);
    });
  });
  ua.start();
}

function dialTarget(targetOverride = null, isGroup = false) {
  const target = targetOverride || document.getElementById("dialTarget").value.trim();
  if (!ua || !target) {
    showBanner("consoleBanner", "Connect SIP and enter target extension/number first.", true);
    return;
  }
  if (!/^[+0-9A-Za-z._-]{1,64}$/.test(target)) {
    showBanner("consoleBanner", "Enter a valid phone number or SIP extension.", true);
    return;
  }
  activeCallTarget = target;
  callTypeForRecording = isGroup ? "group" : "direct";
  ua.call(`sip:${target}@${SIP_DOMAIN}`, {
    mediaConstraints: { audio: true, video: false },
    extraHeaders: [isGroup ? "X-Group-Call: true" : "X-Direct-Call: true"]
  });
}

function startGroupCall() {
  const roomExt = document.getElementById("groupRoomExt").value || "1001";
  dialTarget(roomExt, true);
  showBanner("consoleBanner", `Joining Group Call Bridge (${roomExt}) for agents 1001, 1002, 1003`);
}

function answerIncoming() {
  if (activeSession && activeSession.direction === "incoming") {
    activeSession.answer({ mediaConstraints: { audio: true, video: false } });
  }
}

function hangupActive() {
  if (activeSession) {
    activeSession.terminate();
  }
}

function transferActive() {
  const dest = document.getElementById("transferTarget").value.trim();
  if (activeSession && /^[+0-9A-Za-z._-]{1,64}$/.test(dest)) {
    activeSession.refer(`sip:${dest}@${SIP_DOMAIN}`);
    showBanner("consoleBanner", `Transferring call to ${dest}...`);
  } else {
    showBanner("consoleBanner", "Enter a valid transfer extension.", true);
  }
}

async function startAutomaticRecording(session, attempt = 0) {
  if (session !== activeSession || mediaRecorder || recordingStarting) {
    return;
  }
  if (typeof MediaRecorder === "undefined" || typeof MediaStream === "undefined") {
    showBanner("consoleBanner", "This browser does not support automatic call recording.", true);
    return;
  }

  const connection = session.connection;
  const localTracks = connection.getSenders().map((sender) => sender.track)
    .filter((track) => track && track.kind === "audio" && track.readyState === "live");
  const remoteTracks = connection.getReceivers().map((receiver) => receiver.track)
    .filter((track) => track && track.kind === "audio" && track.readyState === "live");

  if (!localTracks.length || !remoteTracks.length) {
    if (attempt < 20) {
      setTimeout(() => startAutomaticRecording(session, attempt + 1), 250);
    } else {
      showBanner("consoleBanner", "Call connected, but both audio directions were not available for recording.", true);
    }
    return;
  }

  recordingStarting = true;
  try {
    recordedChunks = [];
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) throw new Error("Web Audio is not supported by this browser.");
    recordingAudioContext = new AudioContextClass();
    const recordingDestination = recordingAudioContext.createMediaStreamDestination();
    [...localTracks, ...remoteTracks].forEach((track) => {
      const source = recordingAudioContext.createMediaStreamSource(new MediaStream([track]));
      source.connect(recordingDestination);
    });
    await recordingAudioContext.resume();
    if (session !== activeSession) {
      await recordingAudioContext.close();
      recordingAudioContext = null;
      return;
    }
    const recorder = new MediaRecorder(recordingDestination.stream);
    mediaRecorder = recorder;
    const recordingTarget = session.remote_identity.uri.user || activeCallTarget || "unknown";
    const recordingCallType = callTypeForRecording;
    const recordingStartedAt = callStartTime;
    recorder.ondataavailable = (event) => {
      if (event.data.size > 0) recordedChunks.push(event.data);
    };
    recorder.onstop = () => {
      const blob = new Blob(recordedChunks, { type: recorder.mimeType || "audio/webm" });
      if (blob.size > 0) {
        recordingUploadPromise = uploadCallRecording(blob, recordingTarget, recordingCallType, Math.round((Date.now() - recordingStartedAt) / 1000));
      }
      mediaRecorder = null;
      if (recordingAudioContext) {
        recordingAudioContext.close().catch((error) => console.error("Could not close recording audio context:", error));
        recordingAudioContext = null;
      }
    };
    recorder.onerror = () => showBanner("consoleBanner", "The browser could not record this call.", true);
    recorder.start(1000);
    showBanner("consoleBanner", "Call connected. Recording is active.");
  } catch (error) {
    mediaRecorder = null;
    if (recordingAudioContext) {
      recordingAudioContext.close().catch((closeError) => console.error("Could not close recording audio context:", closeError));
      recordingAudioContext = null;
    }
    showBanner("consoleBanner", `Call recording could not start: ${error.message}`, true);
  } finally {
    recordingStarting = false;
  }
}

function finishCall(session, message) {
  if (!session || session !== activeSession) return;
  const completed = callStartTime > 0;
  activeCallTarget = session.remote_identity.uri.user || activeCallTarget || "";
  const recipient = document.getElementById("feedbackRecipient");
  if (recipient && activeCallTarget) recipient.value = activeCallTarget;
  if (mediaRecorder && mediaRecorder.state === "recording") mediaRecorder.stop();
  activeSession = null;
  callStartTime = 0;
  showBanner("consoleBanner", completed
    ? `${message} Create a feedback link below to invite the customer to rate the call.`
    : message);
}

async function uploadCallRecording(blob, peerTarget, callType, duration) {
  const fd = new FormData();
  fd.append("audio", blob, "recording.webm");
  fd.append("peer_target", peerTarget || "unknown");
  fd.append("call_type", callType);
  fd.append("duration_sec", String(duration));
  try {
    const data = await apiRequest(`${API_BASE}/recordings.php`, {
      method: "POST",
      credentials: "include",
      body: fd
    });
    showBanner("consoleBanner", `Automatic call recording saved (${data.file_name}).`);
    await loadRecordings();
  } catch (error) {
    showBanner("consoleBanner", `Call recording could not be saved: ${error.message}`, true);
  }
}

async function loadRecordings() {
  const list = document.getElementById("recordingsList");
  if (!list) return;
  try {
    const data = await apiRequest(`${API_BASE}/recordings.php`, { credentials: "include" });
    list.innerHTML = data.recordings.map((r) =>
      `<div class="note-line"><strong>${r.file_name}</strong> (${r.peer_target}, ${r.duration_sec}s) — ${r.created_at}</div>`
    ).join("") || `<div class="helper-text">No recordings stored yet.</div>`;
  } catch (error) {
    showBanner("consoleBanner", error.message, true);
  }
}

async function sendCustomerMessage(channel) {
  const recipient = document.getElementById("msgRecipient").value.trim();
  const body = document.getElementById("msgBody").value.trim();
  try {
    const data = await apiRequest(`${API_BASE}/messages.php?mode=customer_dispatch`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ channel, recipient, body })
    });
    showBanner("consoleBanner", data.message);
    document.getElementById("msgBody").value = "";
  } catch (error) {
    showBanner("consoleBanner", error.message, true);
  }
}

async function sendGroupChat(e) {
  e.preventDefault();
  const input = document.getElementById("groupChatInput");
  const message = input.value.trim();
  if (!message) return;
  try {
    await apiRequest(`${API_BASE}/messages.php?mode=group_chat`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ message })
    });
    input.value = "";
    triggerAlertCue("sound");
    loadGroupChat();
  } catch (error) {
    showBanner("consoleBanner", error.message, true);
  }
}

async function loadGroupChat() {
  const box = document.getElementById("groupChatList");
  if (!box) return;
  try {
    const data = await apiRequest(`${API_BASE}/messages.php?mode=group_chat`, { credentials: "include" });
    box.innerHTML = data.messages.map((m) =>
      `<div class="chat-line"><strong>[${m.sender_ext}] ${m.sender_email}:</strong> ${m.message_body}</div>`
    ).join("");
  } catch (error) {
    showBanner("consoleBanner", error.message, true);
  }
}

async function addShortcutNote(e) {
  e.preventDefault();
  const customerRef = document.getElementById("noteCustomerRef").value.trim();
  const noteText = document.getElementById("noteText").value.trim();
  try {
    await apiRequest(`${API_BASE}/notes.php`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ customer_ref: customerRef, note_text: noteText })
    });
    document.getElementById("noteText").value = "";
    loadNotes();
  } catch (error) {
    showBanner("consoleBanner", error.message, true);
  }
}

async function deleteShortcutNote(id) {
  try {
    await apiRequest(`${API_BASE}/notes.php?id=${encodeURIComponent(id)}`, {
      method: "DELETE",
      credentials: "include"
    });
    loadNotes();
  } catch (error) {
    showBanner("consoleBanner", error.message, true);
  }
}

async function loadNotes() {
  const box = document.getElementById("notesList");
  if (!box) return;
  try {
    const data = await apiRequest(`${API_BASE}/notes.php`, { credentials: "include" });
    box.innerHTML = data.notes.map((n) =>
      `<div class="note-line">
        <strong>${n.customer_ref ? `[${n.customer_ref}] ` : ""}</strong>${n.note_text}
        <span class="helper-text"> (Auto-deletes: ${n.expires_at})</span>
        <button type="button" class="btn btn-soft" onclick="deleteShortcutNote(${n.id})">Delete</button>
      </div>`
    ).join("") || `<div class="helper-text">No active shortcut notes (items expire automatically after 15 days).</div>`;
  } catch (error) {
    showBanner("consoleBanner", error.message, true);
  }
}

async function createFeedbackLink() {
  const recipient = document.getElementById("feedbackRecipient").value.trim();
  try {
    const data = await apiRequest(`${API_BASE}/feedback.php?action=create`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ recipient })
    });
    const surveyUrl = new URL("feedback.html", window.location.href);
    surveyUrl.hash = new URLSearchParams({ token: data.token }).toString();
    document.getElementById("feedbackLinkOutput").textContent = surveyUrl.href;
    document.getElementById("copyFeedbackLinkButton").hidden = false;
    showBanner("consoleBanner", "Feedback link created. Copy it and share it with the customer using your approved follow-up channel.");
  } catch (error) {
    showBanner("consoleBanner", error.message, true);
  }
}

async function copyFeedbackLink() {
  const link = document.getElementById("feedbackLinkOutput").textContent;
  if (!link) return;
  try {
    await navigator.clipboard.writeText(link);
    showBanner("consoleBanner", "Feedback link copied.");
  } catch (_) {
    showBanner("consoleBanner", "Copy was blocked by the browser. Select and copy the feedback link shown below.", true);
  }
}

async function submitCustomerFeedback(e) {
  e.preventDefault();
  const form = e.currentTarget;
  const token = new URLSearchParams(window.location.hash.slice(1)).get("token") || "";
  const conduct = form.querySelector('input[name="conduct_rating"]:checked');
  const behavior = form.querySelector('input[name="behavior_rating"]:checked');
  try {
    const data = await apiRequest(`${API_BASE}/feedback.php?action=submit`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        token,
        conduct_rating: conduct ? conduct.value : "",
        behavior_rating: behavior ? behavior.value : "",
        comments: document.getElementById("feedbackComments").value.trim()
      })
    });
    showBanner("feedbackBanner", data.message);
    form.hidden = true;
  } catch (error) {
    showBanner("feedbackBanner", error.message, true);
  }
}