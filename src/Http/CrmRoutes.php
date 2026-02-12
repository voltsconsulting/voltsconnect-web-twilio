<?php

declare(strict_types=1);

use App\Auth;

function handleCrmRoutes(string $uri, string $method, string $rootDir): bool
{
    if ($uri === '/api/crm/tags' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        $stmt = $pdo->prepare('SELECT t.id, t.name, t.created_at, COUNT(m.contact_id) AS member_count
            FROM contact_tags t
            LEFT JOIN contact_tag_members m ON m.tag_id = t.id
            WHERE t.user_id = :uid
            GROUP BY t.id, t.name, t.created_at
            ORDER BY t.name ASC');
        $stmt->execute([':uid' => $uid]);
        json(['tags' => $stmt->fetchAll()]);
        return true;
    }

    if ($uri === '/api/crm/tags/add' && $method === 'POST') {
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
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            json(['error' => 'name required'], 422);
        }

        try {
            $pdo->prepare('INSERT INTO contact_tags (user_id, name) VALUES (:uid, :n)')
                ->execute([':uid' => $uid, ':n' => $name]);
        } catch (\Throwable $e) {
            json(['error' => 'Could not create tag (name may already exist)'], 409);
        }

        $newId = (int) $pdo->lastInsertId();
        json(['ok' => true, 'id' => $newId]);
        return true;
    }

    if ($uri === '/api/crm/tags/delete' && $method === 'POST') {
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
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            json(['error' => 'id required'], 422);
        }

        $pdo->prepare('DELETE FROM contact_tags WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $id, ':uid' => $uid]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/crm/tags/assign' && $method === 'POST') {
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
        $contactId = (int) ($payload['contact_id'] ?? 0);
        if ($tagId <= 0 || $contactId <= 0) {
            json(['error' => 'tag_id and contact_id required'], 422);
        }

        $chk = $pdo->prepare('SELECT id FROM contact_tags WHERE id = :id AND user_id = :uid LIMIT 1');
        $chk->execute([':id' => $tagId, ':uid' => $uid]);
        if (!$chk->fetch()) {
            json(['error' => 'Unknown tag'], 404);
        }

        $pdo->prepare('INSERT IGNORE INTO contact_tag_members (tag_id, contact_id) VALUES (:tid, :cid)')
            ->execute([':tid' => $tagId, ':cid' => $contactId]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/crm/tags/unassign' && $method === 'POST') {
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
        $contactId = (int) ($payload['contact_id'] ?? 0);
        if ($tagId <= 0 || $contactId <= 0) {
            json(['error' => 'tag_id and contact_id required'], 422);
        }

        $chk = $pdo->prepare('SELECT id FROM contact_tags WHERE id = :id AND user_id = :uid LIMIT 1');
        $chk->execute([':id' => $tagId, ':uid' => $uid]);
        if (!$chk->fetch()) {
            json(['error' => 'Unknown tag'], 404);
        }

        $pdo->prepare('DELETE FROM contact_tag_members WHERE tag_id = :tid AND contact_id = :cid')
            ->execute([':tid' => $tagId, ':cid' => $contactId]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/crm/groups' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        $stmt = $pdo->prepare('SELECT g.id, g.name, g.created_at, COUNT(m.contact_id) AS member_count
            FROM contact_groups g
            LEFT JOIN contact_group_members m ON m.group_id = g.id
            WHERE g.user_id = :uid
            GROUP BY g.id, g.name, g.created_at
            ORDER BY g.name ASC');
        $stmt->execute([':uid' => $uid]);
        json(['groups' => $stmt->fetchAll()]);
        return true;
    }

    if ($uri === '/api/crm/groups/add' && $method === 'POST') {
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
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            json(['error' => 'name required'], 422);
        }

        try {
            $pdo->prepare('INSERT INTO contact_groups (user_id, name) VALUES (:uid, :n)')
                ->execute([':uid' => $uid, ':n' => $name]);
        } catch (\Throwable $e) {
            json(['error' => 'Could not create group (name may already exist)'], 409);
        }

        $newId = (int) $pdo->lastInsertId();
        json(['ok' => true, 'id' => $newId]);
        return true;
    }

    if ($uri === '/api/crm/groups/delete' && $method === 'POST') {
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
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            json(['error' => 'id required'], 422);
        }

        $pdo->prepare('DELETE FROM contact_groups WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $id, ':uid' => $uid]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/crm/groups/assign' && $method === 'POST') {
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
        $contactId = (int) ($payload['contact_id'] ?? 0);
        if ($groupId <= 0 || $contactId <= 0) {
            json(['error' => 'group_id and contact_id required'], 422);
        }

        $chk = $pdo->prepare('SELECT id FROM contact_groups WHERE id = :id AND user_id = :uid LIMIT 1');
        $chk->execute([':id' => $groupId, ':uid' => $uid]);
        if (!$chk->fetch()) {
            json(['error' => 'Unknown group'], 404);
        }

        $pdo->prepare('INSERT IGNORE INTO contact_group_members (group_id, contact_id) VALUES (:gid, :cid)')
            ->execute([':gid' => $groupId, ':cid' => $contactId]);
        json(['ok' => true]);
        return true;
    }

    if ($uri === '/api/crm/groups/unassign' && $method === 'POST') {
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
        $contactId = (int) ($payload['contact_id'] ?? 0);
        if ($groupId <= 0 || $contactId <= 0) {
            json(['error' => 'group_id and contact_id required'], 422);
        }

        $chk = $pdo->prepare('SELECT id FROM contact_groups WHERE id = :id AND user_id = :uid LIMIT 1');
        $chk->execute([':id' => $groupId, ':uid' => $uid]);
        if (!$chk->fetch()) {
            json(['error' => 'Unknown group'], 404);
        }

        $pdo->prepare('DELETE FROM contact_group_members WHERE group_id = :gid AND contact_id = :cid')
            ->execute([':gid' => $groupId, ':cid' => $contactId]);
        json(['ok' => true]);
        return true;
    }

    return false;
}
