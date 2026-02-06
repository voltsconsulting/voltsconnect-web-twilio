<?php

declare(strict_types=1);

use App\Auth;
use App\Config;
use App\Db;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VoiceGrant;
use Twilio\Rest\Client;
use Twilio\Security\RequestValidator;

require __DIR__ . '/../vendor/autoload.php';

$rootDir = dirname(__DIR__);
Config::load($rootDir);

$forwardedProto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$flash = '';
if (isset($_SESSION['_flash']) && is_string($_SESSION['_flash']) && $_SESSION['_flash'] !== '') {
    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
}

function installed(string $rootDir): bool
{
    return is_file($rootDir . '/storage/installed.lock') || Config::get('APP_INSTALLED', '0') === '1';
}

function userIdFromClientIdentity(?string $identity): ?int
{
    if (!is_string($identity) || $identity === '') {
        return null;
    }
    if (!str_starts_with($identity, 'user_')) {
        return null;
    }
    $rest = substr($identity, 5);
    if ($rest === false) {
        return null;
    }
    if (preg_match('/^(\d+)/', $rest, $m)) {
        $id = (int) ($m[1] ?? 0);
        return $id > 0 ? $id : null;
    }
    return null;
}

function activeTwilioAccount(PDO $pdo, ?int $userId): array
{
    if ($userId === null) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT ta.*
        FROM twilio_accounts ta
        INNER JOIN user_twilio_accounts uta ON uta.twilio_account_id = ta.id
        WHERE uta.user_id = :uid
        ORDER BY uta.is_default DESC, ta.id ASC
        LIMIT 1');
    try {
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch();
        if (is_array($row) && !empty($row)) {
            return $row;
        }
    } catch (\Throwable $e) {
    }

    return [];
}

function twilioConfig(PDO $pdo, ?int $userId): array
{
    $acc = activeTwilioAccount($pdo, $userId);
    if (!empty($acc)) {
        return [
            'source' => 'db',
            'twilio_account_id' => (int) ($acc['id'] ?? 0),
            'account_sid' => (string) ($acc['account_sid'] ?? ''),
            'auth_token' => (string) ($acc['auth_token'] ?? ''),
            'api_key' => (string) ($acc['api_key'] ?? ''),
            'api_secret' => (string) ($acc['api_secret'] ?? ''),
            'twiml_app_sid' => (string) ($acc['twiml_app_sid'] ?? ''),
            'default_from_number' => (string) ($acc['default_from_number'] ?? ''),
        ];
    }

    $defaultId = (int) appSettingGet($pdo, 'default_twilio_account_id', '0');
    if ($defaultId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM twilio_accounts WHERE id = :id LIMIT 1');
        try {
            $stmt->execute([':id' => $defaultId]);
            $row = $stmt->fetch();
            if (is_array($row) && !empty($row)) {
                return [
                    'source' => 'db_default',
                    'twilio_account_id' => (int) ($row['id'] ?? 0),
                    'account_sid' => (string) ($row['account_sid'] ?? ''),
                    'auth_token' => (string) ($row['auth_token'] ?? ''),
                    'api_key' => (string) ($row['api_key'] ?? ''),
                    'api_secret' => (string) ($row['api_secret'] ?? ''),
                    'twiml_app_sid' => (string) ($row['twiml_app_sid'] ?? ''),
                    'default_from_number' => (string) ($row['default_from_number'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
        }
    }

    return [
        'source' => 'none',
        'twilio_account_id' => 0,
        'account_sid' => '',
        'auth_token' => '',
        'api_key' => '',
        'api_secret' => '',
        'twiml_app_sid' => '',
        'default_from_number' => '',
    ];
}

function getPdo(string $rootDir): PDO
{
    static $pdo;
    static $schemaEnsured = false;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = Db::pdo($rootDir);

    if (!$schemaEnsured) {
        Db::ensureSchema($pdo);
        $schemaEnsured = true;
    }
    return $pdo;
}

function appSettingGet(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT v FROM app_settings WHERE k = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $v = $stmt->fetch();
    $raw = (string) (($v['v'] ?? '') ?: '');
    return $raw !== '' ? $raw : $default;
}

function appSettingSet(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO app_settings (k, v) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([':k' => $key, ':v' => $value]);
}

function getOrCreateNumberId(PDO $pdo, string $phoneNumber): ?int
{
    $phoneNumber = trim($phoneNumber);
    if ($phoneNumber === '') {
        return null;
    }

    $pdo->prepare('INSERT IGNORE INTO numbers (phone_number, friendly_name) VALUES (:pn, NULL)')
        ->execute([':pn' => $phoneNumber]);

    $stmt = $pdo->prepare('SELECT id FROM numbers WHERE phone_number = :pn LIMIT 1');
    $stmt->execute([':pn' => $phoneNumber]);
    $id = (int) (($stmt->fetch()['id'] ?? 0) ?: 0);
    return $id > 0 ? $id : null;
}

function getOrCreateContactId(PDO $pdo, string $phoneNumber): int
{
    $phoneNumber = trim($phoneNumber);
    $pdo->prepare('INSERT IGNORE INTO contacts (name, phone_number) VALUES (NULL, :pn)')
        ->execute([':pn' => $phoneNumber]);
    $stmt = $pdo->prepare('SELECT id FROM contacts WHERE phone_number = :pn LIMIT 1');
    $stmt->execute([':pn' => $phoneNumber]);
    return (int) (($stmt->fetch()['id'] ?? 0) ?: 0);
}

function getOrCreateConversationId(PDO $pdo, int $contactId, ?int $defaultNumberId = null): int
{
    if ($defaultNumberId !== null) {
        $stmt = $pdo->prepare('SELECT id FROM conversations WHERE contact_id = :cid AND default_number_id = :nid LIMIT 1');
        $stmt->execute([':cid' => $contactId, ':nid' => $defaultNumberId]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM conversations WHERE contact_id = :cid AND default_number_id IS NULL LIMIT 1');
        $stmt->execute([':cid' => $contactId]);
    }
    $existing = (int) (($stmt->fetch()['id'] ?? 0) ?: 0);
    if ($existing > 0) {
        return $existing;
    }

    $ins = $pdo->prepare('INSERT INTO conversations (contact_id, default_number_id, last_message_at) VALUES (:cid, :nid, NOW())');
    $ins->execute([':cid' => $contactId, ':nid' => $defaultNumberId]);
    return (int) $pdo->lastInsertId();
}

function updateConversationPreview(PDO $pdo, int $conversationId, string $body): void
{
    $preview = trim($body);
    if (strlen($preview) > 255) {
        $preview = substr($preview, 0, 255);
    }
    $pdo->prepare('UPDATE conversations SET last_message_preview = :p, last_message_at = NOW() WHERE id = :id')
        ->execute([':p' => $preview, ':id' => $conversationId]);
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (!installed($rootDir)) {
    if (is_string($uri) && str_starts_with($uri, '/api/')) {
        json(['error' => 'Not installed'], 503);
    }
    $isStatic = is_string($uri) && preg_match('/\.(css|js|png|svg|ico)$/i', $uri) === 1;
    if ($uri !== '/install' && $uri !== '/install/' && !$isStatic && !str_starts_with((string) $uri, '/assets/') && !str_starts_with((string) $uri, '/webhooks/')) {
        redirect('/install');
    }
}

if (is_string($uri) && str_starts_with($uri, '/api/') && !Auth::check()) {
    json(['error' => 'Not authenticated'], 401);
}

if (is_string($uri) && str_starts_with($uri, '/assets/img/') && $method === 'GET') {
    $rel = substr($uri, strlen('/assets/img/'));
    $rel = $rel === false ? '' : $rel;
    $rel = str_replace('..', '', $rel);
    $path = $rootDir . '/storage/Assets/img/' . $rel;
    if (!is_file($path)) {
        $path = $rootDir . '/storage/assets/img/' . $rel;
    }
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'png') {
        header('Content-Type: image/png');
    } elseif ($ext === 'svg') {
        header('Content-Type: image/svg+xml; charset=utf-8');
    } elseif ($ext === 'ico') {
        header('Content-Type: image/x-icon');
    } else {
        header('Content-Type: application/octet-stream');
    }
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function isAdminUser(PDO $pdo, ?int $userId): bool
{
    if ($userId === null) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    return (string) (($stmt->fetch()['role'] ?? '') ?: '') === 'admin';
}

function envQuote(string $v): string
{
    $needs = $v === '' || strpbrk($v, " \t\n\r#=") !== false;
    if (!$needs) {
        return $v;
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
}

function writeEnvFile(string $rootDir, array $kv): void
{
    $lines = [];
    foreach ($kv as $k => $v) {
        $key = trim((string) $k);
        if ($key === '') {
            continue;
        }
        $val = is_string($v) ? $v : (string) $v;
        $lines[] = $key . '=' . envQuote($val);
    }
    $body = implode("\n", $lines) . "\n";
    $ok = @file_put_contents($rootDir . '/.env', $body);
    if ($ok === false) {
        throw new \RuntimeException('Could not write .env. Ensure the project root is writable.');
    }
}

function rateLimitCheck(string $rootDir, string $bucket, int $maxHits, int $windowSeconds): bool
{
    $dir = $rootDir . '/storage/ratelimits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/rl_' . sha1($bucket) . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $raw = (string) @file_get_contents($file);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $hits = $decoded;
        }
    }
    $min = $now - $windowSeconds;
    $hits = array_values(array_filter($hits, static fn($t) => is_int($t) && $t >= $min));
    if (count($hits) >= $maxHits) {
        return false;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits));
    return true;
}

if ($uri === '/webhooks/twilio/voice/recording' && $method === 'POST') {
    $pdo = getPdo($rootDir);
    if (!validateTwilioWebhook($pdo)) {
        error_log('Twilio voice recording invalid signature url=' . (baseUrl() . (string) ($_SERVER['REQUEST_URI'] ?? '/')));
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid signature';
        exit;
    }

    $callSid = (string) ($_POST['CallSid'] ?? '');
    $recSid = (string) ($_POST['RecordingSid'] ?? '');
    $recUrl = (string) ($_POST['RecordingUrl'] ?? '');
    $recDur = (string) ($_POST['RecordingDuration'] ?? '');
    $dur = null;
    if ($recDur !== '' && ctype_digit($recDur)) {
        $dur = (int) $recDur;
    }
    if ($callSid !== '' && $recUrl !== '') {
        $sql = 'UPDATE calls SET recording_url = :url, recording_sid = :rsid';
        $params = [':url' => $recUrl, ':rsid' => ($recSid !== '' ? $recSid : null), ':sid' => $callSid];
        if ($dur !== null) {
            $sql .= ', recording_duration = :dur';
            $params[':dur'] = $dur;
        }
        $sql .= ' WHERE twilio_sid = :sid';
        try {
            $pdo->prepare($sql)->execute($params);
        } catch (\Throwable $e) {
        }
    }
    http_response_code(204);
    exit;
}

if ($uri === '/webhooks/twilio/voice/fallback' && $method === 'POST') {
    $pdo = getPdo($rootDir);
    if (!validateTwilioWebhook($pdo)) {
        error_log('Twilio voice fallback invalid signature url=' . (baseUrl() . (string) ($_SERVER['REQUEST_URI'] ?? '/')));
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid signature';
        exit;
    }

    header('Content-Type: text/xml; charset=utf-8');

    $dialStatus = (string) ($_POST['DialCallStatus'] ?? '');
    $from = trim((string) ($_POST['From'] ?? ''));
    $to = trim((string) ($_POST['To'] ?? ''));

    if (!in_array($dialStatus, ['no-answer', 'busy', 'failed'], true)) {
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>";
        echo '<Response><Hangup/></Response>';
        exit;
    }

    $calledNumber = trim((string) ($_POST['To'] ?? ''));
    if ($calledNumber === '') {
        $calledNumber = trim((string) ($_POST['Called'] ?? ''));
    }
    $nStmt = $pdo->prepare('SELECT voice_forward_number, voice_ring_timeout FROM numbers WHERE phone_number = :pn LIMIT 1');
    $nStmt->execute([':pn' => $calledNumber]);
    $nRow = $nStmt->fetch();

    $forwardTo = trim((string) (($nRow['voice_forward_number'] ?? '') ?: ''));
    if ($forwardTo === '') {
        $forwardTo = trim(appSettingGet($pdo, 'voice_forward_number', ''));
    }
    $voicemailEnabled = appSettingGet($pdo, 'voice_voicemail_enabled', '0') === '1';
    $voicemailGreeting = appSettingGet($pdo, 'voice_voicemail_greeting', 'Please leave a message after the tone.');
    $vmMaxLen = (int) appSettingGet($pdo, 'voice_voicemail_max_length', '60');
    if ($vmMaxLen < 10) {
        $vmMaxLen = 10;
    }
    if ($vmMaxLen > 300) {
        $vmMaxLen = 300;
    }

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>";

    if ($forwardTo !== '') {
        $statusCallback = htmlspecialchars(baseUrl() . '/webhooks/twilio/voice/status', ENT_QUOTES, 'UTF-8');
        $safeTo = htmlspecialchars($forwardTo, ENT_QUOTES, 'UTF-8');
        $callerId = htmlspecialchars($from !== '' ? $from : $to, ENT_QUOTES, 'UTF-8');
        echo '<Response><Dial callerId="' . $callerId . '" statusCallback="' . $statusCallback . '" statusCallbackEvent="initiated ringing answered completed"><Number>' . $safeTo . '</Number></Dial></Response>';
        exit;
    }

    if ($voicemailEnabled) {
        $say = htmlspecialchars($voicemailGreeting, ENT_QUOTES, 'UTF-8');
        $recCb = htmlspecialchars(baseUrl() . '/webhooks/twilio/voice/voicemail', ENT_QUOTES, 'UTF-8');
        echo '<Response>';
        echo '<Say>' . $say . '</Say>';
        echo '<Record maxLength="' . $vmMaxLen . '" recordingStatusCallback="' . $recCb . '" recordingStatusCallbackMethod="POST" />';
        echo '<Hangup/>';
        echo '</Response>';
        exit;
    }

    echo '<Response><Hangup/></Response>';
    exit;
}

if ($uri === '/webhooks/twilio/voice/voicemail' && $method === 'POST') {
    $pdo = getPdo($rootDir);
    if (!validateTwilioWebhook($pdo)) {
        error_log('Twilio voicemail invalid signature url=' . (baseUrl() . (string) ($_SERVER['REQUEST_URI'] ?? '/')));
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid signature';
        exit;
    }

    $callSid = (string) ($_POST['CallSid'] ?? '');
    $from = trim((string) ($_POST['From'] ?? ''));
    $to = trim((string) ($_POST['To'] ?? ''));
    $recUrl = trim((string) ($_POST['RecordingUrl'] ?? ''));
    $recDur = (string) ($_POST['RecordingDuration'] ?? '');
    $dur = null;
    if ($recDur !== '' && ctype_digit($recDur)) {
        $dur = (int) $recDur;
    }

    $pdo->prepare('INSERT INTO voicemails (call_sid, from_number, to_number, recording_url, recording_duration)
        VALUES (:sid, :from, :to, :url, :dur)')
        ->execute([
            ':sid' => ($callSid !== '' ? $callSid : null),
            ':from' => ($from !== '' ? $from : null),
            ':to' => ($to !== '' ? $to : null),
            ':url' => ($recUrl !== '' ? $recUrl : null),
            ':dur' => $dur,
        ]);

    try {
        $stmt = $pdo->query("SELECT email FROM users WHERE role = 'admin'");
        $admins = $stmt->fetchAll();
        $recSid = '';
        if ($recUrl !== '') {
            if (preg_match('/\/Recordings\/(RE[a-zA-Z0-9]+)/', $recUrl, $m)) {
                $recSid = (string) ($m[1] ?? '');
            }
        }
        $recLink = $recSid !== '' ? (baseUrl() . '/api/voice/recording?sid=' . rawurlencode($recSid)) : '';
        if ($recLink !== '' && is_array($admins)) {
            $subject = 'Voicemail from ' . ($from !== '' ? $from : 'Unknown');
            $when = date('c');
            $body = "You have a new voicemail.\n\nFrom: {$from}\nTo: {$to}\nTime: {$when}\nCallSid: {$callSid}\nRecording: {$recLink}\n";
            foreach ($admins as $a) {
                $toEmail = (string) (($a['email'] ?? '') ?: '');
                if ($toEmail !== '') {
                    @mail($toEmail, $subject, $body);
                }
            }
        }
    } catch (\Throwable $e) {
    }

    http_response_code(204);
    exit;
}

if ($uri === '/webhooks/twilio/voice' && $method === 'POST') {
    $pdo = getPdo($rootDir);
    if (!validateTwilioWebhook($pdo)) {
        error_log('Twilio voice webhook invalid signature url=' . (baseUrl() . (string) ($_SERVER['REQUEST_URI'] ?? '/')));
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid signature';
        exit;
    }

    header('Content-Type: text/xml; charset=utf-8');

    $to = trim((string)($_POST['To'] ?? ''));
    $from = trim((string)($_POST['From'] ?? ''));
    $callSid = (string)($_POST['CallSid'] ?? '');
    $callStatus = (string)($_POST['CallStatus'] ?? '');

    $isOutboundFromBrowser = str_starts_with($from, 'client:') && $to !== '' && !str_starts_with($to, 'client:');

    $clientIdentity = null;
    if (str_starts_with($from, 'client:')) {
        $clientIdentity = substr($from, 7);
    } elseif (str_starts_with($to, 'client:')) {
        $clientIdentity = substr($to, 7);
    }
    $userId = userIdFromClientIdentity($clientIdentity);

    $callerId = '';
    $fromNumberId = (int) ($_POST['FromNumberId'] ?? 0);
    if ($isOutboundFromBrowser) {
        if ($userId !== null && $fromNumberId > 0) {
            $stmt = $pdo->prepare('SELECT n.phone_number
                FROM numbers n
                INNER JOIN user_numbers un ON un.number_id = n.id
                WHERE un.user_id = :uid AND n.id = :nid
                LIMIT 1');
            $stmt->execute([':uid' => $userId, ':nid' => $fromNumberId]);
            $callerId = (string) (($stmt->fetch()['phone_number'] ?? '') ?: '');
        }
        if ($callerId === '') {
            $cfg = twilioConfig($pdo, $userId);
            $callerId = (string) (($cfg['default_from_number'] ?? '') ?: '');
        }
    }

    $dbFrom = $from;
    $dbTo = $to;
    if ($isOutboundFromBrowser && $callerId !== '') {
        $dbFrom = $callerId;
        $dbTo = $to;
    }

    if ($callSid !== '') {
        $direction = $isOutboundFromBrowser ? 'outbound' : 'inbound';
        $insert = $pdo->prepare('INSERT IGNORE INTO calls (direction, from_number, to_number, twilio_sid, status, user_id, client_identity, started_at)
            VALUES (:dir, :from, :to, :sid, :st, :uid, :ident, NOW())');
        $insert->execute([
            ':dir' => $direction,
            ':from' => $dbFrom,
            ':to' => $dbTo,
            ':sid' => $callSid,
            ':st' => $callStatus,
            ':uid' => $userId,
            ':ident' => $clientIdentity,
        ]);

        $pdo->prepare('UPDATE calls SET status = :st, user_id = COALESCE(user_id, :uid), client_identity = COALESCE(client_identity, :ident), started_at = COALESCE(started_at, NOW()) WHERE twilio_sid = :sid')
            ->execute([':st' => $callStatus, ':uid' => $userId, ':ident' => $clientIdentity, ':sid' => $callSid]);
    }

    if ($isOutboundFromBrowser) {
        $statusCallback = htmlspecialchars(baseUrl() . '/webhooks/twilio/voice/status', ENT_QUOTES, 'UTF-8');
        $safeTo = htmlspecialchars($to, ENT_QUOTES, 'UTF-8');
        $safeCaller = htmlspecialchars($callerId !== '' ? $callerId : $from, ENT_QUOTES, 'UTF-8');
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>";
        echo '<Response><Dial callerId="' . $safeCaller . '" statusCallback="' . $statusCallback . '" statusCallbackEvent="initiated ringing answered completed"><Number>' . $safeTo . '</Number></Dial></Response>';
        exit;
    }

    $calledNumber = $to;
    if ($calledNumber === '') {
        $calledNumber = trim((string)($_POST['Called'] ?? ''));
    }

    if ($from !== '' && !str_starts_with($from, 'client:') && (str_starts_with($from, '+') || ctype_digit(ltrim($from, '+')))) {
        try {
            getOrCreateContactId($pdo, $from);
        } catch (\Throwable $e) {
        }
    }

    $stmt = $pdo->prepare('SELECT u.id, u.email FROM users u
        INNER JOIN user_numbers un ON un.user_id = u.id
        INNER JOIN numbers n ON n.id = un.number_id
        WHERE n.phone_number = :pn
        ORDER BY un.is_default DESC, u.id ASC');
    $stmt->execute([':pn' => $calledNumber]);
    $users = $stmt->fetchAll();

    $clientIdentities = [];
    foreach ($users as $u) {
        $uid = (int) $u['id'];
        $email = (string) ($u['email'] ?? '');
        $identity = 'user_' . $uid;
        if ($email !== '') {
            $identity = 'user_' . $uid . '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $email);
        }
        $clientIdentities[] = $identity;
    }

    if (count($clientIdentities) === 0) {
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>";
        echo '<Response><Hangup/></Response>';
        exit;
    }

    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>";
    $statusCallback = htmlspecialchars(baseUrl() . '/webhooks/twilio/voice/status', ENT_QUOTES, 'UTF-8');
    $timeout = (int) appSettingGet($pdo, 'voice_ring_timeout', '20');
    try {
        $nStmt = $pdo->prepare('SELECT voice_ring_timeout FROM numbers WHERE phone_number = :pn LIMIT 1');
        $nStmt->execute([':pn' => $calledNumber]);
        $nr = $nStmt->fetch();
        $ov = (int) (($nr['voice_ring_timeout'] ?? 0) ?: 0);
        if ($ov > 0) {
            $timeout = $ov;
        }
    } catch (\Throwable $e) {
    }
    if ($timeout < 5) {
        $timeout = 5;
    }
    if ($timeout > 60) {
        $timeout = 60;
    }
    $action = htmlspecialchars(baseUrl() . '/webhooks/twilio/voice/fallback', ENT_QUOTES, 'UTF-8');
    echo '<Response><Dial timeout="' . $timeout . '" action="' . $action . '" method="POST" statusCallback="' . $statusCallback . '" statusCallbackEvent="initiated ringing answered completed">';
    foreach ($clientIdentities as $id) {
        $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        echo '<Client>' . $safeId . '</Client>';
    }
    echo '</Dial></Response>';
    exit;
}

if ($uri === '/api/voice/record-start' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);

    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        json(['error' => 'Invalid JSON'], 400);
    }
    $callSid = trim((string) ($data['call_sid'] ?? ''));
    if ($callSid === '') {
        json(['error' => 'call_sid required'], 422);
    }

    $accountSid = '';
    $token = '';

    $callRow = null;
    try {
        $st = $pdo->prepare('SELECT direction, from_number, to_number FROM calls WHERE twilio_sid = :sid LIMIT 1');
        $st->execute([':sid' => $callSid]);
        $callRow = $st->fetch();
    } catch (\Throwable $e) {
    }

    $uid = Auth::userId();
    $isAdmin = false;
    try {
        $isAdmin = isAdminUser($pdo, $uid);
    } catch (\Throwable $e) {
        $isAdmin = false;
    }

    if (!$isAdmin && is_array($callRow) && !empty($callRow)) {
        $dir = trim((string) (($callRow['direction'] ?? '') ?: ''));
        $fromPnAuth = trim((string) (($callRow['from_number'] ?? '') ?: ''));
        $toPnAuth = trim((string) (($callRow['to_number'] ?? '') ?: ''));
        $twilioPn = $dir === 'outbound' ? $fromPnAuth : $toPnAuth;
        if ($uid !== null && $twilioPn !== '') {
            $chk = $pdo->prepare('SELECT 1
                FROM user_numbers un
                INNER JOIN numbers n ON n.id = un.number_id
                WHERE un.user_id = :uid AND n.phone_number = :pn
                LIMIT 1');
            $chk->execute([':uid' => $uid, ':pn' => $twilioPn]);
            if (!$chk->fetch()) {
                json(['error' => 'Forbidden'], 403);
            }
        }
    }

    $fromPn = is_array($callRow) ? trim((string) (($callRow['from_number'] ?? '') ?: '')) : '';
    $toPn = is_array($callRow) ? trim((string) (($callRow['to_number'] ?? '') ?: '')) : '';
    if ($fromPn !== '' || $toPn !== '') {
        try {
            $st = $pdo->prepare('SELECT ta.account_sid, ta.auth_token
                FROM numbers n
                INNER JOIN twilio_accounts ta ON ta.id = n.twilio_account_id
                WHERE n.phone_number IN (:a, :b)
                ORDER BY (n.phone_number = :a) DESC
                LIMIT 1');
            $st->execute([':a' => $fromPn, ':b' => $toPn]);
            $acc = $st->fetch();
            if (is_array($acc)) {
                $accountSid = (string) (($acc['account_sid'] ?? '') ?: '');
                $token = (string) (($acc['auth_token'] ?? '') ?: '');
            }
        } catch (\Throwable $e) {
        }
    }

    if ($accountSid === '' || $token === '') {
        $cfg = twilioConfig($pdo, Auth::userId());
        $accountSid = (string) ($cfg['account_sid'] ?? '');
        $token = (string) ($cfg['auth_token'] ?? '');
    }

    if ($accountSid === '' || $token === '') {
        json(['error' => 'Missing Twilio credentials for recording'], 500);
    }

    $client = new Client($accountSid, $token);
    $recCb = baseUrl() . '/webhooks/twilio/voice/recording';

    try {
        $rec = $client->calls($callSid)->recordings->create([
            'recordingStatusCallback' => $recCb,
            'recordingStatusCallbackMethod' => 'POST',
        ]);
        json([
            'ok' => true,
            'recording_sid' => (string) ($rec->sid ?? ''),
            'recording_url' => (string) ($rec->uri ?? ''),
        ]);
    } catch (\Throwable $e) {
        json(['error' => 'Recording start failed', 'detail' => $e->getMessage()], 502);
    }
}

if ($uri === '/api/voice/recording' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);

    $recSid = trim((string) ($_GET['sid'] ?? ''));
    if ($recSid === '') {
        json(['error' => 'sid required'], 422);
    }

    $row = null;
    $isVoicemail = false;
    try {
        $st = $pdo->prepare('SELECT recording_url, direction, from_number, to_number
            FROM calls
            WHERE recording_sid = :sid OR recording_url LIKE :like
            LIMIT 1');
        $st->execute([':sid' => $recSid, ':like' => '%' . $recSid . '%']);
        $row = $st->fetch();
    } catch (\Throwable $e) {
    }

    if (!is_array($row) || empty($row)) {
        try {
            $st = $pdo->prepare('SELECT recording_url, from_number, to_number FROM voicemails WHERE recording_url LIKE :like LIMIT 1');
            $st->execute([':like' => '%' . $recSid . '%']);
            $row = $st->fetch();
            if (is_array($row) && !empty($row)) {
                $isVoicemail = true;
            }
        } catch (\Throwable $e) {
        }
    }

    if (!is_array($row) || empty($row)) {
        json(['error' => 'Recording not found'], 404);
    }

    if ($isVoicemail) {
        requireAdmin($pdo);
    }

    $recordingUrl = trim((string) (($row['recording_url'] ?? '') ?: ''));
    $direction = trim((string) (($row['direction'] ?? '') ?: ''));
    $fromNumber = trim((string) (($row['from_number'] ?? '') ?: ''));
    $toNumber = trim((string) (($row['to_number'] ?? '') ?: ''));

    if (!$isVoicemail) {
        $uid = Auth::userId();
        $isAdmin = false;
        try {
            $isAdmin = isAdminUser($pdo, $uid);
        } catch (\Throwable $e) {
            $isAdmin = false;
        }
        if (!$isAdmin) {
            $twilioPn = $direction === 'outbound' ? $fromNumber : $toNumber;
            if ($uid !== null && $twilioPn !== '') {
                $chk = $pdo->prepare('SELECT 1
                    FROM user_numbers un
                    INNER JOIN numbers n ON n.id = un.number_id
                    WHERE un.user_id = :uid AND n.phone_number = :pn
                    LIMIT 1');
                $chk->execute([':uid' => $uid, ':pn' => $twilioPn]);
                if (!$chk->fetch()) {
                    json(['error' => 'Forbidden'], 403);
                }
            }
        }
    }

    $accountSid = '';
    $token = '';

    if ($fromNumber !== '' || $toNumber !== '') {
        try {
            $st = $pdo->prepare('SELECT ta.account_sid, ta.auth_token
                FROM numbers n
                INNER JOIN twilio_accounts ta ON ta.id = n.twilio_account_id
                WHERE n.phone_number IN (:a, :b)
                ORDER BY (n.phone_number = :a) DESC
                LIMIT 1');
            $st->execute([':a' => $fromNumber, ':b' => $toNumber]);
            $acc = $st->fetch();
            if (is_array($acc)) {
                $accountSid = (string) (($acc['account_sid'] ?? '') ?: '');
                $token = (string) (($acc['auth_token'] ?? '') ?: '');
            }
        } catch (\Throwable $e) {
        }
    }

    if ($accountSid === '' || $token === '') {
        $cfg = twilioConfig($pdo, Auth::userId());
        $accountSid = (string) ($cfg['account_sid'] ?? '');
        $token = (string) ($cfg['auth_token'] ?? '');
    }

    if ($accountSid === '' || $token === '') {
        json(['error' => 'Missing Twilio credentials for recording'], 500);
    }

    $base = $recordingUrl;
    if ($base === '') {
        $base = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Recordings/' . rawurlencode($recSid);
    }
    $base = preg_replace('/\\.json($|\\?)/i', '', $base);
    $base = preg_replace('/\\.(mp3|wav)($|\\?)/i', '', $base);

    $audioUrl = $base . '.mp3';

    $ch = curl_init($audioUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $accountSid . ':' . $token);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $http < 200 || $http >= 300) {
        json(['error' => 'Failed to fetch recording', 'status' => $http, 'detail' => $cerr], 502);
    }

    header('Content-Type: audio/mpeg');
    header('Content-Disposition: inline; filename="recording_' . htmlspecialchars($recSid, ENT_QUOTES, 'UTF-8') . '.mp3"');
    echo $body;
    exit;
}

if ($uri === '/webhooks/twilio/voice/status' && $method === 'POST') {
    $pdo = getPdo($rootDir);
    if (!validateTwilioWebhook($pdo)) {
        error_log('Twilio voice status invalid signature url=' . (baseUrl() . (string) ($_SERVER['REQUEST_URI'] ?? '/')));
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid signature';
        exit;
    }

    $callSid = (string)($_POST['CallSid'] ?? '');
    $callStatus = (string)($_POST['CallStatus'] ?? '');
    $duration = (string)($_POST['CallDuration'] ?? '');
    if ($callSid !== '' && $callStatus !== '') {
        $durInt = null;
        if ($duration !== '' && ctype_digit($duration)) {
            $durInt = (int) $duration;
        }
        $ended = in_array($callStatus, ['completed', 'busy', 'failed', 'no-answer', 'canceled'], true);
        $sql = 'UPDATE calls SET status = :st';
        if ($ended) {
            $sql .= ', ended_at = COALESCE(ended_at, NOW())';
        }
        if ($durInt !== null) {
            $sql .= ', duration_seconds = :dur';
        }
        $sql .= ' WHERE twilio_sid = :sid';
        $upd = $pdo->prepare($sql);
        $params = [':st' => $callStatus, ':sid' => $callSid];
        if ($durInt !== null) {
            $params[':dur'] = $durInt;
        }
        $upd->execute($params);
    }
    http_response_code(204);
    exit;
}

if ($uri === '/webhooks/twilio/sms' && $method === 'POST') {
    $pdo = getPdo($rootDir);
    if (!validateTwilioWebhook($pdo)) {
        error_log('Twilio sms webhook invalid signature url=' . (baseUrl() . (string) ($_SERVER['REQUEST_URI'] ?? '/')));
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid signature';
        exit;
    }

    $from = trim((string)($_POST['From'] ?? ''));
    $to = trim((string)($_POST['To'] ?? ''));
    $body = (string)($_POST['Body'] ?? '');
    $sid = (string)($_POST['MessageSid'] ?? '');
    $status = (string)($_POST['SmsStatus'] ?? ($_POST['MessageStatus'] ?? 'received'));

    if ($from !== '' && $to !== '') {
        $contactId = getOrCreateContactId($pdo, $from);
        $defaultNumberId = getOrCreateNumberId($pdo, $to);
        $conversationId = getOrCreateConversationId($pdo, $contactId, $defaultNumberId);

        $pdo->prepare('INSERT IGNORE INTO messages (conversation_id, user_id, direction, from_number, to_number, body, twilio_sid, status)
            VALUES (:cid, NULL, :dir, :from, :to, :body, :sid, :st)')
            ->execute([
                ':cid' => $conversationId,
                ':dir' => 'inbound',
                ':from' => $from,
                ':to' => $to,
                ':body' => $body,
                ':sid' => ($sid !== '' ? $sid : null),
                ':st' => ($status !== '' ? $status : 'received'),
            ]);
        updateConversationPreview($pdo, $conversationId, $body);
    }

    http_response_code(204);
    exit;
}

if ($uri === '/webhooks/twilio/sms/status' && $method === 'POST') {
    $pdo = getPdo($rootDir);
    if (!validateTwilioWebhook($pdo)) {
        error_log('Twilio sms status invalid signature url=' . (baseUrl() . (string) ($_SERVER['REQUEST_URI'] ?? '/')));
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid signature';
        exit;
    }
    $sid = (string)($_POST['MessageSid'] ?? '');
    $st = (string)($_POST['MessageStatus'] ?? ($_POST['SmsStatus'] ?? ''));
    if ($sid !== '' && $st !== '') {
        $pdo->prepare('UPDATE messages SET status = :st WHERE twilio_sid = :sid')->execute([':st' => $st, ':sid' => $sid]);
    }
    http_response_code(204);
    exit;
}

function json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function baseUrl(): string
{
    $env = Config::get('BASE_URL');
    if (is_string($env) && $env !== '') {
        $env = trim($env);

        $env = preg_replace('/\s+/', '', $env);
        $env = preg_replace('/^(https?:\/\/)+/i', 'https://', $env);
        $env = preg_replace('/^http:(https:\/\/)/i', '$1', $env);
        $env = preg_replace('/^https:(http:\/\/)/i', '$1', $env);
        $env = preg_replace('/^https?:https:\/\//i', 'https://', $env);
        $env = preg_replace('/^https?:http:\/\//i', 'http://', $env);

        if (!preg_match('/^https?:\/\//i', $env)) {
            $env = 'https://' . ltrim($env, '/');
        }

        $parsed = parse_url($env);
        if (is_array($parsed) && isset($parsed['host'])) {
            $scheme = (string)($parsed['scheme'] ?? 'https');
            $host = (string)$parsed['host'];
            if (isset($parsed['port'])) {
                $host .= ':' . (string)$parsed['port'];
            }
            $path = (string)($parsed['path'] ?? '');
            $path = rtrim($path, '/');
            if ($path === '/public' || str_ends_with($path, '/public')) {
                $path = preg_replace('/\/public$/', '', $path);
            }
            return rtrim($scheme . '://' . $host . $path, '/');
        }

        return rtrim($env, '/');
    }

    $forwardedProto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if ($forwardedProto === 'https' || $forwardedProto === 'http') {
        $scheme = $forwardedProto;
    }

    $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = trim($host);
    if (str_contains($host, '://')) {
        $parsed = parse_url($host);
        if (is_array($parsed) && isset($parsed['host'])) {
            $host = (string) $parsed['host'];
            if (isset($parsed['port'])) {
                $host .= ':' . (string) $parsed['port'];
            }
        }
    }

    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = '';
    if ($scriptName !== '') {
        $dir = str_replace('\\', '/', dirname($scriptName));
        if ($dir !== '/' && $dir !== '.' && $dir !== '\\') {
            $basePath = rtrim($dir, '/');
        }
    }

    if ($basePath === '/public' || str_ends_with($basePath, '/public')) {
        $basePath = preg_replace('/\/public$/', '', $basePath);
        if ($basePath === '/') {
            $basePath = '';
        }
    }

    $forwardedPrefix = (string)($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '');
    if ($forwardedPrefix !== '') {
        $basePath = rtrim($forwardedPrefix, '/');
        if ($basePath === '/') {
            $basePath = '';
        }
    }

    return rtrim($scheme . '://' . $host . $basePath, '/');
}

function validateTwilioWebhook(?PDO $pdo = null): bool
{
    $default = (Config::get('APP_ENV', 'local') === 'local') ? '0' : '1';
    $enabled = Config::get('TWILIO_VALIDATE_WEBHOOK', $default);
    if ($enabled !== '1') {
        return true;
    }

    $signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
    $url = baseUrl() . (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $params = $_POST;

    $tokens = [];
    if ($pdo instanceof PDO) {
        try {
            $rows = $pdo->query('SELECT auth_token FROM twilio_accounts')->fetchAll();
            foreach ($rows as $r) {
                $t = (string) (($r['auth_token'] ?? '') ?: '');
                if ($t !== '') {
                    $tokens[] = $t;
                }
            }
        } catch (\Throwable $e) {
        }
    }
    $tokens = array_values(array_unique($tokens));
    if (count($tokens) === 0) {
        return false;
    }

    foreach ($tokens as $token) {
        try {
            $validator = new RequestValidator($token);
            if ($validator->validate($signature, $url, $params)) {
                return true;
            }
        } catch (\Throwable $e) {
        }
    }
    return false;
}

function render(string $title, string $bodyHtml): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<link rel="icon" type="image/png" href="/assets/img/fav-icon.png">';
    echo '<link rel="stylesheet" href="/styles.css">';
    $wrap = true;
    if (str_contains($bodyHtml, 'class="appShell"') || str_contains($bodyHtml, "class='appShell'")) {
        $wrap = false;
    }
    echo '</head><body>';
    if ($wrap) {
        echo '<div class="container">' . $bodyHtml . '</div>';
    } else {
        echo $bodyHtml;
    }
    echo '</body></html>';
}

function requireAdmin(PDO $pdo): void
{
    Auth::requireLogin();
    $uid = Auth::userId();
    if ($uid === null) {
        json(['error' => 'Not authenticated'], 401);
    }
    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $uid]);
    $role = (string) (($stmt->fetch()['role'] ?? '') ?: '');
    if ($role !== 'admin') {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (str_starts_with($path, '/api/')) {
            json(['error' => 'Admin only'], 403);
        }
        http_response_code(403);
        render('Forbidden', '<div class="topbar"><div class="brand">Twilio Platform</div></div><div class="card"><h2 style="margin:0 0 8px 0">403</h2><div class="small">Admin only.</div></div>');
        exit;
    }
}

if ($uri === '/login' && $method === 'GET') {
    $msg = '';
    if (is_string($flash) && $flash !== '') {
        $msg = '<div class="error">' . h($flash) . '</div>';
    }

    $content = '<div class="topbar"><div class="brand">Twilio Platform</div></div>';
    $content .= '<div class="card"><h2 style="margin:0 0 12px 0">Login</h2>';
    $content .= $msg;
    $content .= '<form method="post" action="/login">';
    $content .= '<div class="row" style="flex-direction:column">';
    $content .= '<input class="input" name="email" type="email" placeholder="Email" required>'; 
    $content .= '<input class="input" name="password" type="password" placeholder="Password" required>';
    $content .= '</div><div style="height:12px"></div>';
    $content .= '<button class="btn primary" type="submit">Sign in</button> '; 
    $content .= '<a class="btn" href="/register">Create account</a>';
    $content .= '</form></div>';

    render('Login', $content);
    exit;
}

if ($uri === '/login' && $method === 'POST') {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!rateLimitCheck($rootDir, 'login:' . $ip, 10, 900)) {
        $_SESSION['_flash'] = 'Too many login attempts. Try again in a few minutes.';
        redirect('/login');
    }
    $pdo = getPdo($rootDir);
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $_SESSION['_flash'] = 'Email and password are required.';
        redirect('/login');
    }

    if (!Auth::attempt($pdo, $email, $password)) {
        $_SESSION['_flash'] = 'Invalid credentials.';
        redirect('/login');
    }

    redirect('/app');
}

if ($uri === '/install' && $method === 'GET') {
    if (installed($rootDir)) {
        redirect('/login');
    }

    $step = (int) ($_GET['step'] ?? 1);
    if ($step < 1) {
        $step = 1;
    }
    if ($step > 3) {
        $step = 3;
    }

    $content = '<div class="topbar"><div class="brand">Twilio Platform</div></div>';
    $content .= '<div class="card"><h2 style="margin:0 0 12px 0">Install</h2>';
    if (is_string($flash) && $flash !== '') {
        $content .= '<div class="error">' . h($flash) . '</div>';
    }

    if ($step === 1) {
        $checks = [];
        $checks[] = ['PHP 8+', PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>=')];
        $checks[] = ['PDO MySQL', extension_loaded('pdo_mysql') ? 'enabled' : 'missing', extension_loaded('pdo_mysql')];
        $checks[] = ['OpenSSL', extension_loaded('openssl') ? 'enabled' : 'missing', extension_loaded('openssl')];
        $checks[] = ['storage/ writable', is_writable($rootDir . '/storage') ? 'writable' : 'not writable', is_writable($rootDir . '/storage')];
        $checks[] = ['project root writable (.env)', is_writable($rootDir) ? 'writable' : 'not writable', is_writable($rootDir)];

        $content .= '<div class="small">Step 1 of 3: Server checks</div><div style="height:10px"></div>';
        $content .= '<div class="row" style="flex-direction:column;gap:8px">';
        foreach ($checks as $c) {
            [$label, $val, $ok] = $c;
            $content .= '<div class="row" style="justify-content:space-between"><div>' . h((string) $label) . '</div><div class="small">' . h((string) $val) . ' ' . ($ok ? 'OK' : 'FAIL') . '</div></div>';
        }
        $content .= '</div><div style="height:14px"></div>';
        $content .= '<a class="btn primary" href="/install?step=2">Continue</a>';
        $content .= '</div>';
        render('Install', $content);
        exit;
    }

    if ($step === 2) {
        $content .= '<div class="small">Step 2 of 3: Database + Base URL</div><div style="height:10px"></div>';
        $content .= '<form method="post" action="/install?step=2">';
        $content .= '<div class="row" style="flex-direction:column">';
        $content .= '<input class="input" name="db_host" placeholder="DB Host" value="localhost" required>';
        $content .= '<input class="input" name="db_port" placeholder="DB Port" value="3306" required>';
        $content .= '<input class="input" name="db_database" placeholder="DB Name" required>';
        $content .= '<input class="input" name="db_username" placeholder="DB Username" required>';
        $content .= '<input class="input" name="db_password" placeholder="DB Password" type="password">';
        $guess = baseUrl();
        $content .= '<input class="input" name="base_url" placeholder="Base URL (https://your-domain)" value="' . h($guess) . '" required>';
        $content .= '<label class="small" style="display:flex;align-items:center;gap:8px;margin-top:6px">'
            . '<input type="checkbox" name="validate_webhook" value="1">'
            . ' Enable Twilio webhook validation (recommended)'
            . ' <button type="button" class="btn" style="padding:0 8px;min-width:auto;height:22px;line-height:20px" onclick="var e=document.getElementById(\'twilioWebhookHelp\'); if(e){ e.style.display = (e.style.display===\'none\' || e.style.display===\'\') ? \'block\' : \'none\'; }">i</button>'
            . '</label>';
        $content .= '<div id="twilioWebhookHelp" class="small" style="display:none;margin-top:6px">'
            . 'When enabled, the app verifies Twilio webhook signatures (X-Twilio-Signature) to ensure inbound webhooks really came from Twilio. '
            . 'Recommended for production. If misconfigured (wrong Base URL, proxy URL rewriting), webhooks may be rejected.'
            . '</div>';
        $content .= '</div><div style="height:12px"></div>';
        $content .= '<button class="btn primary" type="submit">Save & Continue</button> ';
        $content .= '<a class="btn" href="/install?step=1">Back</a>';
        $content .= '</form></div>';
        render('Install', $content);
        exit;
    }

    $content .= '<div class="small">Step 3 of 3: Create admin user</div><div style="height:10px"></div>';
    $content .= '<form method="post" action="/install?step=3">';
    $content .= '<div class="row" style="flex-direction:column">';
    $content .= '<input class="input" name="email" type="email" placeholder="Admin email" required>';
    $content .= '<input class="input" name="password" type="password" placeholder="Admin password (min 8 chars)" minlength="8" required>';
    $content .= '</div><div style="height:12px"></div>';
    $content .= '<button class="btn primary" type="submit">Finish Install</button> ';
    $content .= '<a class="btn" href="/install?step=2">Back</a>';
    $content .= '</form></div>';
    render('Install', $content);
    exit;
}

if ($uri === '/install' && $method === 'POST') {
    if (installed($rootDir)) {
        redirect('/login');
    }
    $step = (int) ($_GET['step'] ?? 1);

    if ($step === 2) {
        $dbHost = trim((string) ($_POST['db_host'] ?? ''));
        $dbPort = trim((string) ($_POST['db_port'] ?? ''));
        $dbName = trim((string) ($_POST['db_database'] ?? ''));
        $dbUser = trim((string) ($_POST['db_username'] ?? ''));
        $dbPass = (string) ($_POST['db_password'] ?? '');
        $baseUrl = trim((string) ($_POST['base_url'] ?? ''));
        $baseUrl = preg_replace('/\s+/', '', $baseUrl);
        if ($baseUrl !== '' && !preg_match('/^https?:\/\//i', $baseUrl)) {
            $baseUrl = 'https://' . ltrim($baseUrl, '/');
        }
        $parsed = parse_url($baseUrl);
        if (is_array($parsed) && isset($parsed['host'])) {
            $scheme = (string) (($parsed['scheme'] ?? '') ?: 'https');
            $host = (string) $parsed['host'];
            if (isset($parsed['port'])) {
                $host .= ':' . (string) $parsed['port'];
            }
            $path = rtrim((string) ($parsed['path'] ?? ''), '/');
            if ($path === '/public' || str_ends_with($path, '/public')) {
                $path = preg_replace('/\/public$/', '', $path);
            }
            $baseUrl = rtrim($scheme . '://' . $host . $path, '/');
        } else {
            $baseUrl = rtrim($baseUrl, '/');
            if ($baseUrl === 'https://') {
                $baseUrl = '';
            }
        }

        if ($dbHost === '' || $dbPort === '' || $dbName === '' || $dbUser === '' || $baseUrl === '') {
            $_SESSION['_flash'] = 'All fields except DB password are required.';
            redirect('/install?step=2');
        }

        try {
            $dsn = 'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4';
            $test = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            Db::ensureSchema($test);
        } catch (\Throwable $e) {
            $_SESSION['_flash'] = 'Database connection failed (' . $dbHost . ':' . $dbPort . '/' . $dbName . '): ' . $e->getMessage();
            redirect('/install?step=2');
        }

        try {
            $appKey = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $appKey = bin2hex(openssl_random_pseudo_bytes(16));
        }

        $validateWebhook = !empty($_POST['validate_webhook']) ? '1' : '0';

        try {
            writeEnvFile($rootDir, [
                'APP_ENV' => 'production',
                'APP_KEY' => $appKey,
                'APP_INSTALLED' => '0',
                'ALLOW_REGISTER' => '0',
                'DB_HOST' => $dbHost,
                'DB_PORT' => $dbPort,
                'DB_DATABASE' => $dbName,
                'DB_USERNAME' => $dbUser,
                'DB_PASSWORD' => $dbPass,
                'TWILIO_VALIDATE_WEBHOOK' => $validateWebhook,
                'BASE_URL' => $baseUrl,
            ]);
            Config::reload($rootDir);
        } catch (\Throwable $e) {
            $_SESSION['_flash'] = $e->getMessage();
            redirect('/install?step=2');
        }

        $_SESSION['_flash'] = 'Saved. Next: create admin user.';
        redirect('/install?step=3');
    }

    if ($step === 3) {
        try {
            Config::load($rootDir);
            $pdo = getPdo($rootDir);
        } catch (\Throwable $e) {
            $_SESSION['_flash'] = 'Database is not configured yet.';
            redirect('/install?step=2');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($email === '' || strlen($password) < 8) {
            $_SESSION['_flash'] = 'Enter a valid email and a password of at least 8 characters.';
            redirect('/install?step=3');
        }

        try {
            $existing = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch();
            if ($existing) {
                $_SESSION['_flash'] = 'Admin already exists. If you want to reinstall, delete storage/installed.lock.';
                redirect('/login');
            }
        } catch (\Throwable $e) {
        }

        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, role) VALUES (:e, :h, 'admin')");
            $stmt->execute([':e' => $email, ':h' => $hash]);
        } catch (\Throwable $e) {
            $_SESSION['_flash'] = 'Could not create admin (email may already exist).';
            redirect('/install?step=3');
        }

        @file_put_contents($rootDir . '/storage/installed.lock', date('c') . "\n");
        $_SESSION['_flash'] = 'Installed. Please sign in.';
        redirect('/login');
    }

    redirect('/install');
}

if ($uri === '/register' && $method === 'GET') {
    $allow = Config::get('ALLOW_REGISTER', (Config::get('APP_ENV', 'local') === 'local') ? '1' : '0');
    if ($allow !== '1') {
        $_SESSION['_flash'] = 'Registration is disabled.';
        redirect('/login');
    }
    $msg = '';
    if (is_string($flash) && $flash !== '') {
        $msg = '<div class="error">' . h($flash) . '</div>';
    }

    $content = '<div class="topbar"><div class="brand">Twilio Platform</div></div>';
    $content .= '<div class="card"><h2 style="margin:0 0 12px 0">Create account</h2>';
    $content .= $msg;
    $content .= '<form method="post" action="/register">';
    $content .= '<div class="row" style="flex-direction:column">';
    $content .= '<input class="input" name="email" type="email" placeholder="Email" required>';
    $content .= '<input class="input" name="password" type="password" placeholder="Password (min 8 chars)" minlength="8" required>';
    $content .= '</div><div style="height:12px"></div>';
    $content .= '<button class="btn primary" type="submit">Create</button> '; 
    $content .= '<a class="btn" href="/login">Back to login</a>';
    $content .= '</form></div>';

    render('Register', $content);
    exit;
}

if ($uri === '/register' && $method === 'POST') {
    $allow = Config::get('ALLOW_REGISTER', (Config::get('APP_ENV', 'local') === 'local') ? '1' : '0');
    if ($allow !== '1') {
        $_SESSION['_flash'] = 'Registration is disabled.';
        redirect('/login');
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!rateLimitCheck($rootDir, 'register:' . $ip, 5, 900)) {
        $_SESSION['_flash'] = 'Too many registration attempts. Try again in a few minutes.';
        redirect('/register');
    }
    $pdo = getPdo($rootDir);
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || strlen($password) < 8) {
        $_SESSION['_flash'] = 'Enter a valid email and a password of at least 8 characters.';
        redirect('/register');
    }

    if (!Auth::register($pdo, $email, $password)) {
        $_SESSION['_flash'] = 'Could not create account (email may already exist).';
        redirect('/register');
    }

    redirect('/app');
}

if ($uri === '/logout' && $method === 'POST') {
    Auth::logout();
    redirect('/login');
}

if ($uri === '/app' && $method === 'GET') {
    Auth::requireLogin();

    $content = '<div class="appShell">';
    $content .= '<header class="appTop">';
    $content .= '<div class="brand"><img class="brandLogo brandLogoDark" src="/assets/img/logo-dark.svg" alt="Logo"><img class="brandLogo brandLogoLight" src="/assets/img/logo-light.svg" alt="Logo">Web - Twilio</div>';
    $content .= '<div class="topActions">';
    $content .= '<button class="btn" type="button" id="themeToggle">Theme</button>';
    $content .= '<button class="btn" type="button" id="rightToggle">Contact</button>';
    $content .= '<form method="post" action="/logout" style="margin:0"><button class="btn danger" type="submit">Logout</button></form>';
    $content .= '</div>';
    $content .= '</header>';

    $content .= '<main class="dashboard">';

    $content .= '<aside class="nav">';
    $content .= '<div class="navTitle">Menu</div>';
    $content .= '<a class="navItem active" href="#inbox" id="navInbox">Inbox</a>';
    $content .= '<a class="navItem" href="#dialpad" id="navDialpad">Dial Pad</a>';
    $content .= '<a class="navItem" href="#calls" id="navCalls">Calls</a>';
    $content .= '<a class="navItem" href="#contacts" id="navContacts">Contacts</a>';
    $content .= '<a class="navItem" href="#numbers" id="navNumbers">Numbers</a>';
    $content .= '<a class="navItem" href="#settings" id="navSettings">Settings</a>';
    $content .= '<a class="navItem" href="#users" id="navUsers">Users</a>';
    $content .= '<div class="navSpacer"></div>';
    $content .= '</aside>';

    $content .= '<section class="view" id="viewInbox">';
    $content .= '<div class="inboxGrid">';

    $content .= '<aside class="sidebar">';
    $content .= '<div class="panelHeader">';
    $content .= '<div class="panelTitle">Inbox</div>';
    $content .= '<div class="small" id="convCount"></div>';
    $content .= '</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="searchInput" placeholder="Search contacts or numbers">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="filterAll">All</button>';
    $content .= '<button class="btn" type="button" id="filterMe">Assigned to me</button>';
    $content .= '<button class="btn primary" type="button" id="newMessageBtn">New message</button>';
    $content .= '</div>';
    $content .= '<div class="card" id="newMessagePanel" style="display:none;margin-top:12px">';
    $content .= '<div class="small" style="margin-bottom:10px">Send new message</div>';
    $content .= '<input class="input" id="newMessageTo" placeholder="To phone number (+1...)">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<select class="input" id="newMessageFrom"></select>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn primary" type="button" id="newMessageStart">Start</button>';
    $content .= '<button class="btn" type="button" id="newMessageCancel">Cancel</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="conversationList"></div>';
    $content .= '</aside>';

    $content .= '<section class="chat">';
    $content .= '<div class="chatHeader">';
    $content .= '<div>';
    $content .= '<div class="chatTitle" id="chatTitle">Select a conversation</div>';
    $content .= '<div class="small" id="chatSub"></div>';
    $content .= '</div>';
    $content .= '<div class="chatMeta">';
    $content .= '<div class="small">Voice: <span id="voiceStatus">Loading...</span></div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="incomingBar" id="incomingBox" style="display:none">';
    $content .= '<div class="small">Incoming from <span id="incomingFrom"></span></div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn primary" type="button" id="incomingAccept">Answer</button>';
    $content .= '<button class="btn danger" type="button" id="incomingReject">Reject</button>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="messages" id="messageList"></div>';

    $content .= '<div class="composer">';
    $content .= '<div class="row" style="align-items:center">';
    $content .= '<select class="input" id="fromNumberSelect" style="max-width:260px"></select>';
    $content .= '</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="row" style="align-items:flex-end">';
    $content .= '<textarea class="input" id="messageBody" placeholder="Write a message" rows="2" style="resize:none;flex:1"></textarea>';
    $content .= '<button class="btn primary" type="button" id="sendBtn">Send</button>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '</section>';

    $content .= '<aside class="rightPanel" id="rightPanel">';
    $content .= '<div class="panelHeader">';
    $content .= '<div class="panelTitle">Contact</div>';
    $content .= '<button class="btn" type="button" id="rightClose">Close</button>';
    $content .= '</div>';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small">Name</div>';
    $content .= '<input class="input" id="contactName" placeholder="Contact name">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small">Phone</div>';
    $content .= '<div id="contactPhone" class="small" style="margin-top:6px"></div>';
    $content .= '<div class="small" id="conversationToNumber" style="margin-top:8px"></div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<button class="btn" type="button" id="saveContact">Save</button>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small">Assigned user</div>';
    $content .= '<select class="input" id="assignedUserSelect"></select>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="assignMe">Assign to me</button>';
    $content .= '<button class="btn" type="button" id="unassign">Unassign</button>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small">Notes</div>';
    $content .= '<div class="notes" id="notesList" style="margin-top:10px"></div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<textarea class="input" id="noteBody" placeholder="Add a note" rows="2" style="resize:none"></textarea>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<button class="btn primary" type="button" id="addNote">Add note</button>';
    $content .= '</div>';

    $content .= '</aside>';

    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="view" id="viewCalls" style="display:none">';
    $content .= '<div class="pageHeader"><div class="pageTitle">Calls</div><div class="small">Call logs</div></div>';
    $content .= '<div class="card">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Latest calls</div>';
    $content .= '<button class="btn" type="button" id="refreshCalls">Refresh</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="callsList"></div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="view" id="viewDialpad" style="display:none">';
    $content .= '<div class="pageHeader"><div class="pageTitle">Dial Pad</div><div class="small">Make calls from the browser</div></div>';
    $content .= '<div class="pageGrid">';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:8px">Voice status: <span id="voiceStatus2">Loading...</span></div>';
    $content .= '<select class="input" id="dialFromNumberSelect" style="max-width:260px"></select>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="dialInput" placeholder="+1..." inputmode="tel">';
    $content .= '<div class="dial">';
    foreach (["1","2","3","4","5","6","7","8","9","*","0","#"] as $k) {
        $content .= '<button class="key" type="button" data-k="' . h($k) . '">' . h($k) . '</button>';
    }
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn primary" type="button" id="callBtn">Call</button>';
    $content .= '<button class="btn" type="button" id="clearBtn">Clear</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div class="card">';
    $content .= '<div class="small">Tip</div>';
    $content .= '<div style="margin-top:8px">Check Settings for webhook URLs and configuration.</div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="view" id="viewSettings" style="display:none">';
    $content .= '<div class="pageHeader"><div class="pageTitle">Settings</div><div class="small">Migrations and configuration</div></div>';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:10px">Database</div>';
    $content .= '<a class="btn" href="/migrate" target="_blank">Run migration</a>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Webhooks</div>';
    $content .= '<button class="btn" type="button" id="showWebhooksInfo">i</button>';
    $content .= '</div>';
    $content .= '<div class="small" style="margin-top:10px">SMS: ' . h(baseUrl()) . '/webhooks/twilio/sms</div>';
    $content .= '<div class="small" style="margin-top:6px">Voice: ' . h(baseUrl()) . '/webhooks/twilio/voice</div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Default Twilio profile</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="refreshDefaultTwilio">Refresh</button>';
    $content .= '<button class="btn primary" type="button" id="saveDefaultTwilio">Save</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<select class="input" id="defaultTwilioAccount"></select>';
    $content .= '<div class="small" style="margin-top:10px">Used when a number does not have an account profile selected.</div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Twilio Accounts (credential profiles)</div>';
    $content .= '<button class="btn" type="button" id="refreshTwilioAccounts">Refresh</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="pageGrid">';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:10px">Add / update profile</div>';
    $content .= '<input class="input" id="taName" placeholder="Profile name (unique)">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="taAccountSid" placeholder="Account SID">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="taAuthToken" placeholder="Auth Token">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="taApiKey" placeholder="API Key (optional)">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="taApiSecret" placeholder="API Secret (optional)">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="taTwimlAppSid" placeholder="TwiML App SID (optional)">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="taDefaultFrom" placeholder="Default From Number (optional)">';
    $content .= '<div style="height:12px"></div>';
    $content .= '<button class="btn primary" type="button" id="addTwilioAccountBtn">Save profile</button>';
    $content .= '</div>';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:10px">Saved profiles</div>';
    $content .= '<div class="list" id="twilioAccountsList"></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Voice routing (inbound fallback)</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="refreshVoiceRouting">Refresh</button>';
    $content .= '<button class="btn primary" type="button" id="saveVoiceRouting">Save</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="pageGrid">';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:10px">Ring agents</div>';
    $content .= '<input class="input" id="voiceRingTimeout" placeholder="Ring timeout seconds (5-60)">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="voiceForwardNumber" placeholder="Forward to number (optional)">';
    $content .= '<div class="small" style="margin-top:10px">If set, calls will forward after timeout/no-answer.</div>';
    $content .= '</div>';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:10px">Voicemail</div>';
    $content .= '<label class="small" style="display:block;margin-bottom:10px"><input type="checkbox" id="voiceVoicemailEnabled"> Enable voicemail when not forwarded</label>';
    $content .= '<label class="small" style="display:block;margin-bottom:10px"><input type="checkbox" id="voiceRecordCalls"> Record calls (store recording URL)</label>';
    $content .= '<textarea class="input" id="voiceVoicemailGreeting" placeholder="Voicemail greeting" rows="2" style="resize:none"></textarea>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="voiceVoicemailMax" placeholder="Voicemail max length seconds (10-300)">';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Voicemails</div>';
    $content .= '<button class="btn" type="button" id="refreshVoicemails">Refresh</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="voicemailsList"></div>';
    $content .= '</div>';

    $content .= '</section>';

    $content .= '<section class="view" id="viewContacts" style="display:none">';
    $content .= '<div class="pageHeader"><div class="pageTitle">Contacts</div><div class="small">Search and edit contacts</div></div>';
    $content .= '<div class="card">';
    $content .= '<input class="input" id="contactsSearch" placeholder="Search name or number">';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="contactsList"></div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="view" id="viewNumbers" style="display:none">';
    $content .= '<div class="pageHeader"><div class="pageTitle">Numbers</div><div class="small">Admin: manage and assign numbers</div></div>';
    $content .= '<div class="pageGrid">';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:10px">Add number</div>';
    $content .= '<input class="input" id="newNumber" placeholder="+1...">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="newNumberName" placeholder="Friendly name (optional)">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<button class="btn primary" type="button" id="addNumberBtn">Add</button>';
    $content .= '</div>';
    $content .= '<div class="card">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Numbers & assignments</div>';
    $content .= '<button class="btn" type="button" id="refreshNumbers">Refresh</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="numbersList"></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="view" id="viewUsers" style="display:none">';
    $content .= '<div class="pageHeader"><div class="pageTitle">User Manager</div><div class="small">Admin only</div></div>';
    $content .= '<div class="pageGrid">';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:10px">Create user</div>';
    $content .= '<input class="input" id="newUserEmail" placeholder="Email">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="newUserPassword" placeholder="Temp password" type="password">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<select class="input" id="newUserRole"><option value="agent">agent</option><option value="admin">admin</option></select>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<button class="btn primary" type="button" id="createUserBtn">Create</button>';
    $content .= '</div>';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:10px">Users</div>';
    $content .= '<div class="list" id="usersList"></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</section>';
    $content .= '</main>';
    $content .= '</div>';

    $content .= '<div class="callBar" id="callBar" style="display:none">';
    $content .= '<div>';
    $content .= '<div class="callTitle" id="callBarStatus">Call</div>';
    $content .= '<div class="small">Duration: <span id="callBarTimer">00:00</span></div>';
    $content .= '</div>';
    $content .= '<div class="row" style="justify-content:flex-end">';
    $content .= '<button class="btn" type="button" id="recordBtn">Record</button>';
    $content .= '<button class="btn" type="button" id="muteBtn">Mute</button>';
    $content .= '<button class="btn danger" type="button" id="hangupBtn">Hang up</button>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="incomingModal" id="incomingModal" style="display:none">';
    $content .= '<div class="incomingCard">';
    $content .= '<div class="incomingTitle">Incoming call</div>';
    $content .= '<div class="small" style="margin-top:6px">From <span id="incomingFromModal"></span></div>';
    $content .= '<div class="row" style="margin-top:14px">';
    $content .= '<button class="btn primary" type="button" id="incomingAcceptModal">Answer</button>';
    $content .= '<button class="btn danger" type="button" id="incomingRejectModal">Reject</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<script src="/app.js"></script>';

    render('Dashboard', $content);
    exit;
}

if ($uri === '/migrate' && $method === 'GET') {
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);
    try {
        Db::ensureSchema($pdo);
    } catch (\Throwable $e) {
        render('Migrate', '<div class="topbar"><div class="brand">Migration</div></div><div class="error">' . h($e->getMessage()) . '</div>');
        exit;
    }
    render('Migrate', '<div class="topbar"><div class="brand">Migration</div></div><div class="ok">Migration complete.</div>');
    exit;
}

if ($uri === '/api/voice/token' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);

    $uid = Auth::userId();
    if ($uid === null) {
        json(['error' => 'Not authenticated'], 401);
    }

    $cfg = twilioConfig($pdo, $uid);
    $accountSid = (string) ($cfg['account_sid'] ?? '');
    $apiKey = (string) ($cfg['api_key'] ?? '');
    $apiSecret = (string) ($cfg['api_secret'] ?? '');
    $appSid = (string) ($cfg['twiml_app_sid'] ?? '');

    if ($accountSid === '' || $apiKey === '' || $apiSecret === '' || $appSid === '') {
        json(['error' => 'Missing Twilio Voice credentials. Configure a Twilio profile (API Key/Secret + TwiML App SID) and set a default profile in Settings.'], 500);
    }

    $uStmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
    $uStmt->execute([':id' => $uid]);
    $email = (string) (($uStmt->fetch()['email'] ?? '') ?: '');
    $identity = 'user_' . $uid;
    if ($email !== '') {
        $identity .= '_' . preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $email);
    }

    $token = new AccessToken($accountSid, $apiKey, $apiSecret, 3600, $identity);
    $grant = new VoiceGrant();
    $grant->setOutgoingApplicationSid($appSid);
    $grant->setIncomingAllow(true);
    $token->addGrant($grant);
    json(['token' => $token->toJWT(), 'identity' => $identity]);
}

if ($uri === '/api/calls' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);

    $limit = (int) ($_GET['limit'] ?? 50);
    if ($limit < 1) {
        $limit = 50;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    $stmt = $pdo->prepare('SELECT c.*, u.email AS user_email
        FROM calls c
        LEFT JOIN users u ON u.id = c.user_id
        ORDER BY c.created_at DESC
        LIMIT ' . $limit);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    json(['calls' => $rows]);
}

if ($uri === '/api/admin/numbers' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $stmt = $pdo->query('SELECT n.id, n.phone_number, n.friendly_name, n.twilio_account_id, n.voice_forward_number, n.voice_ring_timeout
        FROM numbers n
        ORDER BY n.phone_number ASC');
    $numbers = $stmt->fetchAll();

    $mapStmt = $pdo->query('SELECT un.user_id, un.number_id, un.is_default, u.email
        FROM user_numbers un
        INNER JOIN users u ON u.id = un.user_id
        ORDER BY u.email ASC');
    $mappings = $mapStmt->fetchAll();

    json(['numbers' => $numbers, 'mappings' => $mappings]);
}

if ($uri === '/api/admin/numbers/add' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $payload = json_decode((string) file_get_contents('php://input'), true);
    $pn = trim((string) ($payload['phone_number'] ?? ''));
    $name = trim((string) ($payload['friendly_name'] ?? ''));
    if ($pn === '') {
        json(['error' => 'phone_number is required'], 422);
    }

    $pdo->prepare('INSERT IGNORE INTO numbers (phone_number, friendly_name) VALUES (:pn, :fn)')
        ->execute([':pn' => $pn, ':fn' => ($name !== '' ? $name : null)]);
    json(['ok' => true]);
}

if ($uri === '/api/admin/numbers/update' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        json(['error' => 'Invalid JSON'], 400);
    }
    $id = (int) ($payload['id'] ?? 0);
    if ($id <= 0) {
        json(['error' => 'id is required'], 422);
    }

    $updates = [];
    $params = [':id' => $id];

    if (array_key_exists('friendly_name', $payload)) {
        $name = trim((string) ($payload['friendly_name'] ?? ''));
        $updates[] = 'friendly_name = :fn';
        $params[':fn'] = ($name !== '' ? $name : null);
    }

    $twilioAccountId = null;
    if (array_key_exists('twilio_account_id', $payload)) {
        $twilioAccountId = (int) ($payload['twilio_account_id'] ?? 0);
        if ($twilioAccountId <= 0) {
            $twilioAccountId = null;
        }
    }

    $voiceForward = null;
    if (array_key_exists('voice_forward_number', $payload)) {
        $vf = trim((string) ($payload['voice_forward_number'] ?? ''));
        $voiceForward = ($vf !== '' ? $vf : null);
    }
    $voiceRingTimeout = null;
    if (array_key_exists('voice_ring_timeout', $payload)) {
        $vrt = (int) ($payload['voice_ring_timeout'] ?? 0);
        if ($vrt <= 0) {
            $voiceRingTimeout = null;
        } else {
            if ($vrt < 5) {
                $vrt = 5;
            }
            if ($vrt > 60) {
                $vrt = 60;
            }
            $voiceRingTimeout = $vrt;
        }
    }

    if (array_key_exists('twilio_account_id', $payload)) {
        $updates[] = 'twilio_account_id = :tid';
        $params[':tid'] = $twilioAccountId;
    }
    if (count($updates) === 0) {
        json(['ok' => true]);
    }
    $sql = 'UPDATE numbers SET ' . implode(', ', $updates) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);
    json(['ok' => true]);
}

if ($uri === '/api/admin/numbers/save' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        json(['error' => 'Invalid JSON'], 400);
    }

    $id = (int) ($payload['id'] ?? 0);
    if ($id <= 0) {
        json(['error' => 'id is required'], 422);
    }

    $friendly = null;
    if (array_key_exists('friendly_name', $payload)) {
        $name = trim((string) ($payload['friendly_name'] ?? ''));
        $friendly = ($name !== '' ? $name : null);
    }

    $twilioAccountId = null;
    if (array_key_exists('twilio_account_id', $payload)) {
        $twilioAccountId = (int) ($payload['twilio_account_id'] ?? 0);
        if ($twilioAccountId <= 0) {
            $twilioAccountId = null;
        }
    }

    $userIds = $payload['user_ids'] ?? [];
    if (!is_array($userIds)) {
        $userIds = [];
    }
    $cleanUserIds = [];
    foreach ($userIds as $uid) {
        $u = (int) $uid;
        if ($u > 0) {
            $cleanUserIds[] = $u;
        }
    }
    $cleanUserIds = array_values(array_unique($cleanUserIds));

    $defaultUserId = (int) ($payload['default_user_id'] ?? 0);
    if ($defaultUserId > 0 && !in_array($defaultUserId, $cleanUserIds, true)) {
        $defaultUserId = 0;
    }

    try {
        $pdo->beginTransaction();

        $updates = [];
        $params = [':id' => $id];
        if (array_key_exists('friendly_name', $payload)) {
            $updates[] = 'friendly_name = :fn';
            $params[':fn'] = $friendly;
        }
        if (array_key_exists('twilio_account_id', $payload)) {
            $updates[] = 'twilio_account_id = :tid';
            $params[':tid'] = $twilioAccountId;
        }
        if (array_key_exists('voice_forward_number', $payload)) {
            $updates[] = 'voice_forward_number = :vfn';
            $params[':vfn'] = $voiceForward;
        }
        if (array_key_exists('voice_ring_timeout', $payload)) {
            $updates[] = 'voice_ring_timeout = :vrt';
            $params[':vrt'] = $voiceRingTimeout;
        }
        if (count($updates) > 0) {
            $pdo->prepare('UPDATE numbers SET ' . implode(', ', $updates) . ' WHERE id = :id')->execute($params);
        }

        if (array_key_exists('user_ids', $payload)) {
            $pdo->prepare('DELETE FROM user_numbers WHERE number_id = :nid')->execute([':nid' => $id]);
            if (count($cleanUserIds) > 0) {
                $ins = $pdo->prepare('INSERT INTO user_numbers (user_id, number_id, is_default) VALUES (:uid, :nid, :def)');
                foreach ($cleanUserIds as $uid) {
                    $ins->execute([
                        ':uid' => $uid,
                        ':nid' => $id,
                        ':def' => ($defaultUserId > 0 && $uid === $defaultUserId) ? 1 : 0,
                    ]);
                }
            }
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        try { $pdo->rollBack(); } catch (\Throwable $e2) {}
        json(['error' => 'Save failed'], 500);
    }

    json(['ok' => true]);
}

if ($uri === '/api/admin/twilio-accounts' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $stmt = $pdo->query('SELECT id, name, account_sid, twiml_app_sid, default_from_number, created_at
        FROM twilio_accounts
        ORDER BY name ASC');
    json(['accounts' => $stmt->fetchAll()]);
}

if ($uri === '/api/admin/settings/default-twilio' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    json([
        'default_twilio_account_id' => (int) appSettingGet($pdo, 'default_twilio_account_id', '0'),
    ]);
}

if ($uri === '/api/admin/settings/default-twilio' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        json(['error' => 'Invalid JSON'], 400);
    }

    $id = (int) ($payload['default_twilio_account_id'] ?? 0);
    if ($id < 0) {
        $id = 0;
    }
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT id FROM twilio_accounts WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) {
            json(['error' => 'Unknown twilio account id'], 422);
        }
    }

    appSettingSet($pdo, 'default_twilio_account_id', (string) $id);
    json(['ok' => true]);
}

if ($uri === '/api/admin/twilio-accounts/add' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $payload = json_decode((string) file_get_contents('php://input'), true);
    $name = trim((string) ($payload['name'] ?? ''));
    $sid = trim((string) ($payload['account_sid'] ?? ''));
    $token = trim((string) ($payload['auth_token'] ?? ''));
    $apiKey = trim((string) ($payload['api_key'] ?? ''));
    $apiSecret = trim((string) ($payload['api_secret'] ?? ''));
    $appSid = trim((string) ($payload['twiml_app_sid'] ?? ''));
    $from = trim((string) ($payload['default_from_number'] ?? ''));

    if ($name === '' || $sid === '' || $token === '') {
        json(['error' => 'name, account_sid, auth_token are required'], 422);
    }

    $pdo->prepare('INSERT INTO twilio_accounts (name, account_sid, auth_token, api_key, api_secret, twiml_app_sid, default_from_number)
        VALUES (:n, :sid, :tok, :k, :s, :app, :from)
        ON DUPLICATE KEY UPDATE account_sid = VALUES(account_sid), auth_token = VALUES(auth_token), api_key = VALUES(api_key), api_secret = VALUES(api_secret), twiml_app_sid = VALUES(twiml_app_sid), default_from_number = VALUES(default_from_number)')
        ->execute([
            ':n' => $name,
            ':sid' => $sid,
            ':tok' => $token,
            ':k' => ($apiKey !== '' ? $apiKey : null),
            ':s' => ($apiSecret !== '' ? $apiSecret : null),
            ':app' => ($appSid !== '' ? $appSid : null),
            ':from' => ($from !== '' ? $from : null),
        ]);

    json(['ok' => true]);
}

if ($uri === '/api/admin/twilio-accounts/delete' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $payload = json_decode((string) file_get_contents('php://input'), true);
    $id = (int) ($payload['id'] ?? 0);
    if ($id <= 0) {
        json(['error' => 'id is required'], 422);
    }

    $pdo->prepare('DELETE FROM twilio_accounts WHERE id = :id')->execute([':id' => $id]);
    json(['ok' => true]);
}

if ($uri === '/api/admin/settings/voice-routing' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    json([
        'voice_ring_timeout' => (int) appSettingGet($pdo, 'voice_ring_timeout', '20'),
        'voice_forward_number' => appSettingGet($pdo, 'voice_forward_number', ''),
        'voice_voicemail_enabled' => appSettingGet($pdo, 'voice_voicemail_enabled', '0') === '1',
        'voice_voicemail_greeting' => appSettingGet($pdo, 'voice_voicemail_greeting', 'Please leave a message after the tone.'),
        'voice_voicemail_max_length' => (int) appSettingGet($pdo, 'voice_voicemail_max_length', '60'),
        'voice_record_calls' => appSettingGet($pdo, 'voice_record_calls', '0') === '1',
    ]);
}

if ($uri === '/api/admin/settings/voice-routing' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        json(['error' => 'Invalid JSON'], 400);
    }

    $timeout = (int) ($payload['voice_ring_timeout'] ?? 20);
    if ($timeout < 5) {
        $timeout = 5;
    }
    if ($timeout > 60) {
        $timeout = 60;
    }

    $forward = trim((string) ($payload['voice_forward_number'] ?? ''));
    $vmEnabled = !empty($payload['voice_voicemail_enabled']) ? '1' : '0';
    $greeting = trim((string) ($payload['voice_voicemail_greeting'] ?? ''));
    if ($greeting === '') {
        $greeting = 'Please leave a message after the tone.';
    }
    $vmMax = (int) ($payload['voice_voicemail_max_length'] ?? 60);
    if ($vmMax < 10) {
        $vmMax = 10;
    }
    if ($vmMax > 300) {
        $vmMax = 300;
    }

    $recordCalls = !empty($payload['voice_record_calls']) ? '1' : '0';

    appSettingSet($pdo, 'voice_ring_timeout', (string) $timeout);
    appSettingSet($pdo, 'voice_forward_number', $forward);
    appSettingSet($pdo, 'voice_voicemail_enabled', $vmEnabled);
    appSettingSet($pdo, 'voice_voicemail_greeting', $greeting);
    appSettingSet($pdo, 'voice_voicemail_max_length', (string) $vmMax);
    appSettingSet($pdo, 'voice_record_calls', $recordCalls);

    json(['ok' => true]);
}

if ($uri === '/api/admin/voicemails' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $limit = (int) ($_GET['limit'] ?? 50);
    if ($limit < 1) {
        $limit = 50;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    $stmt = $pdo->prepare('SELECT id, call_sid, from_number, to_number, recording_url, recording_duration, created_at
        FROM voicemails
        ORDER BY created_at DESC
        LIMIT ' . $limit);
    $stmt->execute();
    json(['voicemails' => $stmt->fetchAll()]);
}

if ($uri === '/api/admin/numbers/assign' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $payload = json_decode((string) file_get_contents('php://input'), true);
    $userId = (int) ($payload['user_id'] ?? 0);
    $numberId = (int) ($payload['number_id'] ?? 0);
    if ($userId <= 0 || $numberId <= 0) {
        json(['error' => 'user_id and number_id required'], 422);
    }

    $pdo->prepare('INSERT IGNORE INTO user_numbers (user_id, number_id, is_default) VALUES (:uid, :nid, 0)')
        ->execute([':uid' => $userId, ':nid' => $numberId]);
    json(['ok' => true]);
}

if ($uri === '/api/admin/numbers/unassign' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $payload = json_decode((string) file_get_contents('php://input'), true);
    $userId = (int) ($payload['user_id'] ?? 0);
    $numberId = (int) ($payload['number_id'] ?? 0);
    if ($userId <= 0 || $numberId <= 0) {
        json(['error' => 'user_id and number_id required'], 422);
    }

    $pdo->prepare('DELETE FROM user_numbers WHERE user_id = :uid AND number_id = :nid')
        ->execute([':uid' => $userId, ':nid' => $numberId]);
    json(['ok' => true]);
}

if ($uri === '/api/admin/numbers/set-default' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);

    $payload = json_decode((string) file_get_contents('php://input'), true);
    $userId = (int) ($payload['user_id'] ?? 0);
    $numberId = (int) ($payload['number_id'] ?? 0);
    if ($userId <= 0 || $numberId <= 0) {
        json(['error' => 'user_id and number_id required'], 422);
    }

    $pdo->prepare('UPDATE user_numbers SET is_default = 0 WHERE user_id = :uid')
        ->execute([':uid' => $userId]);
    $pdo->prepare('INSERT INTO user_numbers (user_id, number_id, is_default) VALUES (:uid, :nid, 1)
        ON DUPLICATE KEY UPDATE is_default = 1')
        ->execute([':uid' => $userId, ':nid' => $numberId]);
    json(['ok' => true]);
}

if ($uri === '/api/inbox/conversations' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    $q = trim((string)($_GET['q'] ?? ''));
    $assigned = trim((string)($_GET['assigned'] ?? ''));

    $sql = 'SELECT c.id AS conversation_id,
        ct.id AS contact_id,
        ct.name AS contact_name,
        ct.phone_number AS contact_phone,
        c.last_message_preview,
        c.last_message_at,
        c.assigned_user_id,
        u.email AS assigned_user_email,
        c.default_number_id,
        n.phone_number AS conversation_number
      FROM conversations c
      INNER JOIN contacts ct ON ct.id = c.contact_id
      LEFT JOIN users u ON u.id = c.assigned_user_id
      LEFT JOIN numbers n ON n.id = c.default_number_id
      WHERE 1=1';

    $params = [];
    if ($q !== '') {
        $sql .= ' AND (ct.phone_number LIKE :q OR ct.name LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    if ($assigned === 'me') {
        $sql .= ' AND c.assigned_user_id = :me';
        $params[':me'] = Auth::userId();
    }

    $sql .= ' ORDER BY c.last_message_at DESC, c.id DESC LIMIT 100';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json(['conversations' => $stmt->fetchAll()]);
}

if ($uri === '/api/inbox/conversations/create' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);

    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $phone = trim((string) ($data['phone_number'] ?? ''));
    $defaultNumberId = (int) ($data['default_number_id'] ?? 0);
    if ($phone === '' || $defaultNumberId <= 0) {
        json(['error' => 'phone_number and default_number_id required'], 422);
    }

    $chk = $pdo->prepare('SELECT 1 FROM user_numbers WHERE user_id = :uid AND number_id = :nid LIMIT 1');
    $chk->execute([':uid' => Auth::userId(), ':nid' => $defaultNumberId]);
    if (!$chk->fetch()) {
        json(['error' => 'You do not have access to that number'], 403);
    }

    $contactId = getOrCreateContactId($pdo, $phone);
    $cid = getOrCreateConversationId($pdo, $contactId, $defaultNumberId);
    json(['ok' => true, 'conversation_id' => $cid]);
}

if ($uri === '/api/inbox/messages' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    $conversationId = (int) ($_GET['conversation_id'] ?? 0);
    if ($conversationId <= 0) {
        json(['error' => 'conversation_id required'], 422);
    }
    $stmt = $pdo->prepare('SELECT m.id, m.direction, m.from_number, m.to_number, m.body, m.status, m.created_at,
        m.user_id, u.email AS user_email
      FROM messages m
      LEFT JOIN users u ON u.id = m.user_id
      WHERE m.conversation_id = :cid
      ORDER BY m.id ASC
      LIMIT 500');
    $stmt->execute([':cid' => $conversationId]);
    json(['messages' => $stmt->fetchAll()]);
}

if ($uri === '/api/inbox/send' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);

    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $conversationId = (int) ($data['conversation_id'] ?? 0);
    $body = trim((string) ($data['body'] ?? ''));
    $fromNumberId = (int) ($data['from_number_id'] ?? 0);

    if ($conversationId <= 0 || $body === '') {
        json(['error' => 'conversation_id and body required'], 422);
    }

    $convStmt = $pdo->prepare('SELECT c.id, c.contact_id, ct.phone_number AS to_number, c.default_number_id, n.phone_number AS default_from
        FROM conversations c
        INNER JOIN contacts ct ON ct.id = c.contact_id
        LEFT JOIN numbers n ON n.id = c.default_number_id
        WHERE c.id = :id LIMIT 1');
    $convStmt->execute([':id' => $conversationId]);
    $conv = $convStmt->fetch();
    if (!$conv) {
        json(['error' => 'Conversation not found'], 404);
    }

    $to = (string) ($conv['to_number'] ?? '');
    $from = '';
    $fromTwilioAccountId = null;

    $sendConversationId = $conversationId;
    $convDefaultNumberId = (int) (($conv['default_number_id'] ?? 0) ?: 0);
    if ($fromNumberId > 0 && $convDefaultNumberId > 0 && $fromNumberId !== $convDefaultNumberId) {
        $sendConversationId = getOrCreateConversationId($pdo, (int) ($conv['contact_id'] ?? 0), $fromNumberId);
    }

    if ($fromNumberId > 0) {
        $fromStmt = $pdo->prepare('SELECT n.phone_number, n.twilio_account_id FROM numbers n
            INNER JOIN user_numbers un ON un.number_id = n.id
            WHERE un.user_id = :uid AND n.id = :nid LIMIT 1');
        $fromStmt->execute([':uid' => Auth::userId(), ':nid' => $fromNumberId]);
        $row = $fromStmt->fetch();
        $from = (string) (($row['phone_number'] ?? '') ?: '');
        $tid = (int) (($row['twilio_account_id'] ?? 0) ?: 0);
        if ($tid > 0) {
            $fromTwilioAccountId = $tid;
        }
    }

    if ($from === '') {
        $from = (string) (($conv['default_from'] ?? '') ?: '');
    }

    if ($from === '') {
        $fallback = $pdo->prepare('SELECT n.phone_number FROM numbers n
            INNER JOIN user_numbers un ON un.number_id = n.id
            WHERE un.user_id = :uid
            ORDER BY un.is_default DESC, n.id ASC
            LIMIT 1');
        $fallback->execute([':uid' => Auth::userId()]);
        $from = (string) (($fallback->fetch()['phone_number'] ?? '') ?: '');
    }

    if ($from === '') {
        $cfg = twilioConfig($pdo, Auth::userId());
        $from = (string) (($cfg['default_from_number'] ?? '') ?: '');
    }

    if ($from === '' || $to === '') {
        json(['error' => 'Missing from/to numbers'], 500);
    }

    if ($fromTwilioAccountId === null) {
        $stmtTid = $pdo->prepare('SELECT twilio_account_id FROM numbers WHERE phone_number = :pn LIMIT 1');
        $stmtTid->execute([':pn' => $from]);
        $tid = (int) (($stmtTid->fetch()['twilio_account_id'] ?? 0) ?: 0);
        if ($tid > 0) {
            $fromTwilioAccountId = $tid;
        }
    }

    $sid = '';
    $token = '';
    if ($fromTwilioAccountId !== null) {
        $accStmt = $pdo->prepare('SELECT account_sid, auth_token FROM twilio_accounts WHERE id = :id LIMIT 1');
        $accStmt->execute([':id' => $fromTwilioAccountId]);
        $acc = $accStmt->fetch();
        $sid = (string) (($acc['account_sid'] ?? '') ?: '');
        $token = (string) (($acc['auth_token'] ?? '') ?: '');
    }

    if ($sid === '' || $token === '') {
        $cfg = twilioConfig($pdo, Auth::userId());
        $sid = (string) ($cfg['account_sid'] ?? '');
        $token = (string) ($cfg['auth_token'] ?? '');
    }
    if ($sid === '' || $token === '') {
        json(['error' => 'Missing Twilio Messaging credentials. Configure a Twilio profile in Settings and set a default profile.'], 500);
    }

    $client = new Client($sid, $token);
    $statusCallback = baseUrl() . '/webhooks/twilio/sms/status';

    try {
        $msg = $client->messages->create($to, [
            'from' => $from,
            'body' => $body,
            'statusCallback' => $statusCallback,
        ]);
    } catch (\Throwable $e) {
        json(['error' => 'Twilio send failed', 'detail' => $e->getMessage()], 502);
    }

    $pdo->prepare('INSERT INTO messages (conversation_id, user_id, direction, from_number, to_number, body, twilio_sid, status)
        VALUES (:cid, :uid, :dir, :from, :to, :body, :sid, :st)')
        ->execute([
            ':cid' => $sendConversationId,
            ':uid' => Auth::userId(),
            ':dir' => 'outbound',
            ':from' => $from,
            ':to' => $to,
            ':body' => $body,
            ':sid' => (string) ($msg->sid ?? ''),
            ':st' => (string) ($msg->status ?? 'queued'),
        ]);
    updateConversationPreview($pdo, $sendConversationId, $body);

    json(['ok' => true, 'sid' => (string) ($msg->sid ?? ''), 'status' => (string) ($msg->status ?? 'queued'), 'conversation_id' => $sendConversationId]);
}

if ($uri === '/api/inbox/notes' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    $conversationId = (int) ($_GET['conversation_id'] ?? 0);
    if ($conversationId <= 0) {
        json(['error' => 'conversation_id required'], 422);
    }
    $stmt = $pdo->prepare('SELECT n.id, n.note, n.created_at, n.user_id, u.email AS user_email
        FROM conversation_notes n
        INNER JOIN users u ON u.id = n.user_id
        WHERE n.conversation_id = :cid
        ORDER BY n.id DESC
        LIMIT 200');
    $stmt->execute([':cid' => $conversationId]);
    json(['notes' => $stmt->fetchAll()]);
}

if ($uri === '/api/inbox/notes' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    $conversationId = (int) ($data['conversation_id'] ?? 0);
    $note = trim((string) ($data['note'] ?? ''));
    if ($conversationId <= 0 || $note === '') {
        json(['error' => 'conversation_id and note required'], 422);
    }
    $pdo->prepare('INSERT INTO conversation_notes (conversation_id, user_id, note) VALUES (:cid, :uid, :note)')
        ->execute([':cid' => $conversationId, ':uid' => Auth::userId(), ':note' => $note]);
    json(['ok' => true]);
}

if ($uri === '/api/inbox/assign' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    $conversationId = (int) ($data['conversation_id'] ?? 0);
    $assignedUserId = $data['assigned_user_id'] ?? null;
    if ($conversationId <= 0) {
        json(['error' => 'conversation_id required'], 422);
    }
    if ($assignedUserId === 'me') {
        $assignedUserId = Auth::userId();
    }
    if ($assignedUserId === '' || $assignedUserId === null) {
        $assignedUserId = null;
    } else {
        $assignedUserId = (int) $assignedUserId;
    }
    $pdo->prepare('UPDATE conversations SET assigned_user_id = :uid WHERE id = :id')
        ->execute([':uid' => $assignedUserId, ':id' => $conversationId]);
    json(['ok' => true]);
}

if ($uri === '/api/inbox/my-numbers' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);

    $stmt = $pdo->prepare('SELECT n.id, n.phone_number, un.is_default
        FROM numbers n
        INNER JOIN user_numbers un ON un.number_id = n.id
        WHERE un.user_id = :uid
        ORDER BY un.is_default DESC, n.id ASC');
    $stmt->execute([':uid' => Auth::userId()]);
    json(['numbers' => $stmt->fetchAll()]);
}

if ($uri === '/api/inbox/users' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    $stmt = $pdo->query('SELECT id, email, role FROM users ORDER BY id ASC');
    json(['users' => $stmt->fetchAll()]);
}

if ($uri === '/api/inbox/contact' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    $conversationId = (int) ($data['conversation_id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    if ($conversationId <= 0) {
        json(['error' => 'conversation_id required'], 422);
    }
    $stmt = $pdo->prepare('UPDATE contacts ct
        INNER JOIN conversations c ON c.contact_id = ct.id
        SET ct.name = :name
        WHERE c.id = :cid');
    $stmt->execute([':name' => ($name === '' ? null : $name), ':cid' => $conversationId]);
    json(['ok' => true]);
}

if ($uri === '/api/contacts' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    $q = trim((string)($_GET['q'] ?? ''));

    $sql = 'SELECT id, name, phone_number, created_at FROM contacts WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (phone_number LIKE :q OR name LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY id DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json(['contacts' => $stmt->fetchAll()]);
}

if ($uri === '/api/contacts/update' && $method === 'POST') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    $id = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    if ($id <= 0) {
        json(['error' => 'id required'], 422);
    }
    $stmt = $pdo->prepare('UPDATE contacts SET name = :name WHERE id = :id');
    $stmt->execute([':name' => ($name === '' ? null : $name), ':id' => $id]);
    json(['ok' => true]);
}

if ($uri === '/api/admin/users' && $method === 'GET') {
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);
    $stmt = $pdo->query('SELECT id, email, role, created_at FROM users ORDER BY id ASC');
    json(['users' => $stmt->fetchAll()]);
}

if ($uri === '/api/admin/users/create' && $method === 'POST') {
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    $email = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $role = trim((string)($data['role'] ?? 'agent'));
    if ($email === '' || strlen($password) < 8) {
        json(['error' => 'email and password (min 8) required'], 422);
    }
    if ($role !== 'admin') {
        $role = 'agent';
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, role) VALUES (:email, :hash, :role)');
    try {
        $stmt->execute([':email' => $email, ':hash' => $hash, ':role' => $role]);
    } catch (\Throwable $e) {
        json(['error' => 'Could not create user (email may already exist)'], 409);
    }
    json(['ok' => true]);
}

if ($uri === '/api/admin/users/reset-password' && $method === 'POST') {
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    $id = (int)($data['id'] ?? 0);
    $password = (string)($data['password'] ?? '');
    if ($id <= 0 || strlen($password) < 8) {
        json(['error' => 'id and password (min 8) required'], 422);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
    $stmt->execute([':hash' => $hash, ':id' => $id]);
    json(['ok' => true]);
}

if ($uri === '/api/admin/users/set-role' && $method === 'POST') {
    $pdo = getPdo($rootDir);
    requireAdmin($pdo);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        $data = $_POST;
    }
    $id = (int)($data['id'] ?? 0);
    $role = trim((string)($data['role'] ?? 'agent'));
    if ($id <= 0) {
        json(['error' => 'id required'], 422);
    }
    if ($role !== 'admin') {
        $role = 'agent';
    }
    $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
    $stmt->execute([':role' => $role, ':id' => $id]);
    json(['ok' => true]);
}

http_response_code(404);
render('Not Found', '<div class="topbar"><div class="brand">Twilio Platform</div></div><div class="card"><h2 style="margin:0 0 8px 0">404</h2><div class="small">Page not found.</div></div>');
