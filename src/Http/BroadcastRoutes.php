<?php

declare(strict_types=1);

use App\Auth;

function handleBroadcastRoutes(string $uri, string $method, string $rootDir): bool
{
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
            $where = ' WHERE 1=1';
            $params = [];
            if ($mode === 'search' && $q !== '') {
                $where .= ' AND (phone_number LIKE :q OR name LIKE :q OR first_name LIKE :q OR last_name LIKE :q OR email LIKE :q)';
                $params[':q'] = '%' . $q . '%';
            }

            $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts' . $where);
            $cntStmt->execute($params);
            $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);

            if ($optEnabled) {
                $optStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts ct
                INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number' . str_replace(' WHERE ', ' WHERE ct.', $where));
                $optStmt->execute($params);
                $optCount = (int) (($optStmt->fetch()['c'] ?? 0) ?: 0);
            }

            $sampleWhere = $where;
            $sampleParams = $params;
            if ($optEnabled) {
                $sampleWhere = ' WHERE 1=1';
                $sampleParams = [];
                if ($mode === 'search' && $q !== '') {
                    $sampleWhere .= ' AND (phone_number LIKE :q OR name LIKE :q OR first_name LIKE :q OR last_name LIKE :q OR email LIKE :q)';
                    $sampleParams[':q'] = '%' . $q . '%';
                }
                $sampleWhere .= ' AND phone_number NOT IN (SELECT phone_number FROM sms_opt_outs)';
            }
            $sampleStmt = $pdo->prepare('SELECT id, first_name, last_name, name, phone_number, email FROM contacts' . $sampleWhere . ' ORDER BY id DESC LIMIT 20');
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
    }

    if ($uri === '/api/broadcast/send' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
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
            $where = ' WHERE 1=1';
            $params = [];
            if ($mode === 'search' && $q !== '') {
                $where .= ' AND (phone_number LIKE :q OR name LIKE :q OR first_name LIKE :q OR last_name LIKE :q OR email LIKE :q)';
                $params[':q'] = '%' . $q . '%';
            }
            $cntStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts' . $where);
            $cntStmt->execute($params);
            $count = (int) (($cntStmt->fetch()['c'] ?? 0) ?: 0);

            if ($optEnabled) {
                $optStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM contacts ct
                INNER JOIN sms_opt_outs oo ON oo.phone_number = ct.phone_number' . str_replace(' WHERE ', ' WHERE ct.', $where));
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

        if (!$dryRun) {
            json(['error' => 'Sending is not enabled yet. Use Preview (dry_run) first.'], 422);
        }

        json(['ok' => true, 'dry_run' => true, 'count_total' => $count, 'count_opted_out' => $optCount, 'count_eligible' => $eligible]);
        return true;
    }

    return false;
}
