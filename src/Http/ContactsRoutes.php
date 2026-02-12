<?php

declare(strict_types=1);

use App\Auth;

function handleContactsRoutes(string $uri, string $method, string $rootDir): bool
{
    if ($uri === '/api/contacts' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'contacts.view');
        $q = trim((string)($_GET['q'] ?? ''));

        $sql = 'SELECT id, first_name, last_name, name, phone_number, email, created_at FROM contacts WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (phone_number LIKE :q OR name LIKE :q OR first_name LIKE :q OR last_name LIKE :q OR email LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json(['contacts' => $stmt->fetchAll()]);
        return true;
    }

    if ($uri === '/api/contacts/full' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'contacts.view');
        $q = trim((string)($_GET['q'] ?? ''));

        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        $sql = 'SELECT id, first_name, last_name, name, phone_number, email, created_at FROM contacts WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (phone_number LIKE :q OR name LIKE :q OR first_name LIKE :q OR last_name LIKE :q OR email LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $contacts = $stmt->fetchAll();

        $fields = $pdo->query('SELECT id, field_key, label, created_at FROM contact_fields ORDER BY id ASC')->fetchAll();

        $ids = [];
        if (is_array($contacts)) {
            foreach ($contacts as $c) {
                $cid = (int) (($c['id'] ?? 0) ?: 0);
                if ($cid > 0) {
                    $ids[] = $cid;
                }
            }
        }

        $valuesByContact = [];
        if (count($ids) > 0) {
            $in = implode(',', array_map('intval', $ids));
            $vStmt = $pdo->query('SELECT cfv.contact_id, cf.field_key, cfv.value
            FROM contact_field_values cfv
            INNER JOIN contact_fields cf ON cf.id = cfv.field_id
            WHERE cfv.contact_id IN (' . $in . ')');
            $rows = $vStmt->fetchAll();
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $cid = (int) (($r['contact_id'] ?? 0) ?: 0);
                    $k = (string) (($r['field_key'] ?? '') ?: '');
                    if ($cid <= 0 || $k === '') {
                        continue;
                    }
                    if (!array_key_exists($cid, $valuesByContact)) {
                        $valuesByContact[$cid] = [];
                    }
                    $valuesByContact[$cid][$k] = ($r['value'] ?? null);
                }
            }
        }

        $tagsByContact = [];
        $groupsByContact = [];
        if (count($ids) > 0) {
            $in = implode(',', array_map('intval', $ids));

            $tStmt = $pdo->prepare('SELECT m.contact_id, t.id AS tag_id, t.name
            FROM contact_tag_members m
            INNER JOIN contact_tags t ON t.id = m.tag_id
            WHERE m.contact_id IN (' . $in . ') AND t.user_id = :uid
            ORDER BY t.name ASC');
            $tStmt->execute([':uid' => $uid]);
            $tRows = $tStmt->fetchAll();
            if (is_array($tRows)) {
                foreach ($tRows as $r) {
                    $cid = (int) (($r['contact_id'] ?? 0) ?: 0);
                    $tid = (int) (($r['tag_id'] ?? 0) ?: 0);
                    $name = (string) (($r['name'] ?? '') ?: '');
                    if ($cid <= 0 || $tid <= 0 || $name === '') {
                        continue;
                    }
                    if (!array_key_exists($cid, $tagsByContact)) {
                        $tagsByContact[$cid] = [];
                    }
                    $tagsByContact[$cid][] = ['id' => $tid, 'name' => $name];
                }
            }

            $gStmt = $pdo->prepare('SELECT m.contact_id, g.id AS group_id, g.name
            FROM contact_group_members m
            INNER JOIN contact_groups g ON g.id = m.group_id
            WHERE m.contact_id IN (' . $in . ') AND g.user_id = :uid
            ORDER BY g.name ASC');
            $gStmt->execute([':uid' => $uid]);
            $gRows = $gStmt->fetchAll();
            if (is_array($gRows)) {
                foreach ($gRows as $r) {
                    $cid = (int) (($r['contact_id'] ?? 0) ?: 0);
                    $gid = (int) (($r['group_id'] ?? 0) ?: 0);
                    $name = (string) (($r['name'] ?? '') ?: '');
                    if ($cid <= 0 || $gid <= 0 || $name === '') {
                        continue;
                    }
                    if (!array_key_exists($cid, $groupsByContact)) {
                        $groupsByContact[$cid] = [];
                    }
                    $groupsByContact[$cid][] = ['id' => $gid, 'name' => $name];
                }
            }
        }

        json([
            'contacts' => $contacts,
            'fields' => $fields,
            'values_by_contact' => $valuesByContact,
            'tags_by_contact' => $tagsByContact,
            'groups_by_contact' => $groupsByContact,
        ]);
        return true;
    }

    if ($uri === '/api/contacts/add' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $phone = trim((string) ($payload['phone_number'] ?? ''));
        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        if ($phone === '') {
            json(['error' => 'phone_number required'], 422);
        }
        if (!preg_match('/^\+?[0-9]{7,20}$/', $phone)) {
            json(['error' => 'Invalid phone number'], 422);
        }

        if ($name === '' && ($firstName !== '' || $lastName !== '')) {
            $name = trim($firstName . ' ' . $lastName);
        }

        try {
            $pdo->prepare('INSERT INTO contacts (phone_number, first_name, last_name, name, email) VALUES (:p, :fn, :ln, :n, :e)
            ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), name = VALUES(name), email = VALUES(email)')
                ->execute([
                    ':p' => $phone,
                    ':fn' => ($firstName === '' ? null : $firstName),
                    ':ln' => ($lastName === '' ? null : $lastName),
                    ':n' => ($name === '' ? null : $name),
                    ':e' => ($email === '' ? null : $email),
                ]);
        } catch (\Throwable $e) {
            json(['error' => 'Save failed'], 500);
        }

        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/contacts/bulk-save' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $contacts = $payload['contacts'] ?? [];
        $fieldValues = $payload['field_values'] ?? [];
        if (!is_array($contacts) || !is_array($fieldValues)) {
            json(['error' => 'Invalid payload'], 422);
        }

        $fields = $pdo->query('SELECT id, field_key FROM contact_fields')->fetchAll();
        $byKey = [];
        if (is_array($fields)) {
            foreach ($fields as $f) {
                $k = (string) (($f['field_key'] ?? '') ?: '');
                $fid = (int) (($f['id'] ?? 0) ?: 0);
                if ($k !== '' && $fid > 0) {
                    $byKey[$k] = $fid;
                }
            }
        }

        try {
            $pdo->beginTransaction();

            if (count($contacts) > 0) {
                $up = $pdo->prepare('UPDATE contacts SET first_name = :fn, last_name = :ln, name = :n, email = :e WHERE id = :id');
                foreach ($contacts as $c) {
                    if (!is_array($c)) {
                        continue;
                    }
                    $id = (int) ($c['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $firstName = trim((string) ($c['first_name'] ?? ''));
                    $lastName = trim((string) ($c['last_name'] ?? ''));
                    $name = trim((string) ($c['name'] ?? ''));
                    $email = trim((string) ($c['email'] ?? ''));
                    if ($name === '' && ($firstName !== '' || $lastName !== '')) {
                        $name = trim($firstName . ' ' . $lastName);
                    }
                    $up->execute([
                        ':id' => $id,
                        ':fn' => ($firstName === '' ? null : $firstName),
                        ':ln' => ($lastName === '' ? null : $lastName),
                        ':n' => ($name === '' ? null : $name),
                        ':e' => ($email === '' ? null : $email),
                    ]);
                }
            }

            if (count($fieldValues) > 0 && count($byKey) > 0) {
                $ins = $pdo->prepare('INSERT INTO contact_field_values (contact_id, field_id, value) VALUES (:cid, :fid, :v)
                ON DUPLICATE KEY UPDATE value = VALUES(value)');
                foreach ($fieldValues as $cidStr => $values) {
                    $cid = (int) $cidStr;
                    if ($cid <= 0 || !is_array($values)) {
                        continue;
                    }
                    foreach ($values as $k => $v) {
                        $key = trim((string) $k);
                        if ($key === '' || !array_key_exists($key, $byKey)) {
                            continue;
                        }
                        $val = trim((string) ($v ?? ''));
                        $ins->execute([
                            ':cid' => $cid,
                            ':fid' => (int) $byKey[$key],
                            ':v' => ($val === '' ? null : $val),
                        ]);
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

    if ($uri === '/api/contacts/export' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $q = trim((string)($_GET['q'] ?? ''));

        $sql = 'SELECT id, first_name, last_name, name, phone_number, email, created_at FROM contacts WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (phone_number LIKE :q OR name LIKE :q OR first_name LIKE :q OR last_name LIKE :q OR email LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY id DESC LIMIT 5000';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $contacts = $stmt->fetchAll();

        $fields = $pdo->query('SELECT id, field_key FROM contact_fields ORDER BY id ASC')->fetchAll();
        $fieldKeys = [];
        if (is_array($fields)) {
            foreach ($fields as $f) {
                $k = (string) (($f['field_key'] ?? '') ?: '');
                if ($k !== '') {
                    $fieldKeys[] = $k;
                }
            }
        }

        $valuesByContact = [];
        $ids = [];
        if (is_array($contacts)) {
            foreach ($contacts as $c) {
                $cid = (int) (($c['id'] ?? 0) ?: 0);
                if ($cid > 0) {
                    $ids[] = $cid;
                }
            }
        }
        if (count($ids) > 0 && count($fieldKeys) > 0) {
            $in = implode(',', array_map('intval', $ids));
            $vStmt = $pdo->query('SELECT cfv.contact_id, cf.field_key, cfv.value
            FROM contact_field_values cfv
            INNER JOIN contact_fields cf ON cf.id = cfv.field_id
            WHERE cfv.contact_id IN (' . $in . ')');
            $rows = $vStmt->fetchAll();
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $cid = (int) (($r['contact_id'] ?? 0) ?: 0);
                    $k = (string) (($r['field_key'] ?? '') ?: '');
                    if ($cid <= 0 || $k === '') {
                        continue;
                    }
                    if (!array_key_exists($cid, $valuesByContact)) {
                        $valuesByContact[$cid] = [];
                    }
                    $valuesByContact[$cid][$k] = (string) (($r['value'] ?? '') ?: '');
                }
            }
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contacts.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, array_merge(['phone_number', 'first_name', 'last_name', 'name', 'email'], $fieldKeys));
        if (is_array($contacts)) {
            foreach ($contacts as $c) {
                $cid = (int) (($c['id'] ?? 0) ?: 0);
                $row = [
                    (string) (($c['phone_number'] ?? '') ?: ''),
                    (string) (($c['first_name'] ?? '') ?: ''),
                    (string) (($c['last_name'] ?? '') ?: ''),
                    (string) (($c['name'] ?? '') ?: ''),
                    (string) (($c['email'] ?? '') ?: ''),
                ];
                foreach ($fieldKeys as $k) {
                    $row[] = (string) (($valuesByContact[$cid][$k] ?? '') ?: '');
                }
                fputcsv($out, $row);
            }
        }
        fclose($out);
        exit;
    }

    if ($uri === '/api/contacts/import' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $csv = (string) ($payload['csv'] ?? '');
        if (trim($csv) === '') {
            json(['error' => 'csv required'], 422);
        }

        $lines = preg_split("/\r\n|\n|\r/", $csv);
        $lines = array_values(array_filter(array_map('trim', $lines), fn($x) => $x !== ''));
        if (count($lines) < 2) {
            json(['error' => 'CSV must include header and at least one row'], 422);
        }

        $header = str_getcsv($lines[0]);
        $cols = [];
        foreach ($header as $h) {
            $k = trim((string) $h);
            if ($k !== '') {
                $cols[] = $k;
            }
        }
        if (count($cols) === 0) {
            json(['error' => 'Invalid header'], 422);
        }

        $fields = $pdo->query('SELECT id, field_key FROM contact_fields')->fetchAll();
        $byKey = [];
        if (is_array($fields)) {
            foreach ($fields as $f) {
                $k = (string) (($f['field_key'] ?? '') ?: '');
                $fid = (int) (($f['id'] ?? 0) ?: 0);
                if ($k !== '' && $fid > 0) {
                    $byKey[$k] = $fid;
                }
            }
        }

        $upsert = $pdo->prepare('INSERT INTO contacts (phone_number, first_name, last_name, name, email) VALUES (:p, :fn, :ln, :n, :e)
        ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), name = VALUES(name), email = VALUES(email)');
        $getId = $pdo->prepare('SELECT id FROM contacts WHERE phone_number = :p LIMIT 1');
        $insVal = $pdo->prepare('INSERT INTO contact_field_values (contact_id, field_id, value) VALUES (:cid, :fid, :v)
        ON DUPLICATE KEY UPDATE value = VALUES(value)');

        $imported = 0;
        try {
            $pdo->beginTransaction();
            for ($i = 1; $i < count($lines); $i++) {
                $row = str_getcsv($lines[$i]);
                $data = [];
                for ($j = 0; $j < count($cols); $j++) {
                    $data[$cols[$j]] = array_key_exists($j, $row) ? $row[$j] : '';
                }

                $phone = trim((string) ($data['phone_number'] ?? $data['phone'] ?? ''));
                if ($phone === '' || !preg_match('/^\+?[0-9]{7,20}$/', $phone)) {
                    continue;
                }

                $firstName = trim((string) ($data['first_name'] ?? ''));
                $lastName = trim((string) ($data['last_name'] ?? ''));
                $name = trim((string) ($data['name'] ?? ''));
                if ($name === '' && ($firstName !== '' || $lastName !== '')) {
                    $name = trim($firstName . ' ' . $lastName);
                }
                $email = trim((string) ($data['email'] ?? ''));

                $upsert->execute([
                    ':p' => $phone,
                    ':fn' => ($firstName === '' ? null : $firstName),
                    ':ln' => ($lastName === '' ? null : $lastName),
                    ':n' => ($name === '' ? null : $name),
                    ':e' => ($email === '' ? null : $email),
                ]);

                $getId->execute([':p' => $phone]);
                $cid = (int) (($getId->fetch()['id'] ?? 0) ?: 0);
                if ($cid <= 0) {
                    continue;
                }

                foreach ($data as $k => $v) {
                    $key = trim((string) $k);
                    if ($key === '' || !array_key_exists($key, $byKey)) {
                        continue;
                    }
                    $val = trim((string) ($v ?? ''));
                    $insVal->execute([
                        ':cid' => $cid,
                        ':fid' => (int) $byKey[$key],
                        ':v' => ($val === '' ? null : $val),
                    ]);
                }

                $imported++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            try {
                $pdo->rollBack();
            } catch (\Throwable $e2) {
            }
            json(['error' => 'Import failed'], 500);
        }

        json(['ok' => true, 'imported' => $imported]);
        return true;
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
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        if ($id <= 0) {
            json(['error' => 'id required'], 422);
        }
        if ($name === '' && ($firstName !== '' || $lastName !== '')) {
            $name = trim($firstName . ' ' . $lastName);
        }
        $stmt = $pdo->prepare('UPDATE contacts SET first_name = :fn, last_name = :ln, name = :name, email = :email WHERE id = :id');
        $stmt->execute([
            ':fn' => ($firstName === '' ? null : $firstName),
            ':ln' => ($lastName === '' ? null : $lastName),
            ':name' => ($name === '' ? null : $name),
            ':email' => ($email === '' ? null : $email),
            ':id' => $id,
        ]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/contacts/bulk-assign-tag' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $tagId = (int) ($payload['tag_id'] ?? 0);
        $rawIds = $payload['contact_ids'] ?? null;
        if ($tagId <= 0 || !is_array($rawIds)) {
            json(['error' => 'tag_id and contact_ids required'], 422);
        }

        $contactIds = [];
        foreach ($rawIds as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $contactIds[$id] = true;
            }
        }
        $contactIds = array_keys($contactIds);
        if (count($contactIds) === 0) {
            json(['error' => 'No valid contact_ids'], 422);
        }

        $chk = $pdo->prepare('SELECT id FROM contact_tags WHERE id = :id AND user_id = :uid LIMIT 1');
        $chk->execute([':id' => $tagId, ':uid' => $uid]);
        if (!$chk->fetch()) {
            json(['error' => 'Unknown tag'], 404);
        }

        $ins = $pdo->prepare('INSERT IGNORE INTO contact_tag_members (tag_id, contact_id) VALUES (:tid, :cid)');
        foreach ($contactIds as $cid) {
            $ins->execute([':tid' => $tagId, ':cid' => (int) $cid]);
        }
        json(['ok' => true, 'updated' => count($contactIds)]);
        return true;
    }

    if ($uri === '/api/contacts/bulk-assign-group' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $groupId = (int) ($payload['group_id'] ?? 0);
        $rawIds = $payload['contact_ids'] ?? null;
        if ($groupId <= 0 || !is_array($rawIds)) {
            json(['error' => 'group_id and contact_ids required'], 422);
        }

        $contactIds = [];
        foreach ($rawIds as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $contactIds[$id] = true;
            }
        }
        $contactIds = array_keys($contactIds);
        if (count($contactIds) === 0) {
            json(['error' => 'No valid contact_ids'], 422);
        }

        $chk = $pdo->prepare('SELECT id FROM contact_groups WHERE id = :id AND user_id = :uid LIMIT 1');
        $chk->execute([':id' => $groupId, ':uid' => $uid]);
        if (!$chk->fetch()) {
            json(['error' => 'Unknown group'], 404);
        }

        $ins = $pdo->prepare('INSERT IGNORE INTO contact_group_members (group_id, contact_id) VALUES (:gid, :cid)');
        foreach ($contactIds as $cid) {
            $ins->execute([':gid' => $groupId, ':cid' => (int) $cid]);
        }
        json(['ok' => true, 'updated' => count($contactIds)]);
        return true;
    }

    if ($uri === '/api/contacts/bulk-delete' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $rawIds = $payload['contact_ids'] ?? null;
        if (!is_array($rawIds)) {
            json(['error' => 'contact_ids required'], 422);
        }

        $contactIds = [];
        foreach ($rawIds as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $contactIds[$id] = true;
            }
        }
        $contactIds = array_keys($contactIds);
        if (count($contactIds) === 0) {
            json(['error' => 'No valid contact_ids'], 422);
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM contacts WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_map('intval', $contactIds));
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

    if ($uri === '/api/contacts/fields' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $stmt = $pdo->query('SELECT id, field_key, label, created_at FROM contact_fields ORDER BY id ASC');
        json(['fields' => $stmt->fetchAll()]);
        return true;
    }

    if ($uri === '/api/contacts/fields/add' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }

        $fieldKey = trim((string) ($payload['field_key'] ?? ''));
        $label = trim((string) ($payload['label'] ?? ''));
        if ($fieldKey === '' || $label === '') {
            json(['error' => 'field_key and label required'], 422);
        }
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,63}$/', $fieldKey)) {
            json(['error' => 'Invalid field_key (use letters, numbers, underscore; start with a letter)'], 422);
        }

        try {
            $pdo->prepare('INSERT INTO contact_fields (field_key, label) VALUES (:k, :l)')
                ->execute([':k' => $fieldKey, ':l' => $label]);
        } catch (\Throwable $e) {
            json(['error' => 'Could not create field (field_key may already exist)'], 409);
        }

        json(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
        return true;
    }

    if ($uri === '/api/contacts/fields/delete' && $method === 'POST') {
        Auth::requireLogin();
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

    if ($uri === '/api/contacts/fields/values' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $contactId = (int) ($_GET['contact_id'] ?? 0);
        if ($contactId <= 0) {
            json(['error' => 'contact_id required'], 422);
        }

        $stmt = $pdo->prepare('SELECT cf.field_key, cfv.value
        FROM contact_fields cf
        LEFT JOIN contact_field_values cfv ON cfv.field_id = cf.id AND cfv.contact_id = :cid
        ORDER BY cf.id ASC');
        $stmt->execute([':cid' => $contactId]);
        $rows = $stmt->fetchAll();
        $values = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                $k = (string) (($r['field_key'] ?? '') ?: '');
                if ($k === '') {
                    continue;
                }
                $values[$k] = ($r['value'] ?? null);
            }
        }
        json(['values' => $values]);
        return true;
    }

    if ($uri === '/api/contacts/fields/values' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $contactId = (int) ($payload['contact_id'] ?? 0);
        $values = $payload['values'] ?? null;
        if ($contactId <= 0 || !is_array($values)) {
            json(['error' => 'contact_id and values required'], 422);
        }

        $fields = $pdo->query('SELECT id, field_key FROM contact_fields')->fetchAll();
        $byKey = [];
        if (is_array($fields)) {
            foreach ($fields as $f) {
                $k = (string) (($f['field_key'] ?? '') ?: '');
                $fid = (int) (($f['id'] ?? 0) ?: 0);
                if ($k !== '' && $fid > 0) {
                    $byKey[$k] = $fid;
                }
            }
        }

        try {
            $pdo->beginTransaction();
            $up = $pdo->prepare('INSERT INTO contact_field_values (contact_id, field_id, value) VALUES (:cid, :fid, :v)
            ON DUPLICATE KEY UPDATE value = VALUES(value)');
            foreach ($values as $k => $v) {
                $key = trim((string) $k);
                if ($key === '' || !array_key_exists($key, $byKey)) {
                    continue;
                }
                $val = trim((string) ($v ?? ''));
                $up->execute([
                    ':cid' => $contactId,
                    ':fid' => (int) $byKey[$key],
                    ':v' => ($val === '' ? null : $val),
                ]);
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

    if ($uri === '/api/contacts/notes' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $contactId = (int) ($_GET['contact_id'] ?? 0);
        if ($contactId <= 0) {
            json(['error' => 'contact_id required'], 422);
        }
        $stmt = $pdo->prepare('SELECT cn.id, cn.note, cn.created_at, u.email AS user_email
        FROM contact_notes cn
        INNER JOIN users u ON u.id = cn.user_id
        WHERE cn.contact_id = :cid
        ORDER BY cn.id DESC
        LIMIT 1');
        $stmt->execute([':cid' => $contactId]);
        $row = $stmt->fetch();
        $notes = [];
        if ($row) {
            $notes[] = $row;
        }
        json(['notes' => $notes]);
        return true;
    }

    if ($uri === '/api/contacts/notes' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $contactId = (int) ($payload['contact_id'] ?? 0);
        $note = trim((string) ($payload['note'] ?? ''));
        if ($contactId <= 0 || $note === '') {
            json(['error' => 'contact_id and note required'], 422);
        }
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM contact_notes WHERE contact_id = :cid')
                ->execute([':cid' => $contactId]);
            $stmt = $pdo->prepare('INSERT INTO contact_notes (contact_id, user_id, note) VALUES (:cid, :uid, :n)');
            $stmt->execute([':cid' => $contactId, ':uid' => $uid, ':n' => $note]);
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

    return false;
}
