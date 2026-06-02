<?php

declare(strict_types=1);

use App\Auth;
use Twilio\Rest\Client;

function handleInboxRoutes(string $uri, string $method, string $rootDir): bool
{
    $isAdmin = static function (PDO $pdo, int $uid): bool {
        try {
            $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $uid]);
            $r = $stmt->fetch();
            return ((string) (($r['role'] ?? '') ?: '')) === 'admin';
        } catch (\Throwable $e) {
            return false;
        }
    };

    $requireConversationAccess = static function (PDO $pdo, int $uid, int $conversationId) use ($isAdmin): void {
        if ($conversationId <= 0) {
            json(['error' => 'conversation_id required'], 422);
        }
        if ($isAdmin($pdo, $uid)) {
            return;
        }
        $chk = $pdo->prepare('SELECT 1
            FROM conversations c
            INNER JOIN user_numbers un ON un.number_id = c.default_number_id
            WHERE c.id = :cid AND un.user_id = :uid
            LIMIT 1');
        $chk->execute([':cid' => $conversationId, ':uid' => $uid]);
        if (!$chk->fetch()) {
            json(['error' => 'Forbidden'], 403);
        }
    };

    if ($uri === '/api/media/twilio' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'inbox.view');

        $messageId = (int) ($_GET['message_id'] ?? 0);
        $idx = (int) ($_GET['i'] ?? 0);
        if ($messageId <= 0 || $idx < 0) {
            json(['error' => 'message_id and i required'], 422);
        }

        $cfg = twilioConfig($pdo, Auth::userId());
        $sid = trim((string) ($cfg['account_sid'] ?? ''));
        $token = trim((string) ($cfg['auth_token'] ?? ''));
        if ($sid === '' || $token === '') {
            json(['error' => 'Missing Twilio credentials'], 500);
        }

        $mStmt = $pdo->prepare('SELECT url, content_type FROM message_media WHERE message_id = :mid ORDER BY id ASC');
        $mStmt->execute([':mid' => $messageId]);
        $rows = $mStmt->fetchAll();

        $urlAtIdx = '';
        if (is_array($rows) && array_key_exists($idx, $rows)) {
            $urlAtIdx = trim((string) (($rows[$idx]['url'] ?? '') ?: ''));
        }

        $needsRebuild = false;
        if (!is_array($rows) || !array_key_exists($idx, $rows) || $urlAtIdx === '') {
            $needsRebuild = true;
        } else {
            if (stripos($urlAtIdx, 'https://api.twilio.com') !== 0) {
                $needsRebuild = true;
            }
        }

        if ($needsRebuild) {
            try {
                $sStmt = $pdo->prepare('SELECT twilio_sid FROM messages WHERE id = :mid LIMIT 1');
                $sStmt->execute([':mid' => $messageId]);
                $msg = $sStmt->fetch();
                $twSid = trim((string) (($msg['twilio_sid'] ?? '') ?: ''));
                if ($twSid !== '') {
                    $client = new Client($sid, $token);
                    $twMedia = $client->messages($twSid)->media->read();
                    if (is_array($twMedia) && count($twMedia) > 0) {
                        $pdo->prepare('DELETE FROM message_media WHERE message_id = :mid')->execute([':mid' => $messageId]);
                        $ins = $pdo->prepare('INSERT INTO message_media (message_id, url, content_type) VALUES (:mid, :url, :ct)');
                        foreach ($twMedia as $mm) {
                            $uri = (string) (($mm->uri ?? '') ?: '');
                            if ($uri === '') {
                                continue;
                            }
                            $url = 'https://api.twilio.com' . str_replace('.json', '', $uri);
                            $ct = null;
                            if (property_exists($mm, 'contentType')) {
                                $ctv = (string) (($mm->contentType ?? '') ?: '');
                                if ($ctv !== '') {
                                    $ct = $ctv;
                                }
                            }
                            $ins->execute([':mid' => $messageId, ':url' => $url, ':ct' => $ct]);
                        }

                        $mStmt->execute([':mid' => $messageId]);
                        $rows = $mStmt->fetchAll();
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        if (!is_array($rows) || !array_key_exists($idx, $rows)) {
            json(['error' => 'Media not found'], 404);
        }
        $row = $rows[$idx];
        $url = trim((string) (($row['url'] ?? '') ?: ''));
        if ($url === '') {
            json(['error' => 'Media not found'], 404);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            json(['error' => 'Could not init request'], 500);
        }

        $ctDb = trim((string) (($row['content_type'] ?? '') ?: ''));
        curl_setopt($ch, CURLOPT_USERPWD, $sid . ':' . $token);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $status = 0;
        $ct = $ctDb;
        $sentHeaders = false;
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$status, &$ct, &$sentHeaders) {
            $h = trim($header);
            if ($h === '') {
                if (!$sentHeaders) {
                    $sentHeaders = true;
                    if ($ct !== '') {
                        header('Content-Type: ' . $ct);
                    } else {
                        header('Content-Type: application/octet-stream');
                    }
                    header('Cache-Control: private, max-age=60');
                }
                return strlen($header);
            }
            if (stripos($h, 'HTTP/') === 0) {
                $parts = explode(' ', $h);
                if (count($parts) >= 2) {
                    $status = (int) $parts[1];
                }
            }
            if (stripos($h, 'Content-Type:') === 0) {
                $ct = trim(substr($h, strlen('Content-Type:')));
            }
            return strlen($header);
        });

        $ok = curl_exec($ch);
        if ($ok === false) {
            curl_close($ch);
            http_response_code(502);
            echo 'Media fetch failed';
            exit;
        }
        if ($status >= 400 && $status !== 0) {
            curl_close($ch);
            http_response_code($status);
            exit;
        }
        curl_close($ch);
        exit;
    }

    if ($uri === '/api/me' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }
        $stmt = $pdo->prepare('SELECT id, email, role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $uid]);
        $me = $stmt->fetch();
        if (!$me) {
            json(['error' => 'Not found'], 404);
        }
        $roles = [];
        try {
            $rStmt = $pdo->prepare('SELECT r.id, r.name
                FROM user_role_assignments ura
                INNER JOIN roles r ON r.id = ura.role_id
                WHERE ura.user_id = :uid
                ORDER BY r.name ASC');
            $rStmt->execute([':uid' => $uid]);
            $roles = $rStmt->fetchAll();
        } catch (\Throwable $e) {
            $roles = [];
        }

        $perms = [];
        try {
            $perms = userPermissionKeys($pdo, $uid);
        } catch (\Throwable $e) {
            $perms = [];
        }

        $addons = [];
        try {
            $addons = enabledAddons($pdo);
        } catch (\Throwable $e) {
            $addons = [];
        }

        json(['me' => $me, 'roles' => $roles, 'permissions' => $perms, 'addons' => $addons]);
        return true;
    }

    if ($uri === '/api/inbox/conversations/mark-read' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'inbox.view');
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $conversationId = (int) ($payload['conversation_id'] ?? 0);
        $messageId = (int) ($payload['message_id'] ?? 0);
        if ($conversationId <= 0) {
            json(['error' => 'conversation_id required'], 422);
        }

        $requireConversationAccess($pdo, $uid, $conversationId);

        if ($messageId < 0) {
            $messageId = 0;
        }

        try {
            $pdo->prepare('INSERT INTO conversation_reads (user_id, conversation_id, last_read_message_id, last_read_at)
                VALUES (:uid, :cid, :mid, NOW())
                ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)), last_read_at = NOW()')
                ->execute([':uid' => $uid, ':cid' => $conversationId, ':mid' => $messageId]);
            $pdo->prepare('INSERT INTO conversation_read_events (user_id, conversation_id, read_message_id) VALUES (:uid, :cid, :mid)')
                ->execute([':uid' => $uid, ':cid' => $conversationId, ':mid' => $messageId]);
        } catch (\Throwable $e) {
            json(['error' => 'Could not mark read'], 500);
        }

        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/inbox/conversations' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'inbox.view');
        $q = trim((string)($_GET['q'] ?? ''));
        $assigned = trim((string)($_GET['assigned'] ?? ''));

        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        $sql = 'SELECT c.id AS conversation_id,
        ct.id AS contact_id,
        ct.first_name AS contact_first_name,
        ct.last_name AS contact_last_name,
        COALESCE(NULLIF(TRIM(CONCAT_WS(\' \' , ct.first_name, ct.last_name)), \'\'), ct.name) AS contact_name,
        ct.phone_number AS contact_phone,
        ct.email AS contact_email,
        c.last_message_preview,
        c.last_message_at,
        c.assigned_user_id,
        u.email AS assigned_user_email,
        c.default_number_id,
        n.phone_number AS conversation_number,
        COALESCE(cr.last_read_message_id, 0) AS last_read_message_id,
        COALESCE(lm.last_message_id, 0) AS last_message_id,
        CASE WHEN COALESCE(lm.last_message_id, 0) > COALESCE(cr.last_read_message_id, 0) THEN 1 ELSE 0 END AS is_unread
      FROM conversations c
      INNER JOIN contacts ct ON ct.id = c.contact_id
      LEFT JOIN users u ON u.id = c.assigned_user_id
      LEFT JOIN numbers n ON n.id = c.default_number_id
      LEFT JOIN conversation_reads cr ON cr.conversation_id = c.id AND cr.user_id = :uid
      LEFT JOIN (
        SELECT conversation_id, MAX(id) AS last_message_id
        FROM messages
        GROUP BY conversation_id
      ) lm ON lm.conversation_id = c.id
      WHERE 1=1';

        $params = [':uid' => $uid];

        if (!$isAdmin($pdo, $uid)) {
            $sql .= ' AND EXISTS (SELECT 1 FROM user_numbers un WHERE un.user_id = :uid AND un.number_id = c.default_number_id)';
        }

        if ($q !== '') {
            $sql .= ' AND (ct.phone_number LIKE :q OR ct.name LIKE :q OR ct.first_name LIKE :q OR ct.last_name LIKE :q OR ct.email LIKE :q)';
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
        return true;
    }

    if ($uri === '/api/inbox/conversations/create' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'inbox.send');

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
        return true;
    }

    if ($uri === '/api/inbox/messages' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'inbox.view');
        $conversationId = (int) ($_GET['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            json(['error' => 'conversation_id required'], 422);
        }

        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }
        $requireConversationAccess($pdo, $uid, $conversationId);

        $limit = (int) ($_GET['limit'] ?? 200);
        if ($limit < 1) {
            $limit = 200;
        }
        if ($limit > 500) {
            $limit = 500;
        }
        $stmt = $pdo->prepare('SELECT * FROM (
        SELECT m.id, m.direction, m.from_number, m.to_number, m.body, m.status, m.created_at,
            m.user_id, u.email AS user_email
          FROM messages m
          LEFT JOIN users u ON u.id = m.user_id
          WHERE m.conversation_id = :cid
          ORDER BY m.id DESC
          LIMIT ' . $limit .
        ') t ORDER BY t.id ASC');
        $stmt->execute([':cid' => $conversationId]);
        $messages = $stmt->fetchAll();

        $ids = [];
        if (is_array($messages)) {
            foreach ($messages as $m) {
                $mid = (int) (($m['id'] ?? 0) ?: 0);
                if ($mid > 0) {
                    $ids[] = $mid;
                }
            }
        }
        $mediaByMessage = [];
        if (count($ids) > 0) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $mStmt = $pdo->prepare('SELECT id, message_id, url, content_type FROM message_media WHERE message_id IN (' . $placeholders . ') ORDER BY id ASC');
            $mStmt->execute($ids);
            $rows = $mStmt->fetchAll();
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $mid = (int) (($r['message_id'] ?? 0) ?: 0);
                    if ($mid <= 0) {
                        continue;
                    }
                    if (!array_key_exists($mid, $mediaByMessage)) {
                        $mediaByMessage[$mid] = [];
                    }
                    $mediaByMessage[$mid][] = [
                        'id' => (int) (($r['id'] ?? 0) ?: 0),
                        'url' => (string) (($r['url'] ?? '') ?: ''),
                        'content_type' => (string) (($r['content_type'] ?? '') ?: ''),
                    ];
                }
            }
        }

        if (is_array($messages)) {
            foreach ($messages as $i => $m) {
                $mid = (int) (($m['id'] ?? 0) ?: 0);
                $messages[$i]['media'] = $mediaByMessage[$mid] ?? [];
            }
        }

        json(['messages' => $messages]);
        return true;
    }

    if ($uri === '/api/inbox/mms/upload' && $method === 'POST') {
        Auth::requireLogin();

        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            json(['error' => 'file required'], 422);
        }

        $f = $_FILES['file'];
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            json(['error' => 'Upload failed'], 400);
        }

        $tmp = (string) ($f['tmp_name'] ?? '');
        $size = (int) ($f['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp)) {
            json(['error' => 'Upload missing'], 400);
        }
        if ($size <= 0) {
            json(['error' => 'Empty file'], 422);
        }
        if ($size > 10 * 1024 * 1024) {
            json(['error' => 'Max 10MB'], 422);
        }

        $origName = (string) ($f['name'] ?? 'file');
        $origName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $origName);
        $origName = trim((string) $origName, '._');
        if ($origName === '') {
            $origName = 'file';
        }

        $contentType = '';
        try {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $contentType = (string) ($fi->file($tmp) ?: '');
        } catch (\Throwable $e) {
        }

        $allowed = false;
        if ($contentType !== '') {
            if (str_starts_with($contentType, 'image/')) $allowed = true;
            if (str_starts_with($contentType, 'video/')) $allowed = true;
            if (str_starts_with($contentType, 'audio/')) $allowed = true;
            if ($contentType === 'application/pdf') $allowed = true;
        }
        if (!$allowed) {
            json(['error' => 'Unsupported file type'], 422);
        }

        $dir = $rootDir . '/storage/mms_tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $token = bin2hex(random_bytes(16));
        $saveName = $token . '_' . $origName;
        $dest = $dir . '/' . $saveName;
        if (!move_uploaded_file($tmp, $dest)) {
            json(['error' => 'Could not store upload'], 500);
        }

        foreach (glob($dir . '/*') ?: [] as $p) {
            try {
                if (is_file($p) && (time() - (int) filemtime($p)) > 86400) {
                    @unlink($p);
                }
            } catch (\Throwable $e) {
            }
        }

        $url = baseUrl() . '/mms/tmp/' . rawurlencode($token) . '/' . rawurlencode($origName);
        json(['ok' => true, 'url' => $url, 'content_type' => $contentType]);
        return true;
    }

    if ($uri === '/api/inbox/send' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'inbox.send');

        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        $conversationId = (int) ($data['conversation_id'] ?? 0);
        $body = trim((string) ($data['body'] ?? ''));
        $fromNumberId = (int) ($data['from_number_id'] ?? 0);

        $mediaUrls = [];
        if (isset($data['media_urls']) && is_array($data['media_urls'])) {
            foreach ($data['media_urls'] as $u) {
                $s = trim((string) ($u ?? ''));
                if ($s !== '') {
                    $mediaUrls[] = $s;
                }
            }
        }
        if (count($mediaUrls) > 5) {
            $mediaUrls = array_slice($mediaUrls, 0, 5);
        }

        if ($conversationId <= 0 || ($body === '' && count($mediaUrls) === 0)) {
            json(['error' => 'conversation_id and body required (or media_urls for MMS)'], 422);
        }

        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }
        $requireConversationAccess($pdo, $uid, $conversationId);

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

        $optEnabled = appSettingGet($pdo, 'sms_opt_out_enabled', '1') === '1';
        if ($optEnabled) {
            $o = $pdo->prepare('SELECT 1 FROM sms_opt_outs WHERE phone_number = :pn LIMIT 1');
            $o->execute([':pn' => $to]);
            if ($o->fetch()) {
                json(['error' => 'Recipient opted out (STOP)'], 422);
            }
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
            $payload = [
                'from' => $from,
                'body' => $body,
                'statusCallback' => $statusCallback,
            ];
            if (count($mediaUrls) > 0) {
                $payload['mediaUrl'] = $mediaUrls;
            }
            $msg = $client->messages->create($to, $payload);
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
        $messageId = (int) $pdo->lastInsertId();

        if ($messageId > 0) {
            $uid = (int) Auth::userId();
            $pdo->prepare('INSERT INTO conversation_reads (user_id, conversation_id, last_read_message_id, last_read_at)
                VALUES (:uid, :cid, :mid, NOW())
                ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)), last_read_at = NOW()')
                ->execute([':uid' => $uid, ':cid' => $sendConversationId, ':mid' => $messageId]);
            $pdo->prepare('INSERT INTO conversation_read_events (user_id, conversation_id, read_message_id) VALUES (:uid, :cid, :mid)')
                ->execute([':uid' => $uid, ':cid' => $sendConversationId, ':mid' => $messageId]);
        }
        if ($messageId > 0 && count($mediaUrls) > 0) {
            $ins = $pdo->prepare('INSERT INTO message_media (message_id, url) VALUES (:mid, :url)');
            foreach ($mediaUrls as $u) {
                $ins->execute([':mid' => $messageId, ':url' => $u]);
            }

            try {
                $twilioMedia = null;
                for ($attempt = 0; $attempt < 5; $attempt++) {
                    try {
                        $twilioMedia = $client->messages((string) ($msg->sid ?? ''))->media->read();
                    } catch (\Throwable $e) {
                        $twilioMedia = null;
                    }
                    if (is_array($twilioMedia) && count($twilioMedia) > 0) {
                        break;
                    }
                    usleep(500000);
                }
                if (is_array($twilioMedia) && count($twilioMedia) > 0) {
                    $pdo->prepare('DELETE FROM message_media WHERE message_id = :mid')->execute([':mid' => $messageId]);
                    $ins2 = $pdo->prepare('INSERT INTO message_media (message_id, url, content_type) VALUES (:mid, :url, :ct)');
                    foreach ($twilioMedia as $mm) {
                        $uri = (string) (($mm->uri ?? '') ?: '');
                        if ($uri === '') {
                            continue;
                        }
                        $url = 'https://api.twilio.com' . str_replace('.json', '', $uri);
                        $ct = null;
                        if (property_exists($mm, 'contentType')) {
                            $ctv = (string) (($mm->contentType ?? '') ?: '');
                            if ($ctv !== '') {
                                $ct = $ctv;
                            }
                        }
                        $ins2->execute([':mid' => $messageId, ':url' => $url, ':ct' => $ct]);
                    }
                }
            } catch (\Throwable $e) {
            }
        }
        updateConversationPreview($pdo, $sendConversationId, $body);

        json(['ok' => true, 'sid' => (string) ($msg->sid ?? ''), 'status' => (string) ($msg->status ?? 'queued'), 'conversation_id' => $sendConversationId]);
        return true;
    }

    if ($uri === '/api/inbox/notes' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $conversationId = (int) ($_GET['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            json(['error' => 'conversation_id required'], 422);
        }

        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }
        $requireConversationAccess($pdo, $uid, $conversationId);
        $limit = (int) ($_GET['limit'] ?? 100);
        if ($limit < 1) {
            $limit = 100;
        }
        if ($limit > 300) {
            $limit = 300;
        }
        $stmt = $pdo->prepare('SELECT n.id, n.note, n.created_at, u.email AS user_email
        FROM conversation_notes n
        LEFT JOIN users u ON u.id = n.user_id
        WHERE n.conversation_id = :cid
        ORDER BY n.id DESC
        LIMIT ' . $limit);
        $stmt->execute([':cid' => $conversationId]);
        json(['notes' => $stmt->fetchAll()]);
        return true;
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

        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }
        $requireConversationAccess($pdo, $uid, $conversationId);

        $pdo->prepare('INSERT INTO conversation_notes (conversation_id, user_id, note) VALUES (:cid, :uid, :note)')
            ->execute([':cid' => $conversationId, ':uid' => Auth::userId(), ':note' => $note]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/inbox/assign' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'inbox.view');
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

        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }
        $requireConversationAccess($pdo, $uid, $conversationId);

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
        return true;
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
        return true;
    }

    if ($uri === '/api/inbox/users' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $stmt = $pdo->query('SELECT id, email, role FROM users WHERE is_active = 1 ORDER BY id ASC');
        json(['users' => $stmt->fetchAll()]);
        return true;
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
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        if ($conversationId <= 0) {
            json(['error' => 'conversation_id required'], 422);
        }

        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }
        $requireConversationAccess($pdo, $uid, $conversationId);

        if ($name === '' && ($firstName !== '' || $lastName !== '')) {
            $name = trim($firstName . ' ' . $lastName);
        }

        $stmt = $pdo->prepare('UPDATE contacts ct
        INNER JOIN conversations c ON c.contact_id = ct.id
        SET ct.first_name = :fn, ct.last_name = :ln, ct.name = :name, ct.email = :email
        WHERE c.id = :cid');
        $stmt->execute([
            ':fn' => ($firstName === '' ? null : $firstName),
            ':ln' => ($lastName === '' ? null : $lastName),
            ':name' => ($name === '' ? null : $name),
            ':email' => ($email === '' ? null : $email),
            ':cid' => $conversationId,
        ]);
        json(['ok' => true]);
        return true;
    }

    return false;
}
