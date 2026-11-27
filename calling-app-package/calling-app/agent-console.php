<?php
declare(strict_types=1);
require_once __DIR__ . '/api/config.php';

if (empty($_SESSION['agent_id'])) {
    header('Location: index.html', true, 302);
    exit;
}

$agent = requireAuth();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
$sipWssUrl = trim($_ENV['SIP_WSS_URL'] ?? '');
$sipDomain = trim($_ENV['SIP_DOMAIN'] ?? '');
$wssParts = parse_url($sipWssUrl);
$sipHost = parse_url('https://' . $sipDomain, PHP_URL_HOST);
$sipWssUrl = is_array($wssParts)
    && strtolower($wssParts['scheme'] ?? '') === 'wss'
    && !empty($wssParts['host'])
    && empty($wssParts['user'])
    && empty($wssParts['pass'])
    ? $sipWssUrl
    : '';
$sipDomain = $sipHost === $sipDomain ? $sipDomain : '';
$clientConfig = json_encode(
    ['sipWssUrl' => $sipWssUrl, 'sipDomain' => $sipDomain],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="theme-color" content="#111827" />
  <title>Solarooof — Agent Console</title>
  <link rel="stylesheet" href="./styles.css" />
  <script>window.AGENT_CONFIG = <?= $clientConfig ?>;</script>
  <script src="https://cdn.jsdelivr.net/npm/jssip@3.10.1/dist/jssip.min.js"></script>
</head>
<body class="console-page" onload="loadAgentProfile()">
  <header class="topbar">
    <a class="brand-title" href="index.html">Solarooof <span>Agent Portal</span></a>
    <nav class="nav-strip" aria-label="Main navigation">
      <a class="nav-link" href="index.html">Account</a>
      <a class="nav-link" href="set-password.html">Security</a>
      <a class="nav-link active" href="agent-console.php" aria-current="page">Console</a>
      <button type="button" class="btn btn-soft" onclick="handleLogout()">Sign Out</button>
    </nav>
  </header>

  <main class="page-shell">
    <section class="console-intro">
      <div>
        <p class="eyebrow">CUSTOMER OPERATIONS</p>
        <h1 class="section-heading">Agent Console</h1>
        <p class="helper-text">Your secure workspace for calls, team coordination, and customer follow-up.</p>
      </div>
      <div class="status-pill"><span class="status-dot"></span> Session protected</div>
    </section>
    <div id="consoleBanner" class="status-banner" role="status" aria-live="polite">Loading agent session...</div>

    <section class="section-band">
      <h2 class="section-heading">Profile & preferences</h2>
      <form onsubmit="saveProfileSettings(event)">
        <div class="field-row">
          <img id="avatarImg" class="avatar-preview" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='64' height='64'%3E%3Crect width='64' height='64' fill='%23dbeafe'/%3E%3C/svg%3E" alt="Profile avatar" />
          <span id="verifiedBadge" class="helper-text">Checking verification...</span>
          <label for="profileName">Display name</label>
          <input id="profileName" type="text" required />
          <label for="profileExt">Extension</label>
          <select id="profileExt">
            <option value="1001">1001</option>
            <option value="1002">1002</option>
            <option value="1003">1003</option>
          </select>
          <label for="avatarFile">Profile photo</label>
          <input id="avatarFile" type="file" accept="image/png,image/jpeg,image/webp" />
        </div>
        <div class="field-row">
          <label><input id="soundEnabled" type="checkbox" checked /> Sound notifications</label>
          <label><input id="alarmEnabled" type="checkbox" checked /> Call alarm</label>
          <button type="submit" class="btn">Save preferences</button>
        </div>
      </form>
    </section>

    <section class="section-band">
      <h2 class="section-heading">WebRTC calls</h2>
      <p class="helper-text">Connect to the configured SIP service. Recording starts automatically after a call connects; follow your local consent policy and tell customers about recording.</p>
      <div class="recording-notice"><span class="recording-dot"></span> Auto-recording starts when a connected call's audio tracks are available.</div>
      <div class="field-row">
        <label for="sipExtInput">SIP extension</label>
        <input id="sipExtInput" type="text" value="1001" />
        <label for="sipPassInput">SIP password</label>
        <input id="sipPassInput" type="password" placeholder="SIP secret" autocomplete="off" />
        <button type="button" class="btn" onclick="connectWebRtc()">Connect SIP</button>
      </div>
      <div class="field-row">
        <label for="dialTarget">Customer number / extension</label>
        <input id="dialTarget" type="text" placeholder="Customer number or extension" />
        <button type="button" class="btn" onclick="dialTarget()">Call</button>
        <button type="button" class="btn btn-soft" onclick="answerIncoming()">Answer</button>
        <button type="button" class="btn btn-soft" onclick="hangupActive()">Hang up</button>
      </div>
      <div class="field-row">
        <label for="transferTarget">Transfer to</label>
        <input id="transferTarget" type="text" placeholder="Extension" />
        <button type="button" class="btn btn-soft" onclick="transferActive()">Transfer</button>
        <label for="groupRoomExt">Group bridge</label>
        <select id="groupRoomExt">
          <option value="1001">Group Room 1001</option>
          <option value="1002">Group Room 1002</option>
          <option value="1003">Group Room 1003</option>
        </select>
        <button type="button" class="btn" onclick="startGroupCall()">Start group call</button>
      </div>
      <audio id="remoteAudio" autoplay></audio>
      <div id="recordingsList" class="notes-stream"></div>
    </section>

    <section class="section-band" id="feedbackSection">
      <h2 class="section-heading">Customer feedback</h2>
      <p class="helper-text">After a call, create a private survey link for the customer to rate your conduct and behavior from 1 to 5. Copy and share the link using your approved follow-up channel.</p>
      <div class="field-row">
        <label for="feedbackRecipient">Customer number</label>
        <input id="feedbackRecipient" type="text" placeholder="Customer number" />
        <button id="feedbackLinkButton" type="button" class="btn" onclick="createFeedbackLink()">Create feedback link</button>
        <button id="copyFeedbackLinkButton" type="button" class="btn btn-soft" onclick="copyFeedbackLink()" hidden>Copy link</button>
      </div>
      <p id="feedbackLinkOutput" class="feedback-link" aria-live="polite"></p>
    </section>

    <section class="section-band">
      <h2 class="section-heading">Team chat</h2>
      <form onsubmit="sendGroupChat(event)">
        <div class="field-row">
          <input id="groupChatInput" type="text" placeholder="Message team agents..." />
          <button type="submit" class="btn">Send message</button>
        </div>
      </form>
      <div id="groupChatList" class="chat-stream"></div>
    </section>

    <section class="section-band">
      <h2 class="section-heading">Customer message log</h2>
      <div class="field-row">
        <label for="msgRecipient">Customer phone</label>
        <input id="msgRecipient" type="text" placeholder="+91XXXXXXXXXX" />
        <label for="msgBody">Message</label>
        <input id="msgBody" type="text" placeholder="Enter update for customer..." />
        <button type="button" class="btn" onclick="sendCustomerMessage('sms')">Log SMS</button>
        <button type="button" class="btn btn-soft" onclick="sendCustomerMessage('whatsapp')">Log WhatsApp</button>
      </div>
    </section>

    <section class="section-band">
      <h2 class="section-heading">Shortcut notes</h2>
      <p class="helper-text">Customer status notes are automatically deleted after 15 days.</p>
      <form onsubmit="addShortcutNote(event)">
        <div class="field-row">
          <input id="noteCustomerRef" type="text" placeholder="Customer / ticket ID" />
          <input id="noteText" type="text" placeholder="Quick customer status note..." required />
          <button type="submit" class="btn">Save note</button>
        </div>
      </form>
      <div id="notesList" class="notes-stream"></div>
    </section>
  </main>
  <script src="./app.js"></script>
</body>
</html>
