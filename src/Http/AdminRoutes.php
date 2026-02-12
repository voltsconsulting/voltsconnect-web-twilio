<?php

declare(strict_types=1);

use App\Auth;

function handleAdminRoutes(string $uri, string $method, string $rootDir): bool
{
    if ($uri === '/api/admin/settings/timezone' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.view');

        json([
            'app_timezone' => appSettingGet($pdo, 'app_timezone', 'UTC'),
        ]);
        return true;
    }

    if ($uri === '/api/admin/settings/timezone' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }

        $tz = trim((string) ($payload['app_timezone'] ?? ''));
        if ($tz === '') {
            $tz = 'UTC';
        }
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            json(['error' => 'Invalid timezone'], 422);
        }

        appSettingSet($pdo, 'app_timezone', $tz);
        json(['ok' => true, 'app_timezone' => $tz]);
        return true;
    }

    if ($uri === '/api/admin/settings/smtp' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');

        json([
            'smtp_enabled' => appSettingGet($pdo, 'smtp_enabled', '0') === '1',
            'smtp_host' => appSettingGet($pdo, 'smtp_host', ''),
            'smtp_port' => (int) appSettingGet($pdo, 'smtp_port', '587'),
            'smtp_username' => appSettingGet($pdo, 'smtp_username', ''),
            'smtp_secure' => appSettingGet($pdo, 'smtp_secure', 'tls'),
            'smtp_from_email' => appSettingGet($pdo, 'smtp_from_email', ''),
            'smtp_from_name' => appSettingGet($pdo, 'smtp_from_name', ''),
            'has_password' => appSettingGet($pdo, 'smtp_password_enc', '') !== '',
        ]);
        return true;
    }

    if ($uri === '/api/admin/settings/smtp' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }

        $enabled = !empty($payload['smtp_enabled']) ? '1' : '0';
        $host = trim((string) ($payload['smtp_host'] ?? ''));
        $port = (int) ($payload['smtp_port'] ?? 587);
        if ($port <= 0) {
            $port = 587;
        }
        $user = trim((string) ($payload['smtp_username'] ?? ''));
        $pass = (string) ($payload['smtp_password'] ?? '');
        $secure = trim((string) ($payload['smtp_secure'] ?? 'tls'));
        if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
            $secure = 'tls';
        }
        $fromEmail = trim((string) ($payload['smtp_from_email'] ?? ''));
        $fromName = trim((string) ($payload['smtp_from_name'] ?? ''));

        appSettingSet($pdo, 'smtp_enabled', $enabled);
        appSettingSet($pdo, 'smtp_host', $host);
        appSettingSet($pdo, 'smtp_port', (string) $port);
        appSettingSet($pdo, 'smtp_username', $user);
        appSettingSet($pdo, 'smtp_secure', $secure);
        appSettingSet($pdo, 'smtp_from_email', $fromEmail);
        appSettingSet($pdo, 'smtp_from_name', $fromName);

        if (trim($pass) !== '') {
            appSettingSet($pdo, 'smtp_password_enc', encryptSecret($pass));
        }

        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/numbers/delete' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'numbers.manage');

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            json(['error' => 'id is required'], 422);
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM user_numbers WHERE number_id = :id')->execute([':id' => $id]);
            $pdo->prepare('UPDATE conversations SET default_number_id = NULL WHERE default_number_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM numbers WHERE id = :id')->execute([':id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            try { $pdo->rollBack(); } catch (\Throwable $e2) {}
            json(['error' => 'Delete failed'], 500);
        }

        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/settings/smtp/test' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');

        $enabled = appSettingGet($pdo, 'smtp_enabled', '0') === '1';
        if (!$enabled) {
            json(['error' => 'Enable SMTP first'], 400);
        }
        $host = trim(appSettingGet($pdo, 'smtp_host', ''));
        if ($host === '') {
            json(['error' => 'SMTP host is required'], 400);
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $toEmail = trim((string) ($payload['to_email'] ?? ''));
        if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            json(['error' => 'Enter a valid test email address'], 400);
        }

        $subject = 'VOLTS CONNECT test email';
        $body = "This is a test email from VOLTS CONNECT.\n\nIf you received this, your SMTP settings are working.";
        $ok = sendEmail($pdo, $toEmail, $subject, $body);
        if (!$ok) {
            json(['error' => 'Failed to send test email'], 500);
        }
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/numbers' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'numbers.view');

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
        return true;
    }

    if ($uri === '/api/admin/numbers/add' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'numbers.manage');

        $payload = json_decode((string) file_get_contents('php://input'), true);
        $pn = trim((string) ($payload['phone_number'] ?? ''));
        $name = trim((string) ($payload['friendly_name'] ?? ''));
        if ($pn === '') {
            json(['error' => 'phone_number is required'], 422);
        }

        $pdo->prepare('INSERT IGNORE INTO numbers (phone_number, friendly_name) VALUES (:pn, :fn)')
            ->execute([':pn' => $pn, ':fn' => ($name !== '' ? $name : null)]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/numbers/update' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'numbers.manage');

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
        return true;
    }

    if ($uri === '/api/admin/numbers/save' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'numbers.manage');

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
        return true;
    }

    if ($uri === '/api/admin/twilio-accounts' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');

        $stmt = $pdo->query('SELECT id, name, account_sid, twiml_app_sid, default_from_number, created_at
        FROM twilio_accounts
        ORDER BY name ASC');
        json(['accounts' => $stmt->fetchAll()]);
        return true;
    }

    if ($uri === '/api/admin/settings/default-twilio' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');

        json([
            'default_twilio_account_id' => (int) appSettingGet($pdo, 'default_twilio_account_id', '0'),
        ]);
        return true;
    }

    if ($uri === '/api/admin/settings/default-twilio' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');

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
        return true;
    }

    if ($uri === '/api/admin/twilio-accounts/add' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');

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
        return true;
    }

    if ($uri === '/api/admin/twilio-accounts/delete' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');

        $payload = json_decode((string) file_get_contents('php://input'), true);
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            json(['error' => 'id is required'], 422);
        }

        $pdo->prepare('DELETE FROM twilio_accounts WHERE id = :id')->execute([':id' => $id]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/settings/voice-routing' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');

        json([
            'voice_ring_timeout' => (int) appSettingGet($pdo, 'voice_ring_timeout', '20'),
            'voice_forward_number' => appSettingGet($pdo, 'voice_forward_number', ''),
            'voice_voicemail_enabled' => appSettingGet($pdo, 'voice_voicemail_enabled', '0') === '1',
            'voice_voicemail_greeting' => appSettingGet($pdo, 'voice_voicemail_greeting', 'Please leave a message after the tone.'),
            'voice_voicemail_max_length' => (int) appSettingGet($pdo, 'voice_voicemail_max_length', '60'),
            'voice_record_calls' => appSettingGet($pdo, 'voice_record_calls', '0') === '1',
        ]);
        return true;
    }

    if ($uri === '/api/admin/settings/voice-routing' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');

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
        return true;
    }

    if ($uri === '/api/admin/voicemails' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'voicemails.view');

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
        $rows = $stmt->fetchAll();
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $row = is_array($r) ? $r : [];
                $url = trim((string) (($row['recording_url'] ?? '') ?: ''));
                $sid = '';
                if ($url !== '' && preg_match('/\/Recordings\/(RE[a-zA-Z0-9]+)/', $url, $m)) {
                    $sid = (string) ($m[1] ?? '');
                }
                $row['recording_sid'] = $sid !== '' ? $sid : null;
                $row['recording_proxy_url'] = $sid !== '' ? ('/api/voice/recording?sid=' . rawurlencode($sid)) : null;
                $out[] = $row;
            }
        }
        json(['voicemails' => $out]);
        return true;
    }

    if ($uri === '/api/admin/voicemails/bulk-delete' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'voicemails.manage');

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $rawIds = $payload['voicemail_ids'] ?? null;
        if (!is_array($rawIds)) {
            json(['error' => 'voicemail_ids required'], 422);
        }

        $ids = [];
        foreach ($rawIds as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if (count($ids) === 0) {
            json(['error' => 'No valid voicemail_ids'], 422);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM voicemails WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_map('intval', $ids));
            $deleted = (int) $stmt->rowCount();
            $pdo->commit();
        } catch (\Throwable $e) {
            try {
                $pdo->rollBack();
            } catch (\Throwable $e2) {
            }
            json(['error' => 'Delete failed'], 500);
        }

        json(['ok' => true, 'deleted' => $deleted]);
        return true;
    }

    if ($uri === '/api/admin/numbers/assign' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'numbers.manage');

        $payload = json_decode((string) file_get_contents('php://input'), true);
        $userId = (int) ($payload['user_id'] ?? 0);
        $numberId = (int) ($payload['number_id'] ?? 0);
        if ($userId <= 0 || $numberId <= 0) {
            json(['error' => 'user_id and number_id required'], 422);
        }

        $pdo->prepare('INSERT IGNORE INTO user_numbers (user_id, number_id, is_default) VALUES (:uid, :nid, 0)')
            ->execute([':uid' => $userId, ':nid' => $numberId]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/numbers/unassign' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'numbers.manage');

        $payload = json_decode((string) file_get_contents('php://input'), true);
        $userId = (int) ($payload['user_id'] ?? 0);
        $numberId = (int) ($payload['number_id'] ?? 0);
        if ($userId <= 0 || $numberId <= 0) {
            json(['error' => 'user_id and number_id required'], 422);
        }

        $pdo->prepare('DELETE FROM user_numbers WHERE user_id = :uid AND number_id = :nid')
            ->execute([':uid' => $userId, ':nid' => $numberId]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/numbers/set-default' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'numbers.manage');

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
        return true;
    }

    if ($uri === '/api/admin/users' && $method === 'GET') {
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');
        $stmt = $pdo->query('SELECT id, email, role, is_active, created_at FROM users ORDER BY id ASC');
        json(['users' => $stmt->fetchAll()]);
        return true;
    }

    if ($uri === '/api/admin/users/create' && $method === 'POST') {
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');
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
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, role, is_active) VALUES (:email, :hash, :role, 1)');
        try {
            $stmt->execute([':email' => $email, ':hash' => $hash, ':role' => $role]);
        } catch (\Throwable $e) {
            json(['error' => 'Could not create user (email may already exist)'], 409);
        }
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/users/reset-password' && $method === 'POST') {
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');
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
        return true;
    }

    if ($uri === '/api/admin/users/set-role' && $method === 'POST') {
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');
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
        return true;
    }

    if ($uri === '/api/admin/users/set-active' && $method === 'POST') {
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $id = (int) ($payload['id'] ?? 0);
        $active = !empty($payload['is_active']) ? 1 : 0;
        if ($id <= 0) {
            json(['error' => 'id required'], 422);
        }
        $me = Auth::userId();
        if ($me !== null && $id === $me) {
            json(['error' => 'You cannot disable yourself'], 422);
        }

        if ($active === 0) {
            $row = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
            $row->execute([':id' => $id]);
            $r = $row->fetch();
            $role = (string) (($r['role'] ?? '') ?: '');
            if ($role === 'admin') {
                $cnt = (int) (($pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND is_active = 1")->fetch()['c'] ?? 0) ?: 0);
                if ($cnt <= 1) {
                    json(['error' => 'Cannot disable the last active admin'], 422);
                }
            }
        }

        $pdo->prepare('UPDATE users SET is_active = :a WHERE id = :id')->execute([':a' => $active, ':id' => $id]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/users/delete' && $method === 'POST') {
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            json(['error' => 'id required'], 422);
        }
        $me = Auth::userId();
        if ($me !== null && $id === $me) {
            json(['error' => 'You cannot delete yourself'], 422);
        }

        $row = $pdo->prepare('SELECT role, is_active FROM users WHERE id = :id LIMIT 1');
        $row->execute([':id' => $id]);
        $r = $row->fetch();
        if (!$r) {
            json(['ok' => true]);
        }
        $role = (string) (($r['role'] ?? '') ?: '');
        $isActive = (int) (($r['is_active'] ?? 0) ?: 0);
        if ($role === 'admin' && $isActive === 1) {
            $cnt = (int) (($pdo->query("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND is_active = 1")->fetch()['c'] ?? 0) ?: 0);
            if ($cnt <= 1) {
                json(['error' => 'Cannot delete the last active admin'], 422);
            }
        }

        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/contact-fields' && $method === 'GET') {
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');
        $stmt = $pdo->query('SELECT id, field_key, label, created_at FROM contact_fields ORDER BY id ASC');
        json(['fields' => $stmt->fetchAll()]);
        return true;
    }

    if ($uri === '/api/admin/contact-fields/add' && $method === 'POST') {
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $key = trim((string) ($payload['field_key'] ?? ''));
        $label = trim((string) ($payload['label'] ?? ''));
        if ($key === '' || $label === '') {
            json(['error' => 'field_key and label required'], 422);
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,63}$/', $key)) {
            json(['error' => 'field_key must be alphanumeric with underscores (2-64 chars)'], 422);
        }
        $pdo->prepare('INSERT INTO contact_fields (field_key, label) VALUES (:k, :l)')
            ->execute([':k' => $key, ':l' => $label]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/contact-fields/delete' && $method === 'POST') {
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            json(['error' => 'id required'], 422);
        }
        $pdo->prepare('DELETE FROM contact_fields WHERE id = :id')->execute([':id' => $id]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/rbac/permissions' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');
        $rows = $pdo->query('SELECT id, perm_key, label FROM permissions ORDER BY perm_key ASC')->fetchAll();
        json(['permissions' => $rows]);
        return true;
    }

    if ($uri === '/api/admin/rbac/roles' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');
        $roles = $pdo->query('SELECT id, team_id, name, created_at FROM roles WHERE team_id = 1 ORDER BY name ASC')->fetchAll();
        $allPerms = $pdo->query('SELECT perm_key FROM permissions ORDER BY perm_key ASC')->fetchAll();
        $allKeys = [];
        if (is_array($allPerms)) {
            foreach ($allPerms as $p) {
                $k = (string) (($p['perm_key'] ?? '') ?: '');
                if ($k !== '') {
                    $allKeys[] = $k;
                }
            }
        }
        $rp = $pdo->query('SELECT rp.role_id, p.perm_key
            FROM role_permissions rp
            INNER JOIN permissions p ON p.id = rp.permission_id')->fetchAll();
        $byRole = [];
        if (is_array($rp)) {
            foreach ($rp as $r) {
                $rid = (int) (($r['role_id'] ?? 0) ?: 0);
                $k = (string) (($r['perm_key'] ?? '') ?: '');
                if ($rid > 0 && $k !== '') {
                    if (!array_key_exists($rid, $byRole)) {
                        $byRole[$rid] = [];
                    }
                    $byRole[$rid][] = $k;
                }
            }
        }
        if (is_array($roles)) {
            foreach ($roles as $i => $r) {
                $rid = (int) (($r['id'] ?? 0) ?: 0);
                $name = (string) (($r['name'] ?? '') ?: '');
                $roles[$i]['system_locked'] = in_array($name, ['Admin'], true);
                if ($name === 'Admin') {
                    $roles[$i]['permission_keys'] = $allKeys;
                } else {
                    $roles[$i]['permission_keys'] = $byRole[$rid] ?? [];
                }
            }
        }
        json(['roles' => $roles]);
        return true;
    }

    if ($uri === '/api/admin/rbac/roles/save' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $id = (int) ($payload['id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            json(['error' => 'name required'], 422);
        }
        if ($id > 0) {
            $row = $pdo->prepare('SELECT name FROM roles WHERE id = :id AND team_id = 1 LIMIT 1');
            $row->execute([':id' => $id]);
            $r = $row->fetch();
            $roleName = (string) (($r['name'] ?? '') ?: '');
            if ($roleName === 'Admin') {
                json(['error' => 'Cannot edit Admin role'], 422);
            }
            $pdo->prepare('UPDATE roles SET name = :n WHERE id = :id AND team_id = 1')->execute([':n' => $name, ':id' => $id]);
            json(['ok' => true, 'id' => $id]);
            return true;
        }
        try {
            $pdo->prepare('INSERT INTO roles (team_id, name) VALUES (1, :n)')->execute([':n' => $name]);
        } catch (\Throwable $e) {
            json(['error' => 'Role name already exists'], 409);
        }
        json(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
        return true;
    }

    if ($uri === '/api/admin/rbac/roles/delete' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            json(['error' => 'id required'], 422);
        }
        $row = $pdo->prepare('SELECT name FROM roles WHERE id = :id AND team_id = 1 LIMIT 1');
        $row->execute([':id' => $id]);
        $r = $row->fetch();
        $roleName = (string) (($r['name'] ?? '') ?: '');
        if (in_array($roleName, ['Admin'], true)) {
            json(['error' => 'Cannot delete Admin role'], 422);
        }
        $pdo->prepare('DELETE FROM roles WHERE id = :id AND team_id = 1')->execute([':id' => $id]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/rbac/roles/set-permissions' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $roleId = (int) ($payload['role_id'] ?? 0);
        $keys = $payload['permission_keys'] ?? null;
        if ($roleId <= 0 || !is_array($keys)) {
            json(['error' => 'role_id and permission_keys required'], 422);
        }

        $row = $pdo->prepare('SELECT name FROM roles WHERE id = :id AND team_id = 1 LIMIT 1');
        $row->execute([':id' => $roleId]);
        $r = $row->fetch();
        $roleName = (string) (($r['name'] ?? '') ?: '');
        if ($roleName === 'Admin') {
            json(['error' => 'Admin role always has all permissions'], 422);
        }

        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), static fn($x) => trim($x) !== '')));
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE rp FROM role_permissions rp WHERE rp.role_id = :rid')->execute([':rid' => $roleId]);
            if (count($keys) > 0) {
                $placeholders = implode(',', array_fill(0, count($keys), '?'));
                $stmt = $pdo->prepare('SELECT id FROM permissions WHERE perm_key IN (' . $placeholders . ')');
                $stmt->execute($keys);
                $permIds = $stmt->fetchAll();
                $ins = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:rid, :pid)');
                foreach ($permIds as $p) {
                    $pid = (int) (($p['id'] ?? 0) ?: 0);
                    if ($pid > 0) {
                        $ins->execute([':rid' => $roleId, ':pid' => $pid]);
                    }
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            try {
                $pdo->rollBack();
            } catch (\Throwable $e2) {
            }
            json(['error' => 'Save failed'], 500);
        }
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/rbac/users/set-roles' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $userId = (int) ($payload['user_id'] ?? 0);
        $roleIds = $payload['role_ids'] ?? null;
        if ($userId <= 0 || !is_array($roleIds)) {
            json(['error' => 'user_id and role_ids required'], 422);
        }
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $roleIds = array_values(array_filter($roleIds, static fn($x) => $x > 0));
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM user_role_assignments WHERE user_id = :uid')->execute([':uid' => $userId]);
            $ins = $pdo->prepare('INSERT IGNORE INTO user_role_assignments (user_id, role_id) VALUES (:uid, :rid)');
            foreach ($roleIds as $rid) {
                $ins->execute([':uid' => $userId, ':rid' => $rid]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            try {
                $pdo->rollBack();
            } catch (\Throwable $e2) {
            }
            json(['error' => 'Save failed'], 500);
        }
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/admin/rbac/users' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'users.manage');

        $users = $pdo->query('SELECT id, email, role, is_active, created_at FROM users ORDER BY id ASC')->fetchAll();
        $assignments = $pdo->query('SELECT user_id, role_id FROM user_role_assignments')->fetchAll();
        $byUser = [];
        if (is_array($assignments)) {
            foreach ($assignments as $a) {
                $uid = (int) (($a['user_id'] ?? 0) ?: 0);
                $rid = (int) (($a['role_id'] ?? 0) ?: 0);
                if ($uid > 0 && $rid > 0) {
                    if (!array_key_exists($uid, $byUser)) {
                        $byUser[$uid] = [];
                    }
                    $byUser[$uid][] = $rid;
                }
            }
        }
        if (is_array($users)) {
            foreach ($users as $i => $u) {
                $uid = (int) (($u['id'] ?? 0) ?: 0);
                $users[$i]['role_ids'] = $byUser[$uid] ?? [];
            }
        }

        json(['users' => $users]);
        return true;
    }

    if ($uri === '/api/admin/notifications/role-rules' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'notifications.manage');
        $rows = $pdo->query('SELECT role_id, event_key, enabled, reminder_minutes FROM notification_role_rules')->fetchAll();
        json(['rules' => $rows]);
        return true;
    }

    if ($uri === '/api/admin/notifications/role-rules' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'notifications.manage');
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $roleId = (int) ($payload['role_id'] ?? 0);
        $eventKey = trim((string) ($payload['event_key'] ?? ''));
        $enabled = !empty($payload['enabled']) ? 1 : 0;
        $rem = $payload['reminder_minutes'] ?? null;
        $remInt = null;
        if ($rem !== null && $rem !== '') {
            $remInt = (int) $rem;
            if ($remInt < 1) {
                $remInt = null;
            }
            if ($remInt !== null && $remInt > 10080) {
                $remInt = 10080;
            }
        }
        if ($roleId <= 0 || $eventKey === '') {
            json(['error' => 'role_id and event_key required'], 422);
        }
        $pdo->prepare('INSERT INTO notification_role_rules (role_id, event_key, enabled, reminder_minutes)
            VALUES (:rid, :ek, :en, :rm)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), reminder_minutes = VALUES(reminder_minutes)')
            ->execute([':rid' => $roleId, ':ek' => $eventKey, ':en' => $enabled, ':rm' => $remInt]);
        json(['ok' => true]);
        return true;
    }

    return false;
}
