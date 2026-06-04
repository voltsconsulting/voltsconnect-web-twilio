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

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if (installed($rootDir)) {
    $isApi = is_string($uri) && str_starts_with((string) $uri, '/api/');
    $isAppUi = is_string($uri) && ((string) $uri === '/app');

    if ($isApi || $isAppUi) {
        try {
            $pdo = getPdo($rootDir);
            if (!licenseValid($pdo, 'core')) {
                if ($isApi) {
                    if (!apiAllowedWhenCoreUnlicensed((string) $uri)) {
                        json(['error' => 'Core license required'], 402);
                    }
                }

                if ($isAppUi) {
                    http_response_code(402);
                    render('Payment Required', '<div class="topbar"><div class="brand">WEB- Twilio</div></div><div class="card"><h2 style="margin:0 0 8px 0">License required</h2><div class="small">Your core license is not active. Please contact your admin to reactivate.</div></div>');
                    exit;
                }
            }
        } catch (\Throwable $e) {
        }
    }
}

if ($uri === '/api/cron/notifications' && $method === 'GET') {
    $pdo = getPdo($rootDir);
    $token = trim((string)($_GET['token'] ?? ''));
    $expected = trim(appSettingGet($pdo, 'notify_cron_token', ''));
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        json(['error' => 'Invalid token'], 403);
    }

    $rules = $pdo->query("SELECT role_id, reminder_minutes FROM notification_role_rules WHERE event_key = 'sms.unread_reminder' AND enabled = 1 AND reminder_minutes IS NOT NULL")->fetchAll();
    $sent = 0;
    if (is_array($rules)) {
        foreach ($rules as $r) {
            $roleId = (int) (($r['role_id'] ?? 0) ?: 0);
            $mins = (int) (($r['reminder_minutes'] ?? 0) ?: 0);
            if ($roleId <= 0 || $mins <= 0) {
                continue;
            }

            $st = $pdo->prepare('SELECT c.id AS conversation_id,
                    ct.phone_number AS from_number,
                    n.phone_number AS to_number,
                    c.last_message_at,
                    c.last_message_preview,
                    COALESCE(lm.last_message_id, 0) AS last_message_id,
                    COALESCE(MAX(cr.last_read_message_id), 0) AS role_max_read_message_id
                FROM conversations c
                INNER JOIN contacts ct ON ct.id = c.contact_id
                LEFT JOIN numbers n ON n.id = c.default_number_id
                LEFT JOIN (
                    SELECT conversation_id, MAX(id) AS last_message_id
                    FROM messages
                    GROUP BY conversation_id
                ) lm ON lm.conversation_id = c.id
                INNER JOIN user_role_assignments ura ON ura.role_id = :rid
                INNER JOIN users u ON u.id = ura.user_id AND u.is_active = 1
                LEFT JOIN conversation_reads cr ON cr.conversation_id = c.id AND cr.user_id = u.id
                WHERE c.last_message_at IS NOT NULL
                  AND c.last_message_at <= (NOW() - INTERVAL :mins MINUTE)
                GROUP BY c.id, ct.phone_number, n.phone_number, c.last_message_at, c.last_message_preview, lm.last_message_id
                HAVING COALESCE(lm.last_message_id, 0) > 0
                   AND COALESCE(MAX(cr.last_read_message_id), 0) < COALESCE(lm.last_message_id, 0)
                ORDER BY c.last_message_at DESC
                LIMIT 50');
            $st->bindValue(':rid', $roleId, PDO::PARAM_INT);
            $st->bindValue(':mins', $mins, PDO::PARAM_INT);
            $st->execute();
            $convs = $st->fetchAll();
            if (!is_array($convs)) {
                $convs = [];
            }

            foreach ($convs as $c) {
                $cid = (int) (($c['conversation_id'] ?? 0) ?: 0);
                if ($cid <= 0) continue;
                $refKey = 'sms_unread:' . $cid . ':' . $mins;

                try {
                    $pdo->prepare('INSERT INTO notification_sends (role_id, event_key, ref_key, conversation_id, message_id)
                        VALUES (:rid, :ek, :rk, :cid, NULL)')
                        ->execute([':rid' => $roleId, ':ek' => 'sms.unread_reminder', ':rk' => $refKey, ':cid' => $cid]);
                } catch (\Throwable $e) {
                    continue;
                }

                $uStmt = $pdo->prepare('SELECT DISTINCT u.email
                    FROM users u
                    INNER JOIN user_role_assignments ura ON ura.user_id = u.id
                    WHERE ura.role_id = :rid AND u.is_active = 1');
                $uStmt->execute([':rid' => $roleId]);
                $users = $uStmt->fetchAll();
                if (!is_array($users)) $users = [];

                $from = (string) (($c['from_number'] ?? '') ?: '');
                $to = (string) (($c['to_number'] ?? '') ?: '');
                $preview = (string) (($c['last_message_preview'] ?? '') ?: '');
                $when = (string) (($c['last_message_at'] ?? '') ?: '');
                $subject = 'Unread message reminder';
                $body = "A conversation has unread messages for {$mins} minutes.\n\nFrom: {$from}\nTo: {$to}\nLast message time: {$when}\nLast message: {$preview}\n";

                foreach ($users as $u) {
                    $email = (string) (($u['email'] ?? '') ?: '');
                    if ($email !== '') {
                        sendEmail($pdo, $email, $subject, $body);
                        $sent += 1;
                    }
                }
            }
        }
    }

    json(['ok' => true, 'sent' => $sent]);
}

if ($uri === '/api/cron/run' && $method === 'GET') {
    $pdo = getPdo($rootDir);
    $token = trim((string)($_GET['token'] ?? ''));
    $expected = trim(appSettingGet($pdo, 'notify_cron_token', ''));
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        json(['error' => 'Invalid token'], 403);
    }

    $fetchJson = static function (string $url): array {
        $raw = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            $out = curl_exec($ch);
            $err = curl_error($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($out) || $out === '') {
                return ['ok' => false, 'http' => $http, 'error' => $err !== '' ? $err : 'empty_response'];
            }
            $raw = $out;
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 25,
                    'header' => "Accept: application/json\r\n",
                ]
            ]);
            $out = @file_get_contents($url, false, $ctx);
            if (!is_string($out) || $out === '') {
                return ['ok' => false, 'error' => 'empty_response'];
            }
            $raw = $out;
        }

        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            return $parsed;
        }
        return ['ok' => false, 'error' => 'invalid_json'];
    };

    $base = baseUrl();
    $t = urlencode($token);
    $notificationsUrl = $base . '/api/cron/notifications?token=' . $t;
    $broadcastsUrl = $base . '/api/cron/broadcasts?token=' . $t;

    $results = [
        'notifications' => $fetchJson($notificationsUrl),
        'broadcasts' => $fetchJson($broadcastsUrl),
    ];

    json(['ok' => true, 'results' => $results]);
}

if ($uri === '/api/cron/broadcasts' && $method === 'GET') {
    require_once $rootDir . '/src/Http/BroadcastRoutes.php';
    if (function_exists('handleBroadcastRoutes') && handleBroadcastRoutes((string) $uri, (string) $method, $rootDir)) {
        exit;
    }
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
        try {
            seedAddons($pdo);
        } catch (\Throwable $e) {
        }
        $schemaEnsured = true;
    }
    return $pdo;
}

function addonRegistry(): array
{
    return [
        ['key' => 'broadcasting', 'label' => 'Broadcasting / Campaigns', 'url' => '', 'buy_url' => '', 'coming_soon' => false, 'default_enabled' => true],
        ['key' => 'rbac', 'label' => 'Roles & Permissions (RBAC)', 'url' => '', 'buy_url' => '', 'coming_soon' => false, 'default_enabled' => true],
        ['key' => 'advanced_analytics', 'label' => 'Advanced Analytics', 'url' => '', 'buy_url' => '', 'coming_soon' => true, 'default_enabled' => false],
        ['key' => 'flow_builder', 'label' => 'Flow Builder / Automations', 'url' => '', 'buy_url' => '', 'coming_soon' => true, 'default_enabled' => false],
        ['key' => 'integrations', 'label' => 'Integrations / API / Webhooks', 'url' => '', 'buy_url' => '', 'coming_soon' => true, 'default_enabled' => false],
    ];
}

function seedAddons(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare('INSERT IGNORE INTO app_addons (addon_key, enabled) VALUES (:k, :v)');
        foreach (addonRegistry() as $a) {
            $k = trim((string) ($a['key'] ?? ''));
            $v = !empty($a['default_enabled']) ? 1 : 0;
            if ($k !== '') {
                $stmt->execute([':k' => $k, ':v' => $v]);
            }
        }

        foreach (addonRegistry() as $a) {
            $k = trim((string) ($a['key'] ?? ''));
            if ($k === '') {
                continue;
            }
            if (!empty($a['coming_soon'])) {
                $pdo->prepare('UPDATE app_addons SET enabled = 0 WHERE addon_key = :k')->execute([':k' => $k]);
            }
        }
    } catch (\Throwable $e) {
    }
}

function enabledAddons(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT addon_key FROM app_addons WHERE enabled = 1 ORDER BY addon_key ASC')->fetchAll();
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $k = trim((string) (($r['addon_key'] ?? '') ?: ''));
                if ($k !== '') {
                    $out[] = $k;
                }
            }
        }
        return $out;
    } catch (\Throwable $e) {
        return [];
    }
}

function addonEnabled(PDO $pdo, string $addonKey): bool
{
    $addonKey = trim($addonKey);
    if ($addonKey === '') {
        return true;
    }
    try {
        $stmt = $pdo->prepare('SELECT enabled FROM app_addons WHERE addon_key = :k LIMIT 1');
        $stmt->execute([':k' => $addonKey]);
        $row = $stmt->fetch();
        if (!$row) {
            return true;
        }
        return (int) (($row['enabled'] ?? 1) ?: 0) === 1;
    } catch (\Throwable $e) {
        return true;
    }
}

function setAddonEnabled(PDO $pdo, string $addonKey, bool $enabled): void
{
    $addonKey = trim($addonKey);
    if ($addonKey === '') {
        return;
    }
    $v = $enabled ? 1 : 0;
    $pdo->prepare('INSERT INTO app_addons (addon_key, enabled) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)')->execute([':k' => $addonKey, ':v' => $v]);
}

function requireAddon(PDO $pdo, string $addonKey): void
{
    if ($addonKey === '') {
        return;
    }
    if (!addonEnabled($pdo, $addonKey)) {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (str_starts_with($path, '/api/')) {
            json(['error' => 'Addon disabled', 'addon' => $addonKey], 403);
        }
        http_response_code(403);
        render('Forbidden', '<div class="topbar"><div class="brand">WEB- Twilio</div></div><div class="card"><h2 style="margin:0 0 8px 0">403</h2><div class="small">Feature disabled.</div></div>');
        exit;
    }

    $scope = 'addon:' . $addonKey;
    if (!licenseValid($pdo, $scope)) {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (str_starts_with($path, '/api/')) {
            json(['error' => 'Addon license required', 'addon' => $addonKey, 'scope' => $scope], 402);
        }
        http_response_code(402);
        render('Payment Required', '<div class="topbar"><div class="brand">WEB- Twilio</div></div><div class="card"><h2 style="margin:0 0 8px 0">License required</h2><div class="small">This addon needs an active license.</div></div>');
        exit;
    }
}

function appSettingGet(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT v FROM app_settings WHERE k = :k LIMIT 1');
    $stmt->execute([':k' => $key]);
    $v = $stmt->fetch();
    $raw = (string) (($v['v'] ?? '') ?: '');
    return $raw !== '' ? $raw : $default;
}

function licenseRow(PDO $pdo, string $scope): ?array
{
    $scope = trim($scope);
    if ($scope === '') {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT scope, product_id, product_base, admin_email, license_key_enc, is_valid, license_title, expire_date, support_end, next_check_at, last_checked_at, last_error
            FROM app_licenses WHERE scope = :s LIMIT 1');
        $st->execute([':s' => $scope]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function refreshLicenseIfDue(PDO $pdo, string $scope): void
{
    $row = licenseRow($pdo, $scope);
    if (!$row) {
        return;
    }

    $next = (string) (($row['next_check_at'] ?? '') ?: '');
    if ($next !== '') {
        try {
            if (strtotime($next) > time()) {
                return;
            }
        } catch (\Throwable $e) {
        }
    }

    $serverHost = decryptSecret(appSettingGet($pdo, 'lic_server_enc', ''));
    $key = decryptSecret(appSettingGet($pdo, 'lic_key_enc', ''));
    if (trim($serverHost) === '' || trim($key) === '') {
        return;
    }

    $productId = trim((string) (($row['product_id'] ?? '') ?: ''));
    $productBase = trim((string) (($row['product_base'] ?? '') ?: ''));
    $adminEmail = trim((string) (($row['admin_email'] ?? '') ?: ''));
    $licenseKey = decryptSecret((string) (($row['license_key_enc'] ?? '') ?: ''));
    if ($productId === '' || $productBase === '' || $licenseKey === '') {
        return;
    }

    $appVersion = appSettingGet($pdo, 'app_version', '1.0.0');
    $res = \App\Licensing\Lic::checkLicense($serverHost, $key, $productId, $productBase, $licenseKey, $appVersion, $adminEmail !== '' ? $adminEmail : null);

    $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $isValid = !empty($res['ok']);
    $err = $isValid ? '' : (string) (($res['error'] ?? '') ?: 'Invalid license');
    $dur = $isValid ? (int) (($res['request_duration_hours'] ?? 0) ?: 0) : 0;
    $nextAt = null;
    if ($dur > 0) {
        $nextAt = (new \DateTimeImmutable('now'))->modify('+' . $dur . ' hours')->format('Y-m-d H:i:s');
    }

    try {
        $pdo->prepare('UPDATE app_licenses
            SET is_valid = :v,
                license_title = :lt,
                expire_date = :ed,
                support_end = :se,
                next_check_at = :nca,
                last_checked_at = :lca,
                last_error = :le
            WHERE scope = :s')
            ->execute([
                ':s' => $scope,
                ':v' => $isValid ? 1 : 0,
                ':lt' => $isValid ? (string) (($res['license_title'] ?? '') ?: '') : (string) (($row['license_title'] ?? '') ?: ''),
                ':ed' => $isValid ? (string) (($res['expire_date'] ?? '') ?: '') : (string) (($row['expire_date'] ?? '') ?: ''),
                ':se' => $isValid ? (string) (($res['support_end'] ?? '') ?: '') : (string) (($row['support_end'] ?? '') ?: ''),
                ':nca' => $nextAt,
                ':lca' => $now,
                ':le' => $err,
            ]);
    } catch (\Throwable $e) {
    }
}

function licenseValid(PDO $pdo, string $scope): bool
{
    refreshLicenseIfDue($pdo, $scope);
    $row = licenseRow($pdo, $scope);
    if (!$row) {
        return false;
    }
    return (int) (($row['is_valid'] ?? 0) ?: 0) === 1;
}

function apiAllowedWhenCoreUnlicensed(string $uri): bool
{
    if ($uri === '/api/me') return true;
    if (str_starts_with($uri, '/api/admin/licenses')) return true;
    if ($uri === '/api/admin/addons') return true;
    return false;
}

function appSettingSet(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO app_settings (k, v) VALUES (:k, :v)
        ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([':k' => $key, ':v' => $value]);
}

function cryptoKeyBytes(): string
{
    $k = (string) (Config::get('APP_KEY', '') ?? '');
    $k = trim($k);
    if ($k === '') {
        return '';
    }
    return hash('sha256', $k, true);
}

function encryptSecret(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    $key = cryptoKeyBytes();
    if ($key === '' || !function_exists('openssl_encrypt')) {
        return $plain;
    }
    try {
        $iv = random_bytes(16);
    } catch (\Throwable $e) {
        return $plain;
    }
    $ct = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($ct === false) {
        return $plain;
    }
    return base64_encode($iv . $ct);
}

function decryptSecret(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    $key = cryptoKeyBytes();
    if ($key === '' || !function_exists('openssl_decrypt')) {
        return $stored;
    }
    $raw = base64_decode($stored, true);
    if (!is_string($raw) || strlen($raw) < 17) {
        return $stored;
    }
    $iv = substr($raw, 0, 16);
    $ct = substr($raw, 16);
    if (!is_string($iv) || !is_string($ct)) {
        return $stored;
    }
    $pt = openssl_decrypt($ct, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($pt === false) {
        return $stored;
    }
    return (string) $pt;
}

function sendEmail(PDO $pdo, string $toEmail, string $subject, string $body): bool
{
    $enabled = appSettingGet($pdo, 'smtp_enabled', '0') === '1';
    if (!$enabled) {
        return @mail($toEmail, $subject, $body);
    }

    $host = trim(appSettingGet($pdo, 'smtp_host', ''));
    $port = (int) appSettingGet($pdo, 'smtp_port', '587');
    $user = trim(appSettingGet($pdo, 'smtp_username', ''));
    $passEnc = (string) (appSettingGet($pdo, 'smtp_password_enc', '') ?? '');
    $pass = decryptSecret($passEnc);
    $secure = trim(appSettingGet($pdo, 'smtp_secure', 'tls'));
    $fromEmail = trim(appSettingGet($pdo, 'smtp_from_email', ''));
    $fromName = trim(appSettingGet($pdo, 'smtp_from_name', ''));

    if ($host === '' || $port <= 0) {
        return @mail($toEmail, $subject, $body);
    }

    $phpMailerClass = 'PHPMailer\\PHPMailer\\PHPMailer';
    if (class_exists($phpMailerClass)) {
        try {
            $mail = new $phpMailerClass(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->SMTPAuth = $user !== '';
            if ($mail->SMTPAuth) {
                $mail->Username = $user;
                $mail->Password = $pass;
            }
            if ($secure === 'ssl' || $secure === 'tls') {
                $mail->SMTPSecure = $secure;
            }
            $mail->CharSet = 'UTF-8';
            if ($fromEmail !== '') {
                $mail->setFrom($fromEmail, $fromName !== '' ? $fromName : null);
            }
            $mail->addAddress($toEmail);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = $body;
            $mail->isHTML(false);
            $mail->send();
            return true;
        } catch (\Throwable $e) {
            return @mail($toEmail, $subject, $body);
        }
    }

    $headers = '';
    if ($fromEmail !== '') {
        $headers = 'From: ' . ($fromName !== '' ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail) . "\r\n";
    }
    return @mail($toEmail, $subject, $body, $headers);
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

if (is_string($uri) && str_starts_with($uri, '/mms/tmp/') && $method === 'GET') {
    $rest = substr($uri, strlen('/mms/tmp/'));
    $rest = $rest === false ? '' : $rest;
    $parts = array_values(array_filter(explode('/', $rest), fn($x) => $x !== ''));
    $token = (string) ($parts[0] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        http_response_code(404);
        exit;
    }
    $dir = $rootDir . '/storage/mms_tmp';
    $matches = glob($dir . '/' . $token . '_*') ?: [];
    $path = '';
    foreach ($matches as $m) {
        if (is_file($m)) {
            $path = $m;
            break;
        }
    }
    if ($path === '') {
        http_response_code(404);
        exit;
    }
    try {
        if ((time() - (int) filemtime($path)) > 86400) {
            @unlink($path);
            http_response_code(404);
            exit;
        }
    } catch (\Throwable $e) {
    }

    foreach (glob($dir . '/*') ?: [] as $p) {
        try {
            if (is_file($p) && (time() - (int) filemtime($p)) > 86400) {
                @unlink($p);
            }
        } catch (\Throwable $e) {
        }
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['png'], true)) header('Content-Type: image/png');
    elseif (in_array($ext, ['jpg', 'jpeg'], true)) header('Content-Type: image/jpeg');
    elseif (in_array($ext, ['gif'], true)) header('Content-Type: image/gif');
    elseif (in_array($ext, ['webp'], true)) header('Content-Type: image/webp');
    elseif (in_array($ext, ['svg'], true)) header('Content-Type: image/svg+xml; charset=utf-8');
    elseif ($ext === 'pdf') header('Content-Type: application/pdf');
    elseif (in_array($ext, ['mp4', 'm4v'], true)) header('Content-Type: video/mp4');
    elseif (in_array($ext, ['mp3'], true)) header('Content-Type: audio/mpeg');
    else header('Content-Type: application/octet-stream');
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if (!installed($rootDir)) {
    if (is_string($uri) && str_starts_with($uri, '/api/')) {
        json(['error' => 'Not installed'], 503);
    }
    $isStatic = is_string($uri) && preg_match('/\.(css|js|png|svg|ico)$/i', $uri) === 1;
    if ($uri !== '/install' && $uri !== '/install/' && !$isStatic && !str_starts_with((string) $uri, '/assets/') && !str_starts_with((string) $uri, '/webhooks/')) {
        redirect('/install');
    }
}

function sendRoleNotification(PDO $pdo, string $eventKey, string $refKey, string $subject, string $body, ?int $conversationId = null, ?int $messageId = null): void
{
    if ($eventKey === '' || $refKey === '') {
        return;
    }

    $roles = [];
    try {
        $st = $pdo->prepare('SELECT r.id, r.name
            FROM roles r
            INNER JOIN notification_role_rules rr ON rr.role_id = r.id
            WHERE rr.event_key = :k AND rr.enabled = 1');
        $st->execute([':k' => $eventKey]);
        $roles = $st->fetchAll();
    } catch (\Throwable $e) {
        $roles = [];
    }
    if (!is_array($roles) || count($roles) === 0) {
        return;
    }

    foreach ($roles as $r) {
        $roleId = (int) (($r['id'] ?? 0) ?: 0);
        if ($roleId <= 0) {
            continue;
        }

        try {
            $pdo->prepare('INSERT INTO notification_sends (role_id, event_key, ref_key, conversation_id, message_id)
                VALUES (:rid, :ek, :rk, :cid, :mid)')
                ->execute([
                    ':rid' => $roleId,
                    ':ek' => $eventKey,
                    ':rk' => $refKey,
                    ':cid' => $conversationId,
                    ':mid' => $messageId,
                ]);
        } catch (\Throwable $e) {
            continue;
        }

        try {
            $uStmt = $pdo->prepare('SELECT DISTINCT u.email
                FROM users u
                INNER JOIN user_role_assignments ura ON ura.user_id = u.id
                WHERE ura.role_id = :rid AND u.is_active = 1');
            $uStmt->execute([':rid' => $roleId]);
            $users = $uStmt->fetchAll();
            if (!is_array($users)) {
                $users = [];
            }
            foreach ($users as $u) {
                $toEmail = (string) (($u['email'] ?? '') ?: '');
                if ($toEmail !== '') {
                    sendEmail($pdo, $toEmail, $subject, $body);
                }
            }
        } catch (\Throwable $e) {
        }
    }
}

if (is_string($uri) && str_starts_with($uri, '/api/') && !Auth::check()) {
    json(['error' => 'Not authenticated'], 401);
}

require_once $rootDir . '/src/Http/FeatureRoutes.php';
if (function_exists('handleFeatureRoutes') && handleFeatureRoutes((string) $uri, (string) $method, $rootDir)) {
    exit;
}

require_once $rootDir . '/src/Http/BroadcastRoutes.php';
if (function_exists('handleBroadcastRoutes') && handleBroadcastRoutes((string) $uri, (string) $method, $rootDir)) {
    exit;
}

require_once $rootDir . '/src/Http/TemplateRoutes.php';
if (function_exists('handleTemplateRoutes') && handleTemplateRoutes((string) $uri, (string) $method, $rootDir)) {
    exit;
}

require_once $rootDir . '/src/Http/AdminRoutes.php';
if (function_exists('handleAdminRoutes') && handleAdminRoutes((string) $uri, (string) $method, $rootDir)) {
    exit;
}

require_once $rootDir . '/src/Http/CrmRoutes.php';
if (function_exists('handleCrmRoutes') && handleCrmRoutes((string) $uri, (string) $method, $rootDir)) {
    exit;
}

require_once $rootDir . '/src/Http/ContactsRoutes.php';
if (function_exists('handleContactsRoutes') && handleContactsRoutes((string) $uri, (string) $method, $rootDir)) {
    exit;
}

require_once $rootDir . '/src/Http/InboxRoutes.php';
if (function_exists('handleInboxRoutes') && handleInboxRoutes((string) $uri, (string) $method, $rootDir)) {
    exit;
}

require_once $rootDir . '/src/Http/CallsRoutes.php';
if (function_exists('handleCallsRoutes') && handleCallsRoutes((string) $uri, (string) $method, $rootDir)) {
    exit;
}

if (is_string($uri) && str_starts_with($uri, '/assets/img/') && $method === 'GET') {
    $rel = substr($uri, strlen('/assets/img/'));
    $rel = $rel === false ? '' : $rel;
    $path = $rootDir . '/public/assets/img/' . $rel;
    if (!is_file($path)) {
        $path = $rootDir . '/storage/Assets/img/' . $rel;
        if (!is_file($path)) {
            $path = $rootDir . '/storage/assets/img/' . $rel;
        }
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

function userPermissionKeys(PDO $pdo, ?int $userId): array
{
    if ($userId === null) {
        return [];
    }
    if (isAdminUser($pdo, $userId)) {
        $rows = $pdo->query('SELECT perm_key FROM permissions ORDER BY perm_key ASC')->fetchAll();
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $k = (string) (($r['perm_key'] ?? '') ?: '');
                if ($k !== '') {
                    $out[$k] = true;
                }
            }
        }
        return array_keys($out);
    }

    $stmt = $pdo->prepare('SELECT DISTINCT p.perm_key
        FROM user_role_assignments ura
        INNER JOIN role_permissions rp ON rp.role_id = ura.role_id
        INNER JOIN permissions p ON p.id = rp.permission_id
        WHERE ura.user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll();
    $out = [];
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $k = (string) (($r['perm_key'] ?? '') ?: '');
            if ($k !== '') {
                $out[$k] = true;
            }
        }
    }
    return array_keys($out);
}

function userHasPermission(PDO $pdo, ?int $userId, string $permKey): bool
{
    if ($permKey === '') {
        return true;
    }
    if ($userId === null) {
        return false;
    }
    if (isAdminUser($pdo, $userId)) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT 1
        FROM user_role_assignments ura
        INNER JOIN role_permissions rp ON rp.role_id = ura.role_id
        INNER JOIN permissions p ON p.id = rp.permission_id
        WHERE ura.user_id = :uid AND p.perm_key = :k
        LIMIT 1');
    $stmt->execute([':uid' => $userId, ':k' => $permKey]);
    return (bool) $stmt->fetch();
}

function requirePermission(PDO $pdo, string $permKey): void
{
    Auth::requireLogin();
    $uid = Auth::userId();
    if ($uid === null) {
        json(['error' => 'Not authenticated'], 401);
    }
    if (!userHasPermission($pdo, $uid, $permKey)) {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (str_starts_with($path, '/api/')) {
            json(['error' => 'Forbidden'], 403);
        }
        http_response_code(403);
        render('Forbidden', '<div class="topbar"><div class="brand">WEB- Twilio</div></div><div class="card"><h2 style="margin:0 0 8px 0">403</h2><div class="small">Forbidden.</div></div>');
        exit;
    }
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
        $recSid = '';
        if ($recUrl !== '') {
            if (preg_match('/\/Recordings\/(RE[a-zA-Z0-9]+)/', $recUrl, $m)) {
                $recSid = (string) ($m[1] ?? '');
            }
        }
        $recLink = $recSid !== '' ? (baseUrl() . '/api/voice/recording?sid=' . rawurlencode($recSid)) : '';
        if ($recLink !== '') {
            $subject = 'Voicemail from ' . ($from !== '' ? $from : 'Unknown');
            $when = date('c');
            $emailBody = "You have a new voicemail.\n\nFrom: {$from}\nTo: {$to}\nTime: {$when}\nCallSid: {$callSid}\nRecording: {$recLink}\n";
            $refKey = ($recSid !== '' ? ('vm:' . $recSid) : ('vm_call:' . $callSid));
            sendRoleNotification($pdo, 'voice.voicemail', $refKey, $subject, $emailBody, null, null);
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
        requirePermission($pdo, 'voicemails.view');
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

    $optEnabled = appSettingGet($pdo, 'sms_opt_out_enabled', '1') === '1';
    $cmd = strtoupper(trim(preg_replace('/\s+/', ' ', $body)));
    if ($optEnabled && $from !== '' && in_array($cmd, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
        $pdo->prepare('INSERT IGNORE INTO sms_opt_outs (phone_number) VALUES (:pn)')->execute([':pn' => $from]);
    }
    if ($optEnabled && $from !== '' && in_array($cmd, ['START', 'YES', 'UNSTOP'], true)) {
        $pdo->prepare('DELETE FROM sms_opt_outs WHERE phone_number = :pn')->execute([':pn' => $from]);
    }

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
        $msgId = null;
        try {
            $msgId = (int) $pdo->lastInsertId();
        } catch (\Throwable $e) {
            $msgId = null;
        }

        try {
            $numMedia = (int) ($_POST['NumMedia'] ?? 0);
            if ($msgId !== null && $msgId > 0 && $numMedia > 0) {
                $ins = $pdo->prepare('INSERT INTO message_media (message_id, url, content_type) VALUES (:mid, :url, :ct)');
                for ($i = 0; $i < $numMedia; $i++) {
                    $u = trim((string) ($_POST['MediaUrl' . (string) $i] ?? ''));
                    $ct = trim((string) ($_POST['MediaContentType' . (string) $i] ?? ''));
                    if ($u !== '') {
                        $ins->execute([':mid' => $msgId, ':url' => $u, ':ct' => ($ct !== '' ? $ct : null)]);
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        updateConversationPreview($pdo, $conversationId, $body);

        try {
            $subject = 'New SMS from ' . ($from !== '' ? $from : 'Unknown');
            $when = date('c');
            $emailBody = "You have a new inbound SMS.\n\nFrom: {$from}\nTo: {$to}\nTime: {$when}\n\nMessage:\n{$body}\n";
            $refKey = ($sid !== '' ? ('sms:' . $sid) : ('sms_conv:' . (string) $conversationId . ':' . sha1($body)));
            sendRoleNotification($pdo, 'sms.inbound', $refKey, $subject, $emailBody, (int) $conversationId, $msgId !== null && $msgId > 0 ? (int) $msgId : null);
        } catch (\Throwable $e) {
        }
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
    echo '<div class="toastHost" id="toastHost"></div>';
    echo '</body></html>';
}

function requireAdmin(PDO $pdo): void
{
    Auth::requireLogin();
    $uid = Auth::userId();
    if ($uid === null) {
        json(['error' => 'Not authenticated'], 401);
    }
    if (userHasPermission($pdo, $uid, 'settings.manage')) {
        return;
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
        render('Forbidden', '<div class="topbar"><div class="brand">WEB- Twilio</div></div><div class="card"><h2 style="margin:0 0 8px 0">403</h2><div class="small">Admin only.</div></div>');
        exit;
    }
}

if ($uri === '/login' && $method === 'GET') {
    $msg = '';
    if (is_string($flash) && $flash !== '') {
        $msg = '<div class="error">' . h($flash) . '</div>';
    }

    $content = '<div class="topbar"><div class="brand"><img class="brandLogo brandLogoDark" src="/assets/img/logo-dark.svg" alt="Logo"><img class="brandLogo brandLogoLight" src="/assets/img/logo-light.svg" alt="Logo">WEB- Twilio</div></div>';
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
    if ($step > 4) {
        $step = 4;
    }

    $content = '<div class="topbar"><div class="brand"><img class="brandLogo brandLogoDark" src="/assets/img/logo-dark.svg" alt="Logo"><img class="brandLogo brandLogoLight" src="/assets/img/logo-light.svg" alt="Logo">WEB- Twilio</div></div>';
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

    if ($step === 3) {
        $content .= '<div class="small">Step 3 of 4: Create admin user</div><div style="height:10px"></div>';
        $content .= '<form method="post" action="/install?step=3">';
        $content .= '<div class="row" style="flex-direction:column">';
        $content .= '<input class="input" name="email" type="email" placeholder="Admin email" required>';
        $content .= '<input class="input" name="password" type="password" placeholder="Admin password (min 8 chars)" minlength="8" required>';
        $content .= '</div><div style="height:12px"></div>';
        $content .= '<button class="btn primary" type="submit">Continue</button> ';
        $content .= '<a class="btn" href="/install?step=2">Back</a>';
        $content .= '</form></div>';
        render('Install', $content);
        exit;
    }

    $prefEmail = '';
    if (isset($_SESSION['_install_admin_email']) && is_string($_SESSION['_install_admin_email'])) {
        $prefEmail = trim($_SESSION['_install_admin_email']);
    }

    $content .= '<div class="small">Step 4 of 4: Activate license</div><div style="height:10px"></div>';
    $content .= '<form method="post" action="/install?step=4">';
    $content .= '<div class="row" style="flex-direction:column">';
    $content .= '<input class="input" name="admin_email" type="email" placeholder="Admin email" value="' . h($prefEmail) . '" required>';
    $content .= '<input class="input" name="license_key" placeholder="License key" required>';
    $content .= '</div><div style="height:12px"></div>';
    $content .= '<button class="btn primary" type="submit">Activate & Finish</button> ';
    $content .= '<a class="btn" href="/install?step=3">Back</a>';
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

        $_SESSION['_install_admin_email'] = $email;
        $_SESSION['_flash'] = 'Admin created. Next: activate license.';
        redirect('/install?step=4');
    }

    if ($step === 4) {
        try {
            Config::load($rootDir);
            $pdo = getPdo($rootDir);
        } catch (\Throwable $e) {
            $_SESSION['_flash'] = 'Database is not configured yet.';
            redirect('/install?step=2');
        }

        $adminEmail = trim((string) ($_POST['admin_email'] ?? ''));
        $licenseKey = trim((string) ($_POST['license_key'] ?? ''));

        if ($adminEmail === '' || $licenseKey === '') {
            $_SESSION['_flash'] = 'All fields are required.';
            redirect('/install?step=4');
        }

        $serverHost = 'https://lic.volts-consulting.com/wp-json/lice/';
        $key = 'ECBABCE8CE806F23';
        $productId = '7';
        $productBase = 'Volts-Connect-Web-Twilio';

        if ($serverHost === '' || $key === '' || $productId === '' || $productBase === '') {
            $_SESSION['_flash'] = 'Licenser server is not configured.';
            redirect('/install?step=4');
        }

        $serverHost = rtrim($serverHost, '/') . '/';
        appSettingSet($pdo, 'lic_server_enc', encryptSecret($serverHost));
        appSettingSet($pdo, 'lic_key_enc', encryptSecret($key));

        $appVersion = appSettingGet($pdo, 'app_version', '1.0.0');
        $res = \App\Licensing\Lic::checkLicense($serverHost, $key, $productId, $productBase, $licenseKey, $appVersion, $adminEmail);
        if (empty($res['ok'])) {
            $_SESSION['_flash'] = (string) (($res['error'] ?? '') ?: 'License activation failed');
            redirect('/install?step=4');
        }

        $dur = (int) (($res['request_duration_hours'] ?? 0) ?: 0);
        $next = null;
        if ($dur > 0) {
            $next = (new \DateTimeImmutable('now'))->modify('+' . $dur . ' hours')->format('Y-m-d H:i:s');
        }
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $pdo->prepare('INSERT INTO app_licenses (scope, product_id, product_base, admin_email, license_key_enc, is_valid, license_title, expire_date, support_end, next_check_at, last_checked_at, last_error)
            VALUES (\'core\', :pid, :pb, :ae, :lk, 1, :lt, :ed, :se, :nca, :lca, \'\')
            ON DUPLICATE KEY UPDATE
                product_id = VALUES(product_id),
                product_base = VALUES(product_base),
                admin_email = VALUES(admin_email),
                license_key_enc = VALUES(license_key_enc),
                is_valid = VALUES(is_valid),
                license_title = VALUES(license_title),
                expire_date = VALUES(expire_date),
                support_end = VALUES(support_end),
                next_check_at = VALUES(next_check_at),
                last_checked_at = VALUES(last_checked_at),
                last_error = VALUES(last_error)')
            ->execute([
                ':pid' => $productId,
                ':pb' => $productBase,
                ':ae' => $adminEmail,
                ':lk' => encryptSecret($licenseKey),
                ':lt' => (string) (($res['license_title'] ?? '') ?: ''),
                ':ed' => (string) (($res['expire_date'] ?? '') ?: ''),
                ':se' => (string) (($res['support_end'] ?? '') ?: ''),
                ':nca' => $next,
                ':lca' => $now,
            ]);

        @file_put_contents($rootDir . '/storage/installed.lock', date('c') . "\n");
        unset($_SESSION['_install_admin_email']);
        $_SESSION['_flash'] = 'Installed and activated. Please sign in.';
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

    $content = '<div class="topbar"><div class="brand"><img class="brandLogo brandLogoDark" src="/assets/img/logo-dark.svg" alt="Logo"><img class="brandLogo brandLogoLight" src="/assets/img/logo-light.svg" alt="Logo">WEB- Twilio</div></div>';
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

    $notifyCronToken = '';
    try {
        $pdoUi = getPdo($rootDir);
        $notifyCronToken = trim(appSettingGet($pdoUi, 'notify_cron_token', ''));
    } catch (\Throwable $e) {
    }
    if ($notifyCronToken === '' || $notifyCronToken === 'YOUR_TOKEN') {
        try {
            $notifyCronToken = bin2hex(random_bytes(24));
        } catch (\Throwable $e) {
            $notifyCronToken = sha1((string) microtime(true) . ':' . (string) mt_rand());
        }
        try {
            if (isset($pdoUi) && $pdoUi instanceof PDO) {
                appSettingSet($pdoUi, 'notify_cron_token', $notifyCronToken);
            }
        } catch (\Throwable $e) {
        }
    }

    $content = '<div class="appShell">';
    $content .= '<header class="appTop">';
    $content .= '<div class="brand"><img class="brandLogo brandLogoDark" src="/assets/img/logo-dark.svg" alt="Logo"><img class="brandLogo brandLogoLight" src="/assets/img/logo-light.svg" alt="Logo">WEB- Twilio</div>';
    $content .= '<div class="topActions">';
    $content .= '<button class="btn" type="button" id="navHamburger">Menu</button>';
    $content .= '<button class="btn" type="button" id="themeToggle"><span id="themeToggleIcon" aria-hidden="true"></span><span id="themeToggleLabel">Theme</span></button>';
$content .= '<input class="input" id="globalSearchInput" placeholder="Search" style="max-width:160px;font-size:13px;padding:6px 10px" autocomplete="off">';
     $content .= '<form method="post" action="/logout" style="margin:0"><button class="btn danger" type="submit">Logout</button></form>';
    $content .= '</div>';
    $content .= '</header>';

    $content .= '<div id="navOverlay"></div>';

    $content .= '<main class="dashboard">';

    $content .= '<aside class="nav">';
    $content .= '<div class="navTitle">Menu</div>';
    $content .= '<nav class="navLinks">';
    $content .= '<a class="navItem" href="#analytics" id="navAnalytics">Analytics</a>';
    $content .= '<a class="navItem" href="#inbox" id="navInbox" style="position:relative">Inbox<span id="navInboxDot" style="display:none;position:absolute;top:10px;right:10px;width:8px;height:8px;border-radius:999px;background:#ff5b6b"></span></a>';
    $content .= '<a class="navItem" href="#dialpad" id="navDialpad">Dialpad</a>';
    $content .= '<a class="navItem" href="#calls" id="navCalls">Calls</a>';
    $content .= '<a class="navItem" href="#voicemails" id="navVoicemails">Voicemails</a>';
    $content .= '<a class="navItem" href="#contacts" id="navContacts">Contacts</a>';
    $content .= '<a class="navItem" href="#broadcast" id="navBroadcast">Broadcast</a>';
    $content .= '<a class="navItem" href="#numbers" id="navNumbers">Numbers</a>';
    $content .= '<a class="navItem" href="#settings" id="navSettings">Settings</a>';
    $content .= '<a class="navItem" href="#users" id="navUsers">Users</a>';
    $content .= '<a class="navItem" href="#roles" id="navRoles">Roles</a>';
    $content .= '<div class="navSpacer"></div>';
    $content .= '</aside>';

    $content .= '<section class="view" id="viewAnalytics">';
    $content .= '<div class="pageHeader"><div class="pageTitle">Analytics</div><div class="small">Quick analytics</div></div>';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Overview</div>';
    $content .= '<button class="btn" type="button" id="refreshAnalytics">Refresh</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div id="analyticsQuick"></div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="view" id="viewInbox" style="display:none">';
    $content .= '<div class="inboxGrid">';

    $content .= '<aside class="sidebar">';
    $content .= '<div class="panelHeader">';
    $content .= '<div class="panelTitle">Inbox</div>';
    $content .= '<div class="small" id="convCount"></div>';
    $content .= '</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="searchInput" placeholder="Search contacts or numbers" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">';
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
    $content .= '<select class="input" id="inboxTemplate" style="max-width:260px"></select>';
    $content .= '</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="row" style="align-items:flex-end">';
    $content .= '<textarea class="input" id="messageBody" placeholder="Write a message" rows="2" style="resize:none;flex:1"></textarea>';
    $content .= '<button class="btn primary" type="button" id="sendBtn">Send</button>';
    $content .= '</div>';
    $content .= '<div class="row" style="align-items:center;margin-top:8px">';
    $content .= '<button class="btn" type="button" id="mmsPickBtn">Attach</button>';
    $content .= '<button class="btn" type="button" id="mmsClearBtn" style="display:none">Remove</button>';
    $content .= '<div class="small" id="mmsPickedLabel" style="display:none"></div>';
    $content .= '<input type="file" id="mmsFileInput" style="display:none" accept="image/*,video/*,audio/*,application/pdf">';
    $content .= '</div>';
    $content .= '<div class="small" id="inboxSmsCounter" style="margin-top:6px"></div>';
    $content .= '</div>';

    $content .= '</section>';

    $content .= '<aside class="rightPanel" id="rightPanel">';
    $content .= '<div class="panelHeader">';
    $content .= '<div class="panelTitle">Contact</div>';
    $content .= '<button class="btn" type="button" id="rightClose">Close</button>';
    $content .= '</div>';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<button class="btn" type="button" id="inboxEditOpen">Edit details</button>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small">Chat notes</div>';
    $content .= '<div class="notes" id="chatNotesList" style="margin-top:10px"></div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<textarea class="input" id="chatNoteBody" placeholder="Add a chat note" rows="2" style="resize:none"></textarea>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<button class="btn primary" type="button" id="addChatNote">Add chat note</button>';
    $content .= '</div>';

    $content .= '</aside>';

    $content .= '<div class="incomingModal" id="inboxEditModal" style="display:none">';
    $content .= '<div class="incomingCard" style="width:min(720px,calc(100vw - 28px))">';
    $content .= '<div class="row inboxEditHeader" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="incomingTitle">Edit contact</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="inboxEditSaveTop">Save</button>';
    $content .= '<button class="btn" type="button" id="inboxEditClose">Close</button>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small">First name</div>';
    $content .= '<input class="input" id="contactFirstName" placeholder="First name">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small">Last name</div>';
    $content .= '<input class="input" id="contactLastName" placeholder="Last name">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small">Display name</div>';
    $content .= '<input class="input" id="contactName" placeholder="Company / display name">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small">Email</div>';
    $content .= '<input class="input" id="contactEmail" placeholder="email@domain.com">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small">Phone</div>';
    $content .= '<div id="contactPhone" class="small" style="margin-top:6px"></div>';
    $content .= '<div class="small" id="conversationToNumber" style="margin-top:8px"></div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small">Custom fields</div>';
    $content .= '<div id="inboxContactFields" style="margin-top:10px"></div>';
    $content .= '<div style="height:10px"></div>';
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
    $content .= '<div class="small">Contact notes</div>';
    $content .= '<div class="notes" id="contactNotesListModal" style="margin-top:10px"></div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<textarea class="input" id="contactNoteBodyModal" placeholder="Add a contact note" rows="2" style="resize:none"></textarea>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<button class="btn primary" type="button" id="addContactNoteModal">Add contact note</button>';
    $content .= '</div>';

    $content .= '</div>';
    $content .= '</div>';

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
    $content .= '<div class="row" style="align-items:flex-end;flex-wrap:wrap">';
    $content .= '<div style="min-width:220px;flex:1">';
    $content .= '<div class="small" style="margin-bottom:6px">Search</div>';
    $content .= '<input class="input" id="callsSearch" placeholder="From, To, user email, SID...">';
    $content .= '</div>';
    $content .= '<div style="min-width:160px">';
    $content .= '<div class="small" style="margin-bottom:6px">Direction</div>';
    $content .= '<select class="input" id="callsDirection">'
        . '<option value="">All</option>'
        . '<option value="inbound">Inbound</option>'
        . '<option value="outbound">Outbound</option>'
        . '</select>';
    $content .= '</div>';
    $content .= '<div style="min-width:160px">';
    $content .= '<div class="small" style="margin-bottom:6px">Status</div>';
    $content .= '<input class="input" id="callsStatus" placeholder="e.g. completed">';
    $content .= '</div>';
    $content .= '<div style="min-width:220px">';
    $content .= '<div class="small" style="margin-bottom:6px">User</div>';
    $content .= '<select class="input" id="callsUser">'
        . '<option value="">All</option>'
        . '</select>';
    $content .= '</div>';
    $content .= '<div style="min-width:160px">';
    $content .= '<div class="small" style="margin-bottom:6px">From</div>';
    $content .= '<input class="input" id="callsFromDate" type="date">';
    $content .= '</div>';
    $content .= '<div style="min-width:160px">';
    $content .= '<div class="small" style="margin-bottom:6px">To</div>';
    $content .= '<input class="input" id="callsToDate" type="date">';
    $content .= '</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="applyCallsFilters">Apply</button>';
    $content .= '<button class="btn" type="button" id="resetCallsFilters">Reset</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between;flex-wrap:wrap">';
    $content .= '<div class="small" id="callsSelectedCount">0 selected</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="callsSelectAllVisible">Select all visible</button>';
    $content .= '<button class="btn" type="button" id="callsClearSelection">Clear</button>';
    $content .= '<button class="btn danger" type="button" id="callsBulkDelete">Delete selected</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="callsList"></div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="view" id="viewVoicemails" style="display:none">';
    $content .= '<div class="pageHeader"><div class="pageTitle">Voicemails</div><div class="small">Admin: voicemail recordings</div></div>';
    $content .= '<div class="card">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Latest voicemails</div>';
    $content .= '<button class="btn" type="button" id="refreshVoicemailsMain">Refresh</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between;flex-wrap:wrap">';
    $content .= '<div class="small" id="voicemailsSelectedCount">0 selected</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="voicemailsSelectAllVisible">Select all visible</button>';
    $content .= '<button class="btn" type="button" id="voicemailsClearSelection">Clear</button>';
    $content .= '<button class="btn danger" type="button" id="voicemailsBulkDelete">Delete selected</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="voicemailsListMain"></div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="view" id="viewDialpad" style="display:none">';
    $content .= '<div class="pageHeader"><div class="pageTitle">Dial Pad</div><div class="small">Make calls from the browser</div></div>';
    $content .= '<div class="pageGrid">';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:8px">Voice status: <span id="voiceStatus2">Loading...</span></div>';
    $content .= '<select class="input" id="dialFromNumberSelect" style="max-width:260px"></select>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="dialInput" placeholder="+1..." inputmode="tel" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">';
    $content .= '<div class="dial">';
    foreach (["1","2","3","4","5","6","7","8","9","*","0","#"] as $k) {
        $content .= '<button class="key" type="button" data-k="' . h($k) . '">' . h($k) . '</button>';
    }
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn primary" type="button" id="callBtn"><span aria-hidden="true" style="display:inline-flex;align-items:center">'
        . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6.6 10.8c1.5 3 3.6 5.1 6.6 6.6l2.2-2.2c.3-.3.8-.4 1.2-.2 1 .4 2.2.6 3.4.6.7 0 1.3.6 1.3 1.3V20c0 .7-.6 1.3-1.3 1.3C10.4 21.3 2.7 13.6 2.7 4c0-.7.6-1.3 1.3-1.3h3.6c.7 0 1.3.6 1.3 1.3 0 1.2.2 2.3.6 3.4.1.4 0 .9-.3 1.2L6.6 10.8z" fill="currentColor"/></svg>'
        . '</span> <span>Call</span></button>';
    $content .= '<button class="btn" type="button" id="clearBtn">Clear</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div class="card">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Quick analytics</div>';
    $content .= '<button class="btn" type="button" id="refreshDialpadAnalytics">Refresh</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div id="dialpadAnalytics"></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="view" id="viewSettings" style="display:none">';
    $content .= '<div class="pageHeader"><div class="pageTitle">Settings</div><div class="small">Configuration</div></div>';

    $content .= '<div class="row" id="settingsTabs" style="margin-top:12px;gap:8px;flex-wrap:wrap">';
    $content .= '<button class="btn" type="button" data-stab="twilio">Twilio</button>';
    $content .= '<button class="btn" type="button" data-stab="email">Email</button>';
    $content .= '<button class="btn" type="button" data-stab="voice">Voice</button>';
    $content .= '<button class="btn" type="button" data-stab="general">General</button>';
    $content .= '<button class="btn" type="button" data-stab="automations">Automations</button>';
    $content .= '<button class="btn" type="button" data-stab="custom_fields">Custom Fields</button>';
    $content .= '<button class="btn" type="button" data-stab="addons">Addons</button>';
    $content .= '</div>';

    $content .= '<div id="settingsSectionCustomFields" class="settingsSection" data-stab="custom_fields" style="display:none">';

    $content .= '<div class="row" style="margin-top:12px;align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Custom Fields</div>';
    $content .= '<button class="btn" type="button" id="refreshSettingsCustomFields">Refresh</button>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small" style="margin-bottom:10px">Tags</div>';
    $content .= '<div class="row">';
    $content .= '<input class="input" id="newTagName" placeholder="New tag name" style="flex:1">';
    $content .= '<button class="btn primary" type="button" id="addTagBtn">Add</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="tagsList"></div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small" style="margin-bottom:10px">Groups</div>';
    $content .= '<div class="row">';
    $content .= '<input class="input" id="newGroupName" placeholder="New group name" style="flex:1">';
    $content .= '<button class="btn primary" type="button" id="addGroupBtn">Add</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="groupsList"></div>';
    $content .= '</div>';

    $content .= '<div class="card" id="contactFieldsAdminCard" style="display:none">';
    $content .= '<div class="small" style="margin-bottom:10px">Custom fields</div>';
    $content .= '<div class="row">';
    $content .= '<input class="input" id="newContactFieldKey" placeholder="field_key (e.g. first_name)" style="flex:1">';
    $content .= '<input class="input" id="newContactFieldLabel" placeholder="Label (e.g. First name)" style="flex:1">';
    $content .= '<button class="btn primary" type="button" id="addContactFieldBtn">Add</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="contactFieldsList"></div>';
    $content .= '<div class="small" style="margin-top:10px">Default tags: {first_name} {last_name} {email} {phone_number}. Custom fields: {field_key}</div>';
    $content .= '</div>';

    $content .= '</div>';

    $content .= '<div id="settingsSectionAddons" class="settingsSection" data-stab="addons" style="display:none">';
    $content .= '<div class="row" style="margin-top:12px;align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Addons</div>';
    $content .= '<button class="btn" type="button" id="refreshSettingsAddons">Refresh</button>';
    $content .= '</div>';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Addons</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="addonsList"></div>';
    $content .= '<div class="small" style="margin-top:10px">You will be able to purchase addons and activate them with a license key here later.</div>';
    $content .= '</div>';

    $content .= '</div>';

    $content .= '<div id="settingsSectionTwilio" class="settingsSection" data-stab="twilio">';

    $content .= '<div class="row" style="margin-top:12px;align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Twilio</div>';
    $content .= '<button class="btn" type="button" id="refreshSettingsTwilio">Refresh</button>';
    $content .= '</div>';

    $content .= '<div class="card">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Twilio Accounts (credential profiles)</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="pageGrid">';
    $content .= '<div class="card">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small" style="margin-bottom:10px">Add / update profile</div>';
    $content .= '<button class="btn" type="button" id="twilioOptionalInfo">i</button>';
    $content .= '</div>';
    $content .= '<input class="input" id="taName" placeholder="Profile name (unique)">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="taAccountSid" placeholder="Account SID">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="taAuthToken" placeholder="Auth Token">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="taApiKey" placeholder="API SID">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="taApiSecret" placeholder="API Secret">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="taTwimlAppSid" placeholder="TwiML App SID">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="taDefaultFrom" placeholder="Default From Number">';
    $content .= '<div style="height:12px"></div>';
    $content .= '<button class="btn primary" type="button" id="addTwilioAccountBtn">Save profile</button>';
    $content .= '</div>';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:10px">Saved profiles</div>';
    $content .= '<div class="list" id="twilioAccountsList"></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div class="card" id="defaultTwilioCard" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Default Twilio profile</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn primary" type="button" id="saveDefaultTwilio">Save</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<select class="input" id="defaultTwilioAccount"></select>';
    $content .= '<div class="small" style="margin-top:10px">Used when a number does not have an account profile selected.</div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Webhooks</div>';
    $content .= '<button class="btn" type="button" id="showWebhooksInfo">i</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="small" style="margin-bottom:6px">SMS webhook (incoming)</div>';
    $content .= '<input class="input" id="twilioSmsWebhookUrl" name="twilioSmsWebhookUrl" readonly value="' . h(baseUrl() . '/webhooks/twilio/sms') . '" onclick="this.select()">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small" style="margin-bottom:6px">SMS status callback (delivery receipts)</div>';
    $content .= '<input class="input" id="twilioSmsStatusCallbackUrl" name="twilioSmsStatusCallbackUrl" readonly value="' . h(baseUrl() . '/webhooks/twilio/sms/status') . '" onclick="this.select()">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small" style="margin-bottom:6px">Voice webhook (TwiML App Voice URL)</div>';
    $content .= '<input class="input" id="twilioVoiceWebhookUrl" name="twilioVoiceWebhookUrl" readonly value="' . h(baseUrl() . '/webhooks/twilio/voice') . '" onclick="this.select()">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small" style="margin-bottom:6px">Voice status callback</div>';
    $content .= '<input class="input" id="twilioVoiceStatusCallbackUrl" name="twilioVoiceStatusCallbackUrl" readonly value="' . h(baseUrl() . '/webhooks/twilio/voice/status') . '" onclick="this.select()">';
    $content .= '</div>';

    $content .= '</div>';

    $content .= '<div id="settingsSectionEmail" class="settingsSection" data-stab="email" style="display:none">';

    $content .= '<div class="row" style="margin-top:12px;align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Email</div>';
    $content .= '<button class="btn" type="button" id="refreshSettingsEmail">Refresh</button>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">SMTP email</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn primary" type="button" id="saveSmtp">Save</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<label class="small" style="display:block;margin-bottom:10px"><input type="checkbox" id="smtpEnabled"> Enable SMTP (recommended)</label>';
    $content .= '<div class="pageGrid">';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:8px">Server</div>';
    $content .= '<input class="input" id="smtpHost" placeholder="SMTP host">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="smtpPort" placeholder="SMTP port (e.g. 587)">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<select class="input" id="smtpSecure"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="none">None</option></select>';
    $content .= '</div>';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:8px">Auth + From</div>';
    $content .= '<input class="input" id="smtpUsername" placeholder="SMTP username (optional)">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="smtpPassword" placeholder="SMTP password (leave blank to keep unchanged)" type="password">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="smtpFromEmail" placeholder="From email (optional)">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="smtpFromName" placeholder="From name (optional)">';
    $content .= '</div>';
    $content .= '<div class="row" style="margin-top:10px;align-items:flex-end">';
    $content .= '<input class="input" id="smtpTestTo" placeholder="Test email to (e.g. you@company.com)" style="flex:1">';
    $content .= '<button class="btn" type="button" id="sendSmtpTest">Send test</button>';
    $content .= '</div>';
    $content .= '<div class="small" style="margin-top:10px">Notifications sent: Voicemail alerts (sent to all admin users). Password is stored encrypted in the database.</div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div id="settingsSectionGeneral" class="settingsSection" data-stab="general" style="display:none">';

    $content .= '<div class="row" style="margin-top:12px;align-items:center;justify-content:space-between">';
    $content .= '<div class="small">General</div>';
    $content .= '<button class="btn" type="button" id="refreshSettingsGeneral">Refresh</button>';
    $content .= '</div>';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Licenses</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="small" id="updateInfoSummary"></div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="small" id="coreLicenseSummary"></div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="licensesList"></div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Timezone</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn primary" type="button" id="saveTimezone">Save</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<select class="input" id="appTimezone"></select>';
    $content .= '<div class="small" style="margin-top:10px">Used for scheduling campaigns and displaying times.</div>';
    $content .= '<div class="small" style="margin-top:6px">Current time: <span id="timezoneNow"></span></div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $cronUrl = baseUrl() . '/api/cron/run?token=' . urlencode($notifyCronToken);
    $cronCurl = 'curl -fsS "' . $cronUrl . '" > /dev/null 2>&1';
    $cronWget = 'wget -q -O- "' . $cronUrl . '" > /dev/null 2>&1';
    $cronCrontab = '*/1 * * * * ' . $cronCurl;
    $content .= '<div class="small" style="margin-bottom:10px">Cron jobs</div>';
    $content .= '<div class="small" style="margin-bottom:6px">Cron URL (notifications + broadcasts)</div>';
    $content .= '<input class="input" readonly value="' . h($cronUrl) . '" onclick="this.select()">';
    $content .= '<div class="small" style="margin-top:6px">(Same token is used for both cron endpoints.)</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small" style="margin-bottom:6px">Command (cURL)</div>';
    $content .= '<input class="input" readonly value="' . h($cronCurl) . '" onclick="this.select()">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small" style="margin-bottom:6px">Command (wget)</div>';
    $content .= '<input class="input" readonly value="' . h($cronWget) . '" onclick="this.select()">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small" style="margin-bottom:6px">Crontab (recommended: every 1 minute)</div>';
    $content .= '<input class="input" readonly value="' . h($cronCrontab) . '" onclick="this.select()">';
    $content .= '<div class="small" style="margin-top:10px">Recommended interval: every 1 minute.</div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small" style="margin-bottom:10px">Database</div>';
    $content .= '<a class="btn" href="/migrate" target="_blank">Run migration</a>';
    $content .= '<div class="small" style="margin-top:10px">Only run after an update, when instructed.</div>';
    $content .= '</div>';

    $content .= '</div>';

    $content .= '<div id="settingsSectionAutomations" class="settingsSection" data-stab="automations" style="display:none">';
    $content .= '<div class="row" style="margin-top:12px;align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Automations</div>';
    $content .= '<button class="btn" type="button" id="refreshSettingsAutomations">Refresh</button>';
    $content .= '</div>';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">SMS opt-out (STOP)</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn primary" type="button" id="saveOptOut">Save</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<label class="small" style="display:block;margin-bottom:10px"><input type="checkbox" id="smsOptOutEnabled"> Enable STOP/START opt-out automation</label>';
    $content .= '<div class="small">Keywords: STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT to opt out. START, YES, UNSTOP to opt back in.</div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Notification rules</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="small">Role</div>';
    $content .= '<select class="input" id="notifRoleSelect"></select>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small" style="margin-bottom:10px">Events</div>';
    $content .= '<div class="list" id="notifEventsList"></div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<button class="btn primary" type="button" id="notifSaveRule">Save</button>';
    $content .= '<div class="small" style="margin-top:10px">Reminder cron: call ' . h($cronUrl) . ' (token stored in app_settings: notify_cron_token)</div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small" style="margin-bottom:10px">Inbox notifications</div>';
    $content .= '<label class="small" style="display:block;margin-bottom:10px"><input type="checkbox" id="smsNotifySound"> Play sound for new SMS</label>';
    $content .= '<label class="small" style="display:block;margin-bottom:10px"><input type="checkbox" id="smsNotifyDesktop"> Desktop notification for new SMS</label>';
    $content .= '<div class="small">Desktop notifications require browser permission.</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div id="settingsSectionVoice" class="settingsSection" data-stab="voice" style="display:none">';

    $content .= '<div class="row" style="margin-top:12px;align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Voice</div>';
    $content .= '<button class="btn" type="button" id="refreshSettingsVoice">Refresh</button>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Voice routing (inbound fallback)</div>';
    $content .= '<div class="row">';
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
    $content .= '</div>';

    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '</section>';

    $content .= '<section class="view" id="viewContacts" style="display:none">';
    $content .= '<div class="pageHeader"><div class="pageTitle">Contacts</div><div class="small">Search and edit contacts</div></div>';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<input class="input" id="contactsSearch" placeholder="Search name or number" style="flex:1" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">';
    $content .= '<select class="input" id="contactsFilterGroup" style="max-width:220px">';
    $content .= '<option value="">All groups</option>';
    $content .= '</select>';
    $content .= '<select class="input" id="contactsFilterTag" style="max-width:220px">';
    $content .= '<option value="">All tags</option>';
    $content .= '</select>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="exportContactsBtn">Export</button>';
    $content .= '<button class="btn" type="button" id="importContactsBtn">Import</button>';
    $content .= '<input type="file" id="importContactsFile" accept=".csv,text/csv" style="display:none">';
    $content .= '<button class="btn" type="button" id="openAddContactModal">Add contact</button>';
    $content .= '<button class="btn primary" type="button" id="saveContactsAll">Save all</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between;flex-wrap:wrap">';
    $content .= '<div class="small" id="contactsSelectedCount">0 selected</div>';
    $content .= '<div class="row" style="flex-wrap:wrap;align-items:center;gap:8px;flex:1">';
    $content .= '<button class="btn" type="button" id="contactsSelectAllVisible">Select all visible</button>';
    $content .= '<button class="btn" type="button" id="contactsClearSelection">Clear</button>';
    $content .= '<select class="input" id="contactsBulkGroup" style="max-width:220px"></select>';
    $content .= '<button class="btn" type="button" id="contactsBulkAddGroup">Add group</button>';
    $content .= '<select class="input" id="contactsBulkTag" style="max-width:220px"></select>';
    $content .= '<button class="btn" type="button" id="contactsBulkAddTag">Add tag</button>';
    $content .= '</div>';
    $content .= '<button class="btn danger" type="button" id="contactsBulkDelete">Delete selected</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="card">';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="contactsList"></div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<div class="incomingModal" id="addContactModal" style="display:none">';
    $content .= '<div class="incomingCard" style="width:min(720px,calc(100vw - 28px))">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between;position:sticky;top:0;z-index:2;padding-bottom:10px;background:inherit">';
    $content .= '<div class="incomingTitle">Add contact</div>';
    $content .= '<button class="btn" type="button" id="closeAddContactModal">Close</button>';
    $content .= '</div>';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<input class="input" id="newContactPhone" placeholder="Phone number (+1...)">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="row">';
    $content .= '<input class="input" id="newContactFirstName" placeholder="First name" style="flex:1">';
    $content .= '<input class="input" id="newContactLastName" placeholder="Last name" style="flex:1">';
    $content .= '</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="row">';
    $content .= '<input class="input" id="newContactName" placeholder="Display name (optional)" style="flex:1">';
    $content .= '<input class="input" id="newContactEmail" placeholder="Email" style="flex:1">';
    $content .= '</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<button class="btn primary" type="button" id="addContactBtn">Add</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<section class="view" id="viewBroadcast" style="display:none">';
    $content .= '<div class="pageHeader"><div class="pageTitle">Broadcast</div><div class="small">Bulk messaging (preview first)</div></div>';

    $content .= '<div class="row" id="broadcastTabs" style="margin-top:12px;gap:8px;flex-wrap:wrap">';
    $content .= '<button class="btn" type="button" data-btab="send">Send</button>';
    $content .= '<button class="btn" type="button" data-btab="history">Campaigns</button>';
    $content .= '<button class="btn" type="button" data-btab="analytics">Analytics</button>';
    $content .= '<button class="btn" type="button" data-btab="templates">Templates</button>';
    $content .= '</div>';

    $content .= '<div id="broadcastSectionCampaigns" class="broadcastSection" data-btab="send">';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small" style="margin-bottom:10px">Audience</div>';
    $content .= '<select class="input" id="broadcastAudienceMode">'
        . '<option value="all">All contacts</option>'
        . '<option value="search">Search filter</option>'
        . '<option value="group">Group</option>'
        . '<option value="tag">Tag</option>'
        . '<option value="paste">Paste numbers</option><option value="contacts">Selected contacts (0)</option>'
        . '</select>';
    $content .= '<div id="broadcastAudienceSearch" style="margin-top:10px;display:none">';
    $content .= '<input class="input" id="broadcastQuery" placeholder="Search filter (name, number, email)" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">';
    $content .= '</div>';
    $content .= '<div id="broadcastAudienceGroup" style="margin-top:10px;display:none">';
    $content .= '<select class="input" id="broadcastGroupSelect"></select>';
    $content .= '</div>';
    $content .= '<div id="broadcastAudienceTag" style="margin-top:10px;display:none">';
    $content .= '<select class="input" id="broadcastTagSelect"></select>';
    $content .= '</div>';
    $content .= '<div id="broadcastAudiencePaste" style="margin-top:10px;display:none">';
    $content .= '<textarea class="input" id="broadcastPasteNumbers" placeholder="One phone number per line (+1...)" rows="4" style="resize:none"></textarea>';
    $content .= '</div>';
    $content .= '<div class="small" style="margin-top:10px">Preview will exclude opted-out recipients if STOP/START automation is enabled.</div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small" style="margin-bottom:10px">Message</div>';
    $content .= '<div class="row" style="align-items:flex-end;flex-wrap:wrap">';
    $content .= '<div style="min-width:260px;flex:1">';
    $content .= '<div class="small" style="margin-bottom:6px">Template</div>';
    $content .= '<select class="input" id="broadcastTemplate"></select>';
    $content .= '</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="broadcastTemplateSaveBtn">Save template</button>';
    $content .= '<button class="btn danger" type="button" id="broadcastTemplateDeleteBtn">Delete</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<select class="input" id="broadcastMergeField"></select>';
    $content .= '<div class="small" style="margin-top:6px">Click a field to insert into the message.</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<select class="input" id="broadcastFromNumber"></select>';
    $content .= '<div style="height:10px"></div>';

    $content .= '<div class="row" style="align-items:center;gap:10px;margin-bottom:6px">';
    $content .= '<div class="small">Throttling</div>';
    $content .= '<label class="small" style="display:flex;align-items:center;gap:8px">'
        . '<input type="checkbox" id="broadcastThrottleEnabled">'
        . '<span>Enable</span>'
        . '</label>';
    $content .= '</div>';
    $content .= '<div id="broadcastThrottleBox" style="display:none">';
    $content .= '<div class="row" style="flex-wrap:wrap">';
    $content .= '<div style="min-width:220px;flex:1">';
    $content .= '<div class="small" style="margin-bottom:6px">Batch size <span class="tip"><span class="badge tipIcon">?</span><span class="tipContent">Batch size controls how many recipients are sent per run.<br><br><strong>Scheduled</strong>: per cron tick<br><strong>Send now</strong>: per immediate run<br><br>Smaller batches reduce timeouts and traffic spikes.</span></span></div>';
    $content .= '<input class="input" id="broadcastBatchSize" type="number" min="1" max="500" step="1" value="50">';
    $content .= '</div>';
    $content .= '<div style="min-width:220px;flex:1">';
    $content .= '<div class="small" style="margin-bottom:6px">Delay per message (ms) <span class="tip"><span class="badge tipIcon">?</span><span class="tipContent">Adds a pause after each message send.<br><br>Helps smooth traffic, reduce filtering spikes, and avoid server overload on shared hosting.</span></span></div>';
    $content .= '<input class="input" id="broadcastSendDelayMs" type="number" min="0" max="5000" step="10" value="0">';
    $content .= '<div class="small" style="margin-top:8px">Example: Batch 25 + Delay 200ms.</div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:10px"></div>';

    $content .= '<div class="small" style="margin-bottom:6px">Send</div>';
    $content .= '<select class="input" id="broadcastSendMode">'
        . '<option value="now">Send now</option>'
        . '<option value="schedule">Schedule</option>'
        . '</select>';
    $content .= '<div id="broadcastScheduleBox" style="display:none;margin-top:10px">';
    $content .= '<div class="pageGrid">';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:6px">Date</div>';
    $content .= '<input class="input" id="broadcastScheduleDate" type="date">';
    $content .= '</div>';
    $content .= '<div class="card">';
    $content .= '<div class="small" style="margin-bottom:6px">Time</div>';
    $content .= '<input class="input" id="broadcastScheduleTime" type="time">';
    $content .= '<div class="small" style="margin-top:8px">Times use your app timezone (Settings → General).</div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<textarea class="input" id="broadcastBody" placeholder="Write your message" rows="4" style="resize:none"></textarea>';
    $content .= '<div class="small" id="broadcastSmsCounter" style="margin-top:6px"></div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="broadcastPreviewBtn">Preview</button>';
    $content .= '<button class="btn danger" type="button" id="broadcastSendBtn">Send</button>';
    $content .= '</div>';
    $content .= '<div class="small" style="margin-top:10px">Merge tags supported: {first_name} {last_name} {name} {email} {phone_number} and custom fields {field_key}.</div>';
    $content .= '</div>';

    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small">Preview</div>';
    $content .= '<div id="broadcastPreview" style="margin-top:10px"></div>';
    $content .= '</div>';

    $content .= '</div>';

    $content .= '<div id="broadcastSectionHistory" class="broadcastSection" data-btab="history" style="display:none">';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Campaigns</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="broadcastJobsRefresh">Refresh</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="list" id="broadcastJobsList"></div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div id="broadcastSectionAnalytics" class="broadcastSection" data-btab="analytics" style="display:none">';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">';
    $content .= '<div class="small">Analytics</div>';
    $content .= '<div class="row" style="gap:8px;flex-wrap:wrap">';
    $content .= '<select class="input" id="broadcastJobSelect" style="min-width:240px"></select>';
    $content .= '<button class="btn" type="button" id="broadcastJobRefresh">Refresh</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div id="broadcastJobSummary"></div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div id="broadcastJobCounts"></div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div id="broadcastJobErrors"></div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div id="broadcastJobSample"></div>';
    $content .= '</div>';
    $content .= '</div>';

    $content .= '<div id="broadcastSectionTemplates" class="broadcastSection" data-btab="templates" style="display:none">';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Templates</div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn" type="button" id="broadcastTemplatesRefresh">Refresh</button>';
    $content .= '<button class="btn primary" type="button" id="broadcastTemplatesNew">New</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<select class="input" id="broadcastTemplatesSelect"></select>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="small">Name</div>';
    $content .= '<input class="input" id="broadcastTemplateName" placeholder="Template name">';
    $content .= '<div style="height:10px"></div>';
    $content .= '<select class="input" id="broadcastTemplateMergeField"></select>';
    $content .= '<div class="small" style="margin-top:6px">Click a field to insert into the template.</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<div class="small">Body</div>';
    $content .= '<textarea class="input" id="broadcastTemplateBody" placeholder="Template message" rows="6" style="resize:vertical"></textarea>';
    $content .= '<div class="small" id="broadcastTemplateSmsCounter" style="margin-top:6px"></div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="row">';
    $content .= '<button class="btn primary" type="button" id="broadcastTemplatesSave">Save</button>';
    $content .= '<button class="btn danger" type="button" id="broadcastTemplatesDelete">Delete</button>';
    $content .= '</div>';
    $content .= '</div>';
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
    $content .= '<div class="small" style="margin-bottom:10px">RBAC role assignments (in addition to legacy user.role)</div>';
    $content .= '<div class="list" id="usersList"></div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</section>';

    $content .= '<section class="view" id="viewRoles" style="display:none">';
    $content .= '<div class="pageHeader"><div class="pageTitle">Roles</div><div class="small">Admin: manage roles & permissions</div></div>';
    $content .= '<div class="pageGrid">';
    $content .= '<div class="card">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="small">Roles & permissions</div>';
    $content .= '<button class="btn" type="button" id="refreshRbac">Refresh</button>';
    $content .= '</div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="row" style="align-items:center;gap:10px">';
    $content .= '<select class="input" id="rbacRoleSelect" style="flex:1"></select>';
    $content .= '<button class="btn" type="button" id="rbacNewRole">New role</button>';
    $content .= '<button class="btn danger" type="button" id="rbacDeleteRole">Delete</button>';
    $content .= '</div>';
    $content .= '<div style="height:10px"></div>';
    $content .= '<input class="input" id="rbacRoleName" placeholder="Role name">';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="small" style="margin-bottom:10px">Permissions</div>';
    $content .= '<div class="list" id="rbacPermissionsList"></div>';
    $content .= '<div style="height:12px"></div>';
    $content .= '<button class="btn primary" type="button" id="rbacSaveRolePerms">Save permissions</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</section>';
    $content .= '</main>';
    $content .= '</div>';

    $content .= '<div class="callBar" id="callBar" style="display:none">';
    $content .= '<div>';
    $content .= '<div class="callTitle" id="callBarStatus">Call</div>';
    $content .= '<div class="small">Duration: <span id="callBarTimer">00:00</span></div>';
    $content .= '<div class="small">DTMF: <span id="callDtmfLog"></span></div>';
    $content .= '</div>';
    $content .= '<div id="callDtmfPad" style="display:none;gap:6px;flex-wrap:wrap;max-width:220px">';
    foreach (['1','2','3','4','5','6','7','8','9','*','0','#'] as $k) {
        $content .= '<button class="btn" type="button" data-dtmf="' . h($k) . '" style="width:64px">' . h($k) . '</button>';
    }
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

    $content .= '<div class="incomingModal" id="rbacNewRoleModal" style="display:none">';
    $content .= '<div class="incomingCard" style="width:min(560px,calc(100vw - 28px))">';
    $content .= '<div class="row" style="align-items:center;justify-content:space-between">';
    $content .= '<div class="incomingTitle">Create role</div>';
    $content .= '<button class="btn" type="button" id="rbacNewRoleClose">Close</button>';
    $content .= '</div>';
    $content .= '<div class="card" style="margin-top:12px">';
    $content .= '<div class="small">Role name</div>';
    $content .= '<input class="input" id="rbacNewRoleName" placeholder="e.g. Manager">';
    $content .= '<div style="height:12px"></div>';
    $content .= '<div class="row" style="justify-content:flex-end">';
    $content .= '<button class="btn" type="button" id="rbacNewRoleCancel">Cancel</button>';
    $content .= '<button class="btn primary" type="button" id="rbacNewRoleCreate">Create</button>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';
    $content .= '</div>';

    $appJsVer = '';
    try {
        $appJsVer = (string) (@filemtime(__DIR__ . '/app.js') ?: '');
    } catch (\Throwable $e) {
        $appJsVer = '';
    }
    $content .= '<script src="/app.js?v=' . h($appJsVer) . '"></script>';

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

    $rows = [];
    try {
        $stmt = $pdo->query('SELECT migration_key, description, applied_at FROM schema_migrations ORDER BY applied_at DESC, id DESC LIMIT 50');
        $rows = $stmt ? $stmt->fetchAll() : [];
    } catch (\Throwable $e) {
        $rows = [];
    }

    $html = '<div class="topbar"><div class="brand">Migration</div></div>';
    $html .= '<div class="ok">Migration complete.</div>';
    $html .= '<div class="card" style="margin-top:12px">';
    $html .= '<div class="small" style="margin-bottom:10px">Recent changes</div>';
    if (!$rows) {
        $html .= '<div class="small">No migration history yet.</div>';
    } else {
        $html .= '<div class="list">';
        foreach ($rows as $r) {
            $k = h((string)($r['migration_key'] ?? ''));
            $d = h((string)($r['description'] ?? ''));
            $t = h((string)($r['applied_at'] ?? ''));
            $html .= '<div class="item"><div><strong>' . $d . '</strong></div><div class="small" style="margin-top:6px">' . $k . ' • ' . $t . '</div></div>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    render('Migrate', $html);
    exit;
}

if ($uri === '/api/voice/token' && $method === 'GET') {
    Auth::requireLogin();
    $pdo = getPdo($rootDir);

    $uid = Auth::userId();
    if ($uid === null) {
        json(['error' => 'Not authenticated'], 401);
    }

    $fromNumberId = (int) ($_GET['from_number_id'] ?? $_GET['FromNumberId'] ?? 0);
    $fromTwilioAccountId = 0;
    if ($fromNumberId > 0) {
        try {
            $stmt = $pdo->prepare('SELECT n.twilio_account_id
                FROM numbers n
                INNER JOIN user_numbers un ON un.number_id = n.id
                WHERE un.user_id = :uid AND n.id = :nid
                LIMIT 1');
            $stmt->execute([':uid' => $uid, ':nid' => $fromNumberId]);
            $row = $stmt->fetch();
            $fromTwilioAccountId = (int) (($row['twilio_account_id'] ?? 0) ?: 0);
        } catch (\Throwable $e) {
            $fromTwilioAccountId = 0;
        }
    }

    $accountSid = '';
    $apiKey = '';
    $apiSecret = '';
    $appSid = '';
    if ($fromTwilioAccountId > 0) {
        try {
            $stmt = $pdo->prepare('SELECT account_sid, api_key, api_secret, twiml_app_sid FROM twilio_accounts WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $fromTwilioAccountId]);
            $acc = $stmt->fetch();
            if (is_array($acc)) {
                $accountSid = (string) (($acc['account_sid'] ?? '') ?: '');
                $apiKey = (string) (($acc['api_key'] ?? '') ?: '');
                $apiSecret = (string) (($acc['api_secret'] ?? '') ?: '');
                $appSid = (string) (($acc['twiml_app_sid'] ?? '') ?: '');
            }
        } catch (\Throwable $e) {
        }
    }

    if ($accountSid === '' || $apiKey === '' || $apiSecret === '' || $appSid === '') {
        $cfg = twilioConfig($pdo, $uid);
        $accountSid = (string) ($cfg['account_sid'] ?? '');
        $apiKey = (string) ($cfg['api_key'] ?? '');
        $apiSecret = (string) ($cfg['api_secret'] ?? '');
        $appSid = (string) ($cfg['twiml_app_sid'] ?? '');
    }

    if ($accountSid === '' || $apiKey === '' || $apiSecret === '' || $appSid === '') {
        json(['error' => 'Missing Twilio Voice credentials. For browser calling, set API SID/Secret + TwiML App SID on the Twilio profile assigned to your selected From number (or set a default Twilio profile in Settings).'], 500);
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

http_response_code(404);
render('Not Found', '<div class="topbar"><div class="brand">WEB- Twilio</div></div><div class="card"><h2 style="margin:0 0 8px 0">404</h2><div class="small">Page not found.</div></div>');
