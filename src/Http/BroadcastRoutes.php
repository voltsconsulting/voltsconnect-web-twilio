<?php

declare(strict_types=1);

use App\Auth;
use Twilio\Rest\Client;

function handleBroadcastRoutes(string $uri, string $method, string $rootDir): bool
{
    $sanitizePasteNumbers = static function (string $numbersRaw): array {
        $lines = preg_split('/\r\n|\r|\n/', $numbersRaw);
        $nums = [];
        if (is_array($lines)) {
            foreach ($lines as $ln) {
                $v = trim((string) $ln);
                if ($v === '') {
                    continue;
                }
                if (!preg_match('/^\+?[0-9]{7,20}$/', $v)) {
                    continue;
                }
                $nums[] = $v;
            }
        }
        $nums = array_values(array_unique($nums));
        return $nums;
    };

    $mergeBodyForContact = static function (PDO $pdo, string $body, array $contactRow, array $customFieldMap): string {
        $map = [
            'first_name' => (string) (($contactRow['first_name'] ?? '') ?: ''),
            'last_name' => (string) (($contactRow['last_name'] ?? '') ?: ''),
            'name' => (string) (($contactRow['name'] ?? '') ?: ''),
            'email' => (string) (($contactRow['email'] ?? '') ?: ''),
            'phone_number' => (string) (($contactRow['phone_number'] ?? '') ?: ''),
        ];
        foreach ($customFieldMap as $k => $v) {
            if (is_string($k)) {
                $map[$k] = (string) ($v ?? '');
            }
        }

        if ($body === '') {
            return '';
        }

        $out = $body;
        if (preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', $body, $m)) {
            $keys = $m[1] ?? [];
            if (is_array($keys)) {
                foreach ($keys as $k) {
                    $key = (string) $k;
                    $val = array_key_exists($key, $map) ? (string) ($map[$key] ?? '') : '';
                    $out = str_replace('{' . $key . '}', $val, $out);
                }
            }
        }
        return $out;
    };

    $loadCustomFieldsMap = static function (PDO $pdo, int $contactId): array {
        if ($contactId <= 0) {
            return [];
        }
        try {
            $stmt = $pdo->prepare('SELECT cf.field_key, cfv.value
                FROM contact_fields cf
                LEFT JOIN contact_field_values cfv ON cfv.field_id = cf.id AND cfv.contact_id = :cid
                ORDER BY cf.id ASC');
            $stmt->execute([':cid' => $contactId]);
            $rows = $stmt->fetchAll();
            $map = [];
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $k = (string) (($r['field_key'] ?? '') ?: '');
                    if ($k === '') {
                        continue;
                    }
                    $map[$k] = (string) (($r['value'] ?? '') ?: '');
                }
            }
            return $map;
        } catch (\Throwable $e) {
            return [];
        }
    };

    $ensureRecipients = static function (PDO $pdo, array $job) use ($sanitizePasteNumbers): void {
        $jobId = (int) (($job['id'] ?? 0) ?: 0);
        if ($jobId <= 0) {
            return;
        }
        $chk = $pdo->prepare('SELECT 1 FROM broadcast_job_recipients WHERE job_id = :jid LIMIT 1');
        $chk->execute([':jid' => $jobId]);
        if ($chk->fetch()) {
            return;
        }

        $mode = (string) (($job['mode'] ?? '') ?: '');
        $q = (string) (($job['q'] ?? '') ?: '');
        $groupId = (int) (($job['group_id'] ?? 0) ?: 0);
        $tagId = (int) (($job['tag_id'] ?? 0) ?: 0);
        $numbersRaw = (string) (($job['numbers_raw'] ?? '') ?: '');
        $optEnabled = appSettingGet($pdo, 'sms_opt_out_enabled', '1') === '1';

        if ($mode === 'all' || $mode === 'search') {
            $where = ' WHERE ct.phone_number IS NOT NULL AND ct.phone_number <> \'\'';
            $where .= " AND ct.phone_number REGEXP '^[+]?[0-9]{7,20}$'";
            $params = [':jid' => $jobId];
            if ($mode === 'search' && trim($q) !== '') {
                $where .= ' AND (ct.phone_number LIKE :q OR ct.name LIKE :q OR ct.first_name LIKE :q OR ct.last_name LIKE :q OR ct.email LIKE :q)';
                $params[':q'] = '%' . trim($q) . '%';
            }
            if ($optEnabled) {
                $where .= ' AND ct.phone_number NOT IN (SELECT phone_number FROM sms_opt_outs)';
            }
            $sql = 'INSERT INTO broadcast_job_recipients (job_id, contact_id, phone_number, status)
                SELECT :jid, ct.id, ct.phone_number, \'pending\'
                FROM contacts ct' . $where;
            try {
                $pdo->prepare($sql)->execute($params);
            } catch (\Throwable $e) {
                try {
                    $pdo->prepare('UPDATE broadcast_jobs SET last_error = :e WHERE id = :id')->execute([':e' => $e->getMessage(), ':id' => $jobId]);
                } catch (\Throwable $e2) {
                }
            }
        } elseif ($mode === 'group') {
            if ($groupId <= 0) {
                return;
            }
            $where = ' WHERE m.group_id = :gid AND ct.phone_number IS NOT NULL AND ct.phone_number <> \'\'';
            $where .= " AND ct.phone_number REGEXP '^[+]?[0-9]{7,20}$'";
            $params = [':jid' => $jobId, ':gid' => $groupId];
            if ($optEnabled) {
                $where .= ' AND ct.phone_number NOT IN (SELECT phone_number FROM sms_opt_outs)';
            }
            $sql = 'INSERT INTO broadcast_job_recipients (job_id, contact_id, phone_number, status)
                SELECT :jid, ct.id, ct.phone_number, \'pending\'
                FROM contacts ct
                INNER JOIN contact_group_members m ON m.contact_id = ct.id' . $where;
            try {
                $pdo->prepare($sql)->execute($params);
            } catch (\Throwable $e) {
                try {
                    $pdo->prepare('UPDATE broadcast_jobs SET last_error = :e WHERE id = :id')->execute([':e' => $e->getMessage(), ':id' => $jobId]);
                } catch (\Throwable $e2) {
                }
            }
        } elseif ($mode === 'tag') {
            if ($tagId <= 0) {
                return;
            }
            $where = ' WHERE m.tag_id = :tid AND ct.phone_number IS NOT NULL AND ct.phone_number <> \'\'';
            $where .= " AND ct.phone_number REGEXP '^[+]?[0-9]{7,20}$'";
            $params = [':jid' => $jobId, ':tid' => $tagId];
            if ($optEnabled) {
                $where .= ' AND ct.phone_number NOT IN (SELECT phone_number FROM sms_opt_outs)';
            }
            $sql = 'INSERT INTO broadcast_job_recipients (job_id, contact_id, phone_number, status)
                SELECT :jid, ct.id, ct.phone_number, \'pending\'
                FROM contacts ct
                INNER JOIN contact_tag_members m ON m.contact_id = ct.id' . $where;
            try {
                $pdo->prepare($sql)->execute($params);
            } catch (\Throwable $e) {
                try {
                    $pdo->prepare('UPDATE broadcast_jobs SET last_error = :e WHERE id = :id')->execute([':e' => $e->getMessage(), ':id' => $jobId]);
                } catch (\Throwable $e2) {
                }
            }
 } elseif ($mode === 'contacts') {
 $contactIdsRaw = (string) ($payload['contact_ids'] ?? '');
 $contactIds = [];
 if ($contactIdsRaw !== '') {
 $ids = json_decode($contactIdsRaw, true);
 if (is_array($ids)) {
 foreach ($ids as $id) {
 $n = (int) ($id ?? 0);
 if ($n > 0) { $contactIds[] = $n; }
 }
 }
 }
 $count = count($contactIds);
 $optCount = 0;
 $sample = [];
 if ($count === 0) {
 $eligible = 0;
 } else {
 $in = implode(',', array_fill(0, $count, '?'));
 $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts ct WHERE ct.id IN (' . $in . ')');
 $cntStmt->execute($contactIds);
 $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);
 if ($optEnabled) {
 $optStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c FROM contacts ct INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number WHERE ct.id IN (' . $in . ')');
 $optStmt->execute($contactIds);
 $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
 }
 $eligible = max(0, $count - $optCount);
 $sampleStmt = $pdo->prepare('SELECT id, first_name, last_name, name, phone_number, email FROM contacts WHERE id IN (' . $in . ') ORDER BY id DESC LIMIT 20');
 $sampleStmt->execute($contactIds);
 $sample = $sampleStmt->fetchAll();
 }
        } elseif ($mode === 'paste') {
            $nums = $sanitizePasteNumbers($numbersRaw);
            if (count($nums) === 0) {
                return;
            }
            $find = $pdo->prepare('SELECT id FROM contacts WHERE phone_number = :pn LIMIT 1');
            $ins = $pdo->prepare('INSERT INTO broadcast_job_recipients (job_id, contact_id, phone_number, status) VALUES (:jid, :cid, :pn, \'pending\')');
            $optStmt = null;
            if ($optEnabled) {
                $optStmt = $pdo->prepare('SELECT 1 FROM sms_opt_outs WHERE phone_number = :pn LIMIT 1');
            }
            foreach ($nums as $pn) {
                if ($optStmt) {
                    $optStmt->execute([':pn' => $pn]);
                    if ($optStmt->fetch()) {
                        continue;
                    }
                }
                $find->execute([':pn' => $pn]);
                $cid = (int) (($find->fetch()['id'] ?? 0) ?: 0);
                $ins->execute([':jid' => $jobId, ':cid' => $cid > 0 ? $cid : null, ':pn' => $pn]);
            }
        }

        try {
            $cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM broadcast_job_recipients WHERE job_id = :jid');
            $cnt->execute([':jid' => $jobId]);
            $c = (int) (($cnt->fetch()['c'] ?? 0) ?: 0);
            $pdo->prepare('UPDATE broadcast_jobs SET total_count = :c WHERE id = :jid')
                ->execute([':c' => $c, ':jid' => $jobId]);
        } catch (\Throwable $e) {
        }
    };

    $processJobBatch = static function (PDO $pdo, array $job, int $limit) use ($mergeBodyForContact, $loadCustomFieldsMap): array {
        $jobId = (int) (($job['id'] ?? 0) ?: 0);
        $uid = (int) (($job['user_id'] ?? 0) ?: 0);
        $fromNumberId = (int) (($job['from_number_id'] ?? 0) ?: 0);
        $body = (string) (($job['body'] ?? '') ?: '');
        $sendDelayMs = (int) (($job['send_delay_ms'] ?? 0) ?: 0);
        if ($sendDelayMs < 0) {
            $sendDelayMs = 0;
        }
        if ($sendDelayMs > 5000) {
            $sendDelayMs = 5000;
        }
        if ($jobId <= 0 || $uid <= 0 || $fromNumberId <= 0 || $body === '') {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $fromStmt = $pdo->prepare('SELECT n.phone_number, n.twilio_account_id FROM numbers n WHERE n.id = :id LIMIT 1');
        $fromStmt->execute([':id' => $fromNumberId]);
        $fromRow = $fromStmt->fetch();
        $from = (string) (($fromRow['phone_number'] ?? '') ?: '');
        $fromTwilioAccountId = (int) (($fromRow['twilio_account_id'] ?? 0) ?: 0);
        if ($from === '') {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $sid = '';
        $token = '';
        if ($fromTwilioAccountId > 0) {
            $accStmt = $pdo->prepare('SELECT account_sid, auth_token FROM twilio_accounts WHERE id = :id LIMIT 1');
            $accStmt->execute([':id' => $fromTwilioAccountId]);
            $acc = $accStmt->fetch();
            $sid = (string) (($acc['account_sid'] ?? '') ?: '');
            $token = (string) (($acc['auth_token'] ?? '') ?: '');
        }
        if ($sid === '' || $token === '') {
            $cfg = twilioConfig($pdo, $uid);
            $sid = (string) ($cfg['account_sid'] ?? '');
            $token = (string) ($cfg['auth_token'] ?? '');
        }
        if ($sid === '' || $token === '') {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'error' => 'Missing Twilio Messaging credentials'];
        }

        $optEnabled = appSettingGet($pdo, 'sms_opt_out_enabled', '1') === '1';
        $optStmt = null;
        if ($optEnabled) {
            $optStmt = $pdo->prepare('SELECT 1 FROM sms_opt_outs WHERE phone_number = :pn LIMIT 1');
        }

        $list = $pdo->prepare('SELECT id, contact_id, phone_number FROM broadcast_job_recipients WHERE job_id = :jid AND status = \'pending\' ORDER BY id ASC LIMIT ' . (int) $limit);
        $list->execute([':jid' => $jobId]);
        $rows = $list->fetchAll();
        if (!is_array($rows) || count($rows) === 0) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $client = new Client($sid, $token);
        $statusCallback = baseUrl() . '/webhooks/twilio/sms/status';

        $updSent = $pdo->prepare('UPDATE broadcast_job_recipients SET status = \'sent\', message_id = :mid, twilio_sid = :ts, updated_at = NOW() WHERE id = :id');
        $updFail = $pdo->prepare('UPDATE broadcast_job_recipients SET status = \'failed\', error = :e, updated_at = NOW() WHERE id = :id');
        $updSkip = $pdo->prepare('UPDATE broadcast_job_recipients SET status = \'skipped\', error = :e, updated_at = NOW() WHERE id = :id');
        $updCid = $pdo->prepare('UPDATE broadcast_job_recipients SET contact_id = :cid WHERE id = :id');

        $contactStmt = $pdo->prepare('SELECT id, first_name, last_name, name, phone_number, email FROM contacts WHERE id = :id LIMIT 1');
        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($rows as $r) {
            $rid = (int) (($r['id'] ?? 0) ?: 0);
            $to = (string) (($r['phone_number'] ?? '') ?: '');
            $contactId = (int) (($r['contact_id'] ?? 0) ?: 0);
            if ($rid <= 0 || $to === '') {
                continue;
            }

            if ($optStmt) {
                $optStmt->execute([':pn' => $to]);
                if ($optStmt->fetch()) {
                    $updSkip->execute([':id' => $rid, ':e' => 'opted_out']);
                    $skipped += 1;
                    continue;
                }
            }

            if ($contactId <= 0) {
                $contactId = getOrCreateContactId($pdo, $to);
                if ($contactId > 0) {
                    $updCid->execute([':id' => $rid, ':cid' => $contactId]);
                }
            }

            $contact = [];
            if ($contactId > 0) {
                $contactStmt->execute([':id' => $contactId]);
                $c = $contactStmt->fetch();
                if (is_array($c)) {
                    $contact = $c;
                }
            }
            if (!is_array($contact) || empty($contact)) {
                $contact = ['first_name' => null, 'last_name' => null, 'name' => null, 'email' => null, 'phone_number' => $to];
            }
            $customMap = $loadCustomFieldsMap($pdo, $contactId);
            $finalBody = $mergeBodyForContact($pdo, $body, $contact, $customMap);
            $finalBody = trim($finalBody);
            if ($finalBody === '') {
                $updSkip->execute([':id' => $rid, ':e' => 'empty_body']);
                $skipped += 1;
                continue;
            }

            $conversationId = 0;
            if ($contactId > 0) {
                $conversationId = getOrCreateConversationId($pdo, $contactId, $fromNumberId);
            }
            if ($conversationId <= 0) {
                $updFail->execute([':id' => $rid, ':e' => 'missing_conversation']);
                $failed += 1;
                continue;
            }

            try {
                $msg = $client->messages->create($to, [
                    'from' => $from,
                    'body' => $finalBody,
                    'statusCallback' => $statusCallback,
                ]);
            } catch (\Throwable $e) {
                $updFail->execute([':id' => $rid, ':e' => $e->getMessage()]);
                $failed += 1;
                continue;
            }

            if ($sendDelayMs > 0) {
                usleep($sendDelayMs * 1000);
            }

            $pdo->prepare('INSERT INTO messages (conversation_id, user_id, direction, from_number, to_number, body, twilio_sid, status)
                VALUES (:cid, :uid, :dir, :from, :to, :body, :sid, :st)')
                ->execute([
                    ':cid' => $conversationId,
                    ':uid' => $uid,
                    ':dir' => 'outbound',
                    ':from' => $from,
                    ':to' => $to,
                    ':body' => $finalBody,
                    ':sid' => (string) ($msg->sid ?? ''),
                    ':st' => (string) ($msg->status ?? 'queued'),
                ]);
            $messageId = (int) $pdo->lastInsertId();

            updateConversationPreview($pdo, $conversationId, $finalBody);

            $updSent->execute([
                ':id' => $rid,
                ':mid' => $messageId > 0 ? $messageId : null,
                ':ts' => (string) ($msg->sid ?? ''),
            ]);
            $sent += 1;
        }

        try {
            $pdo->prepare('UPDATE broadcast_jobs
                SET sent_count = (SELECT COUNT(*) FROM broadcast_job_recipients WHERE job_id = :jid AND status = \'sent\'),
                    failed_count = (SELECT COUNT(*) FROM broadcast_job_recipients WHERE job_id = :jid AND status = \'failed\'),
                    opted_out_count = (SELECT COUNT(*) FROM broadcast_job_recipients WHERE job_id = :jid AND status = \'skipped\' AND error = \'opted_out\')
                WHERE id = :jid')
                ->execute([':jid' => $jobId]);
        } catch (\Throwable $e) {
        }

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
    };

    if ($uri === '/api/cron/broadcasts' && $method === 'GET') {
        $pdo = getPdo($rootDir);
        $token = trim((string) ($_GET['token'] ?? ''));
        $expected = trim(appSettingGet($pdo, 'notify_cron_token', ''));
        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            json(['error' => 'Invalid token'], 403);
        }

        $job = null;
        try {
            $stmt = $pdo->query("SELECT * FROM broadcast_jobs WHERE (status = 'scheduled' OR status = 'running') AND scheduled_at_utc <= UTC_TIMESTAMP() ORDER BY scheduled_at_utc ASC, id ASC LIMIT 1");
            $row = $stmt->fetch();
            if (is_array($row) && !empty($row)) {
                $job = $row;
            }
        } catch (\Throwable $e) {
            $job = null;
        }
        if (!is_array($job) || empty($job)) {
            json(['ok' => true, 'processed' => false]);
        }

        $jobId = (int) (($job['id'] ?? 0) ?: 0);
        if ($jobId <= 0) {
            json(['ok' => true, 'processed' => false]);
        }

        if (((string) ($job['status'] ?? '')) === 'scheduled') {
            try {
                $pdo->prepare("UPDATE broadcast_jobs SET status = 'running', started_at = UTC_TIMESTAMP() WHERE id = :id")
                    ->execute([':id' => $jobId]);
            } catch (\Throwable $e) {
            }
            try {
                $stmt = $pdo->prepare('SELECT * FROM broadcast_jobs WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $jobId]);
                $row = $stmt->fetch();
                if (is_array($row) && !empty($row)) {
                    $job = $row;
                }
            } catch (\Throwable $e) {
            }
        }

        $ensureRecipients($pdo, $job);
        $batchSize = (int) (($job['batch_size'] ?? 50) ?: 50);
        if ($batchSize < 1) {
            $batchSize = 1;
        }
        if ($batchSize > 500) {
            $batchSize = 500;
        }
        $batch = $processJobBatch($pdo, $job, $batchSize);

        $pending = 0;
        try {
            $p = $pdo->prepare("SELECT COUNT(*) AS c FROM broadcast_job_recipients WHERE job_id = :jid AND status = 'pending'");
            $p->execute([':jid' => $jobId]);
            $pending = (int) (($p->fetch()['c'] ?? 0) ?: 0);
        } catch (\Throwable $e) {
            $pending = 0;
        }
        if ($pending === 0) {
            try {
                $pdo->prepare("UPDATE broadcast_jobs SET status = 'finished', finished_at = UTC_TIMESTAMP() WHERE id = :id")
                    ->execute([':id' => $jobId]);
            } catch (\Throwable $e) {
            }
        }

        json(['ok' => true, 'processed' => true, 'job_id' => $jobId, 'batch' => $batch, 'pending' => $pending]);
        return true;
    }

    if ($uri === '/api/broadcast/schedule' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'broadcast.use');

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        $mode = trim((string) ($payload['mode'] ?? ''));
        $q = trim((string) ($payload['q'] ?? ''));
        $groupId = (int) ($payload['group_id'] ?? 0);
        $tagId = (int) ($payload['tag_id'] ?? 0);
        $numbersRaw = (string) ($payload['numbers'] ?? '');
        $body = trim((string) ($payload['body'] ?? ''));
        $fromNumberId = (int) ($payload['from_number_id'] ?? 0);
        $scheduleDate = trim((string) ($payload['schedule_date'] ?? ''));
        $scheduleTime = trim((string) ($payload['schedule_time'] ?? ''));
        $batchSize = (int) ($payload['batch_size'] ?? 50);
        $sendDelayMs = (int) ($payload['send_delay_ms'] ?? 0);

        if ($batchSize < 1) $batchSize = 1;
        if ($batchSize > 500) $batchSize = 500;
        if ($sendDelayMs < 0) $sendDelayMs = 0;
        if ($sendDelayMs > 5000) $sendDelayMs = 5000;

        if ($body === '' || $fromNumberId <= 0) {
            json(['error' => 'body and from_number_id required'], 422);
        }
        if ($scheduleDate === '' || $scheduleTime === '') {
            json(['error' => 'schedule_date and schedule_time required'], 422);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduleDate)) {
            json(['error' => 'Invalid schedule_date'], 422);
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $scheduleTime)) {
            json(['error' => 'Invalid schedule_time'], 422);
        }

        if ($mode === '') {
            $mode = ($q !== '') ? 'search' : 'all';
        }

        $chk = $pdo->prepare('SELECT n.phone_number FROM numbers n
        INNER JOIN user_numbers un ON un.number_id = n.id
        WHERE un.user_id = :uid AND n.id = :nid LIMIT 1');
        $chk->execute([':uid' => $uid, ':nid' => $fromNumberId]);
        if (!$chk->fetch()) {
            json(['error' => 'You do not have access to that From number'], 403);
        }

        $tz = (string) appSettingGet($pdo, 'app_timezone', 'UTC');
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $tz = 'UTC';
        }
        $localTz = new \DateTimeZone($tz);
        $dtLocal = \DateTime::createFromFormat('Y-m-d H:i', $scheduleDate . ' ' . $scheduleTime, $localTz);
        if (!$dtLocal) {
            json(['error' => 'Invalid scheduled date/time'], 422);
        }
        $dtUtc = clone $dtLocal;
        $dtUtc->setTimezone(new \DateTimeZone('UTC'));
        $scheduledAtUtc = $dtUtc->format('Y-m-d H:i:s');
        $scheduledAtLocal = $dtLocal->format('Y-m-d H:i');
        $nowUtc = new \DateTime('now', new \DateTimeZone('UTC'));
        if ($dtUtc <= $nowUtc) {
            json(['error' => 'Scheduled time must be in the future'], 422);
        }

        $optEnabled = appSettingGet($pdo, 'sms_opt_out_enabled', '1') === '1';
        $count = 0;
        $optCount = 0;

        if ($mode === 'all' || $mode === 'search') {
            $where = ' WHERE ct.phone_number IS NOT NULL AND ct.phone_number <> \'\'';
            $where .= " AND ct.phone_number REGEXP '^[+]?[0-9]{7,20}$'";
            $params = [];
            if ($mode === 'search' && $q !== '') {
                $where .= ' AND (ct.phone_number LIKE :q OR ct.name LIKE :q OR ct.first_name LIKE :q OR ct.last_name LIKE :q OR ct.email LIKE :q)';
                $params[':q'] = '%' . $q . '%';
            }
            $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts ct' . $where);
            $cntStmt->execute($params);
            $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);

            if ($optEnabled) {
                $optStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts ct
                INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number' . $where);
                $optStmt->execute($params);
                $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
            }
        } elseif ($mode === 'group') {
            if ($groupId <= 0) {
                json(['error' => 'group_id required'], 422);
            }
            $chk2 = $pdo->prepare('SELECT id FROM contact_groups WHERE id = :id AND user_id = :uid LIMIT 1');
            $chk2->execute([':id' => $groupId, ':uid' => $uid]);
            if (!$chk2->fetch()) {
                json(['error' => 'Unknown group'], 404);
            }

            $cntStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c
                FROM contacts ct
                INNER JOIN contact_group_members m ON m.contact_id = ct.id
                WHERE m.group_id = :gid');
            $cntStmt->execute([':gid' => $groupId]);
            $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);

            if ($optEnabled) {
                $optStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c
                    FROM contacts ct
                    INNER JOIN contact_group_members m ON m.contact_id = ct.id
                    INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number
                    WHERE m.group_id = :gid');
                $optStmt->execute([':gid' => $groupId]);
                $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
            }
        } elseif ($mode === 'tag') {
            if ($tagId <= 0) {
                json(['error' => 'tag_id required'], 422);
            }
            $chk2 = $pdo->prepare('SELECT id FROM contact_tags WHERE id = :id AND user_id = :uid LIMIT 1');
            $chk2->execute([':id' => $tagId, ':uid' => $uid]);
            if (!$chk2->fetch()) {
                json(['error' => 'Unknown tag'], 404);
            }

            $cntStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c
                FROM contacts ct
                INNER JOIN contact_tag_members m ON m.contact_id = ct.id
                WHERE m.tag_id = :tid');
            $cntStmt->execute([':tid' => $tagId]);
            $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);

            if ($optEnabled) {
                $optStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c
                    FROM contacts ct
                    INNER JOIN contact_tag_members m ON m.contact_id = ct.id
                    INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number
                    WHERE m.tag_id = :tid');
                $optStmt->execute([':tid' => $tagId]);
                $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
            }
 } elseif ($mode === 'contacts') {
 $contactIdsRaw = (string) ($payload['contact_ids'] ?? '');
 $contactIds = [];
 if ($contactIdsRaw !== '') {
 $ids = json_decode($contactIdsRaw, true);
 if (is_array($ids)) {
 foreach ($ids as $id) {
 $n = (int) ($id ?? 0);
 if ($n > 0) { $contactIds[] = $n; }
 }
 }
 }
 $count = count($contactIds);
 $optCount = 0;
 $sample = [];
 if ($count === 0) {
 $eligible = 0;
 } else {
 $in = implode(',', array_fill(0, $count, '?'));
 $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts ct WHERE ct.id IN (' . $in . ')');
 $cntStmt->execute($contactIds);
 $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);
 if ($optEnabled) {
 $optStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c FROM contacts ct INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number WHERE ct.id IN (' . $in . ')');
 $optStmt->execute($contactIds);
 $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
 }
 $eligible = max(0, $count - $optCount);
 $sampleStmt = $pdo->prepare('SELECT id, first_name, last_name, name, phone_number, email FROM contacts WHERE id IN (' . $in . ') ORDER BY id DESC LIMIT 20');
 $sampleStmt->execute($contactIds);
 $sample = $sampleStmt->fetchAll();
 }
        } elseif ($mode === 'paste') {
            $lines = preg_split('/\r\n|\r|\n/', $numbersRaw);
            $nums = [];
            if (is_array($lines)) {
                foreach ($lines as $ln) {
                    $v = trim((string) $ln);
                    if ($v === '') {
                        continue;
                    }
                    if (!preg_match('/^\+?[0-9]{7,20}$/', $v)) {
                        continue;
                    }
                    $nums[] = $v;
                }
            }
            $nums = array_values(array_unique($nums));
            $count = count($nums);
            if ($optEnabled && $count > 0) {
                $in = implode(',', array_fill(0, $count, '?'));
                $oStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM sms_opt_outs WHERE phone_number IN (' . $in . ')');
                $oStmt->execute($nums);
                $optCount = (int) (($oStmt->fetch()['c'] ?? 0) ?: 0);
            }
        } else {
            json(['error' => 'Unknown mode'], 422);
        }

        $total = max(0, $count);
        $eligible = max(0, $count - $optCount);

        $stmt = $pdo->prepare('INSERT INTO broadcast_jobs (user_id, from_number_id, body, mode, q, group_id, tag_id, numbers_raw, batch_size, send_delay_ms, scheduled_at_utc, status, total_count, opted_out_count)
            VALUES (:uid, :fn, :body, :mode, :q, :gid, :tid, :nums, :bs, :sd, :sat, \'scheduled\', :tc, :oc)');
        $stmt->execute([
            ':uid' => $uid,
            ':fn' => $fromNumberId,
            ':body' => $body,
            ':mode' => $mode,
            ':q' => $q !== '' ? $q : null,
            ':gid' => $groupId > 0 ? $groupId : null,
            ':tid' => $tagId > 0 ? $tagId : null,
            ':nums' => trim($numbersRaw) !== '' ? $numbersRaw : null,
            ':bs' => $batchSize,
            ':sd' => $sendDelayMs,
            ':sat' => $scheduledAtUtc,
            ':tc' => $total,
            ':oc' => $optCount,
        ]);
        $jobId = (int) $pdo->lastInsertId();

        json([
            'ok' => true,
            'job_id' => $jobId,
            'scheduled_at_utc' => $scheduledAtUtc,
            'scheduled_at_local' => $scheduledAtLocal,
            'scheduled_tz' => $tz,
            'count_total' => $total,
            'count_opted_out' => $optCount,
            'count_eligible' => $eligible,
        ]);
        return true;
    }

    if ($uri === '/api/broadcast/jobs' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'broadcast.use');
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        $limit = (int) (($_GET['limit'] ?? 50) ?: 50);
        $offset = (int) (($_GET['offset'] ?? 0) ?: 0);
        if ($limit <= 0) $limit = 50;
        if ($limit > 200) $limit = 200;
        if ($offset < 0) $offset = 0;

        $stmt = $pdo->prepare('SELECT j.*, n.phone_number AS from_number
            FROM broadcast_jobs j
            LEFT JOIN numbers n ON n.id = j.from_number_id
            WHERE j.user_id = :uid
            ORDER BY j.id DESC
            LIMIT :lim OFFSET :off');
        $stmt->bindValue(':uid', $uid, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (!is_array($rows)) $rows = [];
        json(['jobs' => $rows]);
        return true;
    }

    if ($uri === '/api/broadcast/job' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'broadcast.use');
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        $jobId = (int) (($_GET['id'] ?? 0) ?: 0);
        if ($jobId <= 0) {
            json(['error' => 'id required'], 422);
        }

        $jStmt = $pdo->prepare('SELECT j.*, n.phone_number AS from_number
            FROM broadcast_jobs j
            LEFT JOIN numbers n ON n.id = j.from_number_id
            WHERE j.id = :id AND j.user_id = :uid
            LIMIT 1');
        $jStmt->execute([':id' => $jobId, ':uid' => $uid]);
        $job = $jStmt->fetch();
        if (!is_array($job) || empty($job)) {
            json(['error' => 'Not found'], 404);
        }

        $counts = [];
        try {
            $cStmt = $pdo->prepare('SELECT status, COUNT(*) AS c
                FROM broadcast_job_recipients
                WHERE job_id = :jid
                GROUP BY status');
            $cStmt->execute([':jid' => $jobId]);
            $rows = $cStmt->fetchAll();
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $st = (string) (($r['status'] ?? '') ?: '');
                    $c = (int) (($r['c'] ?? 0) ?: 0);
                    if ($st !== '') $counts[$st] = $c;
                }
            }
        } catch (\Throwable $e) {
            $counts = [];
        }

        $topErrors = [];
        try {
            $eStmt = $pdo->prepare('SELECT error, COUNT(*) AS c
                FROM broadcast_job_recipients
                WHERE job_id = :jid AND error IS NOT NULL AND error <> \'\'
                GROUP BY error
                ORDER BY c DESC
                LIMIT 5');
            $eStmt->execute([':jid' => $jobId]);
            $topErrors = $eStmt->fetchAll();
            if (!is_array($topErrors)) $topErrors = [];
        } catch (\Throwable $e) {
            $topErrors = [];
        }

        $sample = [];
        try {
            $sStmt = $pdo->prepare('SELECT phone_number, status, error, twilio_sid, created_at, updated_at
                FROM broadcast_job_recipients
                WHERE job_id = :jid
                ORDER BY id DESC
                LIMIT 20');
            $sStmt->execute([':jid' => $jobId]);
            $sample = $sStmt->fetchAll();
            if (!is_array($sample)) $sample = [];
        } catch (\Throwable $e) {
            $sample = [];
        }

        json(['job' => $job, 'counts' => $counts, 'top_errors' => $topErrors, 'sample' => $sample]);
        return true;
    }

    if ($uri === '/api/broadcast/cancel' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'broadcast.use');
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $jobId = (int) (($payload['id'] ?? 0) ?: 0);
        if ($jobId <= 0) {
            json(['error' => 'id required'], 422);
        }

        $jStmt = $pdo->prepare('SELECT id, status FROM broadcast_jobs WHERE id = :id AND user_id = :uid LIMIT 1');
        $jStmt->execute([':id' => $jobId, ':uid' => $uid]);
        $job = $jStmt->fetch();
        if (!is_array($job) || empty($job)) {
            json(['error' => 'Not found'], 404);
        }
        $status = (string) (($job['status'] ?? '') ?: '');
        if (!in_array($status, ['scheduled', 'running'], true)) {
            json(['error' => 'Only scheduled or running campaigns can be canceled'], 422);
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE broadcast_jobs SET status = 'canceled', finished_at = UTC_TIMESTAMP(), updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $jobId]);
            $pdo->prepare("UPDATE broadcast_job_recipients SET status = 'skipped', error = 'canceled', updated_at = NOW() WHERE job_id = :jid AND status = 'pending'")
                ->execute([':jid' => $jobId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            try { $pdo->rollBack(); } catch (\Throwable $e2) {}
            json(['error' => 'Cancel failed'], 500);
        }

        json(['ok' => true, 'job_id' => $jobId]);
        return true;
    }

    if ($uri === '/api/broadcast/preview' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'broadcast.use');
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        try {

        $mode = trim((string) ($payload['mode'] ?? ''));
        $q = trim((string) ($payload['q'] ?? ''));
        $groupId = (int) ($payload['group_id'] ?? 0);
        $tagId = (int) ($payload['tag_id'] ?? 0);
        $numbersRaw = (string) ($payload['numbers'] ?? '');

        if ($mode === '') {
            $mode = ($q !== '') ? 'search' : 'all';
        }

        $optEnabled = appSettingGet($pdo, 'sms_opt_out_enabled', '1') === '1';

        $count = 0;
        $optCount = 0;
        $sample = [];

        if ($mode === 'all' || $mode === 'search') {
            $where = ' WHERE ct.phone_number IS NOT NULL AND ct.phone_number <> \'\'';
            $where .= " AND ct.phone_number REGEXP '^[+]?[0-9]{7,20}$'";
            $params = [];
            if ($mode === 'search' && $q !== '') {
                $where .= ' AND (ct.phone_number LIKE :q OR ct.name LIKE :q OR ct.first_name LIKE :q OR ct.last_name LIKE :q OR ct.email LIKE :q)';
                $params[':q'] = '%' . $q . '%';
            }

            $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts ct' . $where);
            $cntStmt->execute($params);
            $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);

            if ($optEnabled) {
                $optStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts ct
                INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number' . $where);
                $optStmt->execute($params);
                $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
            }

            $sampleWhere = $where;
            $sampleParams = $params;
            if ($optEnabled) {
                $sampleWhere .= ' AND ct.phone_number NOT IN (SELECT phone_number FROM sms_opt_outs)';
            }
            $sampleStmt = $pdo->prepare('SELECT id, first_name, last_name, name, phone_number, email FROM contacts ct' . $sampleWhere . ' ORDER BY ct.id DESC LIMIT 20');
            $sampleStmt->execute($sampleParams);
            $sample = $sampleStmt->fetchAll();
        } elseif ($mode === 'group') {
            if ($groupId <= 0) {
                json(['error' => 'group_id required'], 422);
            }
            $chk = $pdo->prepare('SELECT id FROM contact_groups WHERE id = :id AND user_id = :uid LIMIT 1');
            $chk->execute([':id' => $groupId, ':uid' => $uid]);
            if (!$chk->fetch()) {
                json(['error' => 'Unknown group'], 404);
            }

            $cntStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c
                FROM contacts ct
                INNER JOIN contact_group_members m ON m.contact_id = ct.id
                WHERE m.group_id = :gid');
            $cntStmt->execute([':gid' => $groupId]);
            $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);

            if ($optEnabled) {
                $optStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c
                    FROM contacts ct
                    INNER JOIN contact_group_members m ON m.contact_id = ct.id
                    INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number
                    WHERE m.group_id = :gid');
                $optStmt->execute([':gid' => $groupId]);
                $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
            }

            $sql = 'SELECT DISTINCT ct.id, ct.first_name, ct.last_name, ct.name, ct.phone_number, ct.email
                FROM contacts ct
                INNER JOIN contact_group_members m ON m.contact_id = ct.id
                WHERE m.group_id = :gid';
            $params = [':gid' => $groupId];
            if ($optEnabled) {
                $sql .= ' AND ct.phone_number NOT IN (SELECT phone_number FROM sms_opt_outs)';
            }
            $sql .= ' ORDER BY ct.id DESC LIMIT 20';
            $sStmt = $pdo->prepare($sql);
            $sStmt->execute($params);
            $sample = $sStmt->fetchAll();
        } elseif ($mode === 'tag') {
            if ($tagId <= 0) {
                json(['error' => 'tag_id required'], 422);
            }
            $chk = $pdo->prepare('SELECT id FROM contact_tags WHERE id = :id AND user_id = :uid LIMIT 1');
            $chk->execute([':id' => $tagId, ':uid' => $uid]);
            if (!$chk->fetch()) {
                json(['error' => 'Unknown tag'], 404);
            }

            $cntStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c
                FROM contacts ct
                INNER JOIN contact_tag_members m ON m.contact_id = ct.id
                WHERE m.tag_id = :tid');
            $cntStmt->execute([':tid' => $tagId]);
            $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);

            if ($optEnabled) {
                $optStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c
                    FROM contacts ct
                    INNER JOIN contact_tag_members m ON m.contact_id = ct.id
                    INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number
                    WHERE m.tag_id = :tid');
                $optStmt->execute([':tid' => $tagId]);
                $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
            }

            $sql = 'SELECT DISTINCT ct.id, ct.first_name, ct.last_name, ct.name, ct.phone_number, ct.email
                FROM contacts ct
                INNER JOIN contact_tag_members m ON m.contact_id = ct.id
                WHERE m.tag_id = :tid';
            $params = [':tid' => $tagId];
            if ($optEnabled) {
                $sql .= ' AND ct.phone_number NOT IN (SELECT phone_number FROM sms_opt_outs)';
            }
            $sql .= ' ORDER BY ct.id DESC LIMIT 20';
            $sStmt = $pdo->prepare($sql);
            $sStmt->execute($params);
            $sample = $sStmt->fetchAll();
 } elseif ($mode === 'contacts') {
 $contactIdsRaw = (string) ($payload['contact_ids'] ?? '');
 $contactIds = [];
 if ($contactIdsRaw !== '') {
 $ids = json_decode($contactIdsRaw, true);
 if (is_array($ids)) {
 foreach ($ids as $id) {
 $n = (int) ($id ?? 0);
 if ($n > 0) { $contactIds[] = $n; }
 }
 }
 }
 $count = count($contactIds);
 $optCount = 0;
 $sample = [];
 if ($count === 0) {
 $eligible = 0;
 } else {
 $in = implode(',', array_fill(0, $count, '?'));
 $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts ct WHERE ct.id IN (' . $in . ')');
 $cntStmt->execute($contactIds);
 $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);
 if ($optEnabled) {
 $optStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c FROM contacts ct INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number WHERE ct.id IN (' . $in . ')');
 $optStmt->execute($contactIds);
 $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
 }
 $eligible = max(0, $count - $optCount);
 $sampleStmt = $pdo->prepare('SELECT id, first_name, last_name, name, phone_number, email FROM contacts WHERE id IN (' . $in . ') ORDER BY id DESC LIMIT 20');
 $sampleStmt->execute($contactIds);
 $sample = $sampleStmt->fetchAll();
 }
        } elseif ($mode === 'paste') {
            $lines = preg_split('/\r\n|\r|\n/', $numbersRaw);
            $nums = [];
            if (is_array($lines)) {
                foreach ($lines as $ln) {
                    $v = trim((string) $ln);
                    if ($v === '') {
                        continue;
                    }
                    if (!preg_match('/^\+?[0-9]{7,20}$/', $v)) {
                        continue;
                    }
                    $nums[] = $v;
                }
            }
            $nums = array_values(array_unique($nums));
            $count = count($nums);

            if ($optEnabled && $count > 0) {
                $in = implode(',', array_fill(0, $count, '?'));
                $oStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM sms_opt_outs WHERE phone_number IN (' . $in . ')');
                $oStmt->execute($nums);
                $optCount = (int) (($oStmt->fetch()['c'] ?? 0) ?: 0);
            }

            $eligibleNums = $nums;
            if ($optEnabled && count($eligibleNums) > 0) {
                $in = implode(',', array_fill(0, count($eligibleNums), '?'));
                $oo = $pdo->prepare('SELECT phone_number FROM sms_opt_outs WHERE phone_number IN (' . $in . ')');
                $oo->execute($eligibleNums);
                $opted = array_map(static fn($r) => (string) (($r['phone_number'] ?? '') ?: ''), $oo->fetchAll());
                $optedSet = array_flip(array_filter($opted));
                $eligibleNums = array_values(array_filter($eligibleNums, static fn($n) => !array_key_exists($n, $optedSet)));
            }

            $sampleNums = array_slice($eligibleNums, 0, 20);
            $sample = [];
            if (count($sampleNums) > 0) {
                $in = implode(',', array_fill(0, count($sampleNums), '?'));
                $cStmt = $pdo->prepare('SELECT id, first_name, last_name, name, phone_number, email FROM contacts WHERE phone_number IN (' . $in . ')');
                $cStmt->execute($sampleNums);
                $rows = $cStmt->fetchAll();
                $byPhone = [];
                if (is_array($rows)) {
                    foreach ($rows as $r) {
                        $pn = (string) (($r['phone_number'] ?? '') ?: '');
                        if ($pn !== '') {
                            $byPhone[$pn] = $r;
                        }
                    }
                }
                foreach ($sampleNums as $pn) {
                    if (array_key_exists($pn, $byPhone)) {
                        $sample[] = $byPhone[$pn];
                    } else {
                        $sample[] = ['id' => null, 'first_name' => null, 'last_name' => null, 'name' => null, 'phone_number' => $pn, 'email' => null];
                    }
                }
            }
        } else {
            json(['error' => 'Unknown mode'], 422);
        }

        $eligible = max(0, $count - $optCount);
        json(['count_total' => $count, 'count_opted_out' => $optCount, 'count_eligible' => $eligible, 'sample' => $sample]);
        return true;

        } catch (\Throwable $e) {
            error_log('broadcast_preview_failed: ' . $e->getMessage());
            $msg = substr((string) $e->getMessage(), 0, 240);
            json(['error' => 'Server error', 'detail' => $msg], 500);
        }
    }

    if ($uri === '/api/broadcast/send' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'broadcast.use');
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        $mode = trim((string) ($payload['mode'] ?? ''));
        $q = trim((string) ($payload['q'] ?? ''));
        $groupId = (int) ($payload['group_id'] ?? 0);
        $tagId = (int) ($payload['tag_id'] ?? 0);
        $numbersRaw = (string) ($payload['numbers'] ?? '');
        $body = trim((string) ($payload['body'] ?? ''));
        $fromNumberId = (int) ($payload['from_number_id'] ?? 0);
        $dryRun = array_key_exists('dry_run', $payload) ? (bool) $payload['dry_run'] : true;
        $batchSize = (int) ($payload['batch_size'] ?? 200);
        $sendDelayMs = (int) ($payload['send_delay_ms'] ?? 0);
        if ($batchSize < 1) $batchSize = 1;
        if ($batchSize > 500) $batchSize = 500;
        if ($sendDelayMs < 0) $sendDelayMs = 0;
        if ($sendDelayMs > 5000) $sendDelayMs = 5000;
        if ($body === '' || $fromNumberId <= 0) {
            json(['error' => 'body and from_number_id required'], 422);
        }

        if ($mode === '') {
            $mode = ($q !== '') ? 'search' : 'all';
        }

        $chk = $pdo->prepare('SELECT n.phone_number FROM numbers n
        INNER JOIN user_numbers un ON un.number_id = n.id
        WHERE un.user_id = :uid AND n.id = :nid LIMIT 1');
        $chk->execute([':uid' => $uid, ':nid' => $fromNumberId]);
        $fromRow = $chk->fetch();
        if (!$fromRow) {
            json(['error' => 'You do not have access to that From number'], 403);
        }

        $optEnabled = appSettingGet($pdo, 'sms_opt_out_enabled', '1') === '1';

        $count = 0;
        $optCount = 0;

        if ($mode === 'all' || $mode === 'search') {
            $where = ' WHERE ct.phone_number IS NOT NULL AND ct.phone_number <> \'\'';
            $where .= " AND ct.phone_number REGEXP '^[+]?[0-9]{7,20}$'";
            $params = [];
            if ($mode === 'search' && $q !== '') {
                $where .= ' AND (ct.phone_number LIKE :q OR ct.name LIKE :q OR ct.first_name LIKE :q OR ct.last_name LIKE :q OR ct.email LIKE :q)';
                $params[':q'] = '%' . $q . '%';
            }
            $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts ct' . $where);
            $cntStmt->execute($params);
            $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);

            if ($optEnabled) {
                $optStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts ct
                INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number' . $where);
                $optStmt->execute($params);
                $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
            }
        } elseif ($mode === 'group') {
            if ($groupId <= 0) {
                json(['error' => 'group_id required'], 422);
            }
            $chk2 = $pdo->prepare('SELECT id FROM contact_groups WHERE id = :id AND user_id = :uid LIMIT 1');
            $chk2->execute([':id' => $groupId, ':uid' => $uid]);
            if (!$chk2->fetch()) {
                json(['error' => 'Unknown group'], 404);
            }
            $cntStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c
                FROM contacts ct
                INNER JOIN contact_group_members m ON m.contact_id = ct.id
                WHERE m.group_id = :gid');
            $cntStmt->execute([':gid' => $groupId]);
            $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);
            if ($optEnabled) {
                $optStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c
                    FROM contacts ct
                    INNER JOIN contact_group_members m ON m.contact_id = ct.id
                    INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number
                    WHERE m.group_id = :gid');
                $optStmt->execute([':gid' => $groupId]);
                $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
            }
        } elseif ($mode === 'tag') {
            if ($tagId <= 0) {
                json(['error' => 'tag_id required'], 422);
            }
            $chk2 = $pdo->prepare('SELECT id FROM contact_tags WHERE id = :id AND user_id = :uid LIMIT 1');
            $chk2->execute([':id' => $tagId, ':uid' => $uid]);
            if (!$chk2->fetch()) {
                json(['error' => 'Unknown tag'], 404);
            }
            $cntStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c
                FROM contacts ct
                INNER JOIN contact_tag_members m ON m.contact_id = ct.id
                WHERE m.tag_id = :tid');
            $cntStmt->execute([':tid' => $tagId]);
            $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);
            if ($optEnabled) {
                $optStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c
                    FROM contacts ct
                    INNER JOIN contact_tag_members m ON m.contact_id = ct.id
                    INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number
                    WHERE m.tag_id = :tid');
                $optStmt->execute([':tid' => $tagId]);
                $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
            }
 } elseif ($mode === 'contacts') {
 $contactIdsRaw = (string) ($payload['contact_ids'] ?? '');
 $contactIds = [];
 if ($contactIdsRaw !== '') {
 $ids = json_decode($contactIdsRaw, true);
 if (is_array($ids)) {
 foreach ($ids as $id) {
 $n = (int) ($id ?? 0);
 if ($n > 0) { $contactIds[] = $n; }
 }
 }
 }
 $count = count($contactIds);
 $optCount = 0;
 $sample = [];
 if ($count === 0) {
 $eligible = 0;
 } else {
 $in = implode(',', array_fill(0, $count, '?'));
 $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts ct WHERE ct.id IN (' . $in . ')');
 $cntStmt->execute($contactIds);
 $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);
 if ($optEnabled) {
 $optStmt = $pdo->prepare('SELECT COUNT(DISTINCT ct.id) AS c FROM contacts ct INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number WHERE ct.id IN (' . $in . ')');
 $optStmt->execute($contactIds);
 $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
 }
 $eligible = max(0, $count - $optCount);
 $sampleStmt = $pdo->prepare('SELECT id, first_name, last_name, name, phone_number, email FROM contacts WHERE id IN (' . $in . ') ORDER BY id DESC LIMIT 20');
 $sampleStmt->execute($contactIds);
 $sample = $sampleStmt->fetchAll();
 }
        } elseif ($mode === 'paste') {
            $lines = preg_split('/\r\n|\r|\n/', $numbersRaw);
            $nums = [];
            if (is_array($lines)) {
                foreach ($lines as $ln) {
                    $v = trim((string) $ln);
                    if ($v === '') {
                        continue;
                    }
                    if (!preg_match('/^\+?[0-9]{7,20}$/', $v)) {
                        continue;
                    }
                    $nums[] = $v;
                }
            }
            $nums = array_values(array_unique($nums));
            $count = count($nums);
            if ($optEnabled && $count > 0) {
                $in = implode(',', array_fill(0, $count, '?'));
                $oStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM sms_opt_outs WHERE phone_number IN (' . $in . ')');
                $oStmt->execute($nums);
                $optCount = (int) (($oStmt->fetch()['c'] ?? 0) ?: 0);
            }
        } else {
            json(['error' => 'Unknown mode'], 422);
        }

        $eligible = max(0, $count - $optCount);

        if ($dryRun) {
            json(['ok' => true, 'dry_run' => true, 'count_total' => $count, 'count_opted_out' => $optCount, 'count_eligible' => $eligible]);
        }

        $scheduledAtUtc = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO broadcast_jobs (user_id, from_number_id, body, mode, q, group_id, tag_id, numbers_raw, batch_size, send_delay_ms, scheduled_at_utc, status, total_count, opted_out_count, started_at)
            VALUES (:uid, :fn, :body, :mode, :q, :gid, :tid, :nums, :bs, :sd, :sat, \'running\', :tc, :oc, UTC_TIMESTAMP())');
        $stmt->execute([
            ':uid' => $uid,
            ':fn' => $fromNumberId,
            ':body' => $body,
            ':mode' => $mode,
            ':q' => $q !== '' ? $q : null,
            ':gid' => $groupId > 0 ? $groupId : null,
            ':tid' => $tagId > 0 ? $tagId : null,
            ':nums' => trim($numbersRaw) !== '' ? $numbersRaw : null,
            ':bs' => $batchSize,
            ':sd' => $sendDelayMs,
            ':sat' => $scheduledAtUtc,
            ':tc' => max(0, $count),
            ':oc' => max(0, $optCount),
        ]);
        $jobId = (int) $pdo->lastInsertId();
        $jobStmt = $pdo->prepare('SELECT * FROM broadcast_jobs WHERE id = :id LIMIT 1');
        $jobStmt->execute([':id' => $jobId]);
        $job = $jobStmt->fetch();
        if (!is_array($job) || empty($job)) {
            json(['error' => 'Could not create broadcast job'], 500);
        }

        $ensureRecipients($pdo, $job);
        $batch = $processJobBatch($pdo, $job, $batchSize);

        $pending = 0;
        try {
            $p = $pdo->prepare("SELECT COUNT(*) AS c FROM broadcast_job_recipients WHERE job_id = :jid AND status = 'pending'");
            $p->execute([':jid' => $jobId]);
            $pending = (int) (($p->fetch()['c'] ?? 0) ?: 0);
        } catch (\Throwable $e) {
            $pending = 0;
        }
        if ($pending === 0) {
            try {
                $pdo->prepare("UPDATE broadcast_jobs SET status = 'finished', finished_at = UTC_TIMESTAMP() WHERE id = :id")
                    ->execute([':id' => $jobId]);
            } catch (\Throwable $e) {
            }
        }

        $sentCount = 0;
        try {
            $sc = $pdo->prepare("SELECT COUNT(*) AS c FROM broadcast_job_recipients WHERE job_id = :jid AND status = 'sent'");
            $sc->execute([':jid' => $jobId]);
            $sentCount = (int) (($sc->fetch()['c'] ?? 0) ?: 0);
        } catch (\Throwable $e) {
            $sentCount = 0;
        }

        json([
            'ok' => true,
            'dry_run' => false,
            'job_id' => $jobId,
            'sent_count' => $sentCount,
            'pending_count' => $pending,
            'count_total' => max(0, $count),
            'count_opted_out' => max(0, $optCount),
            'count_eligible' => $eligible,
            'batch' => $batch,
        ]);
        return true;
    }

    return false;
}
