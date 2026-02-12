<?php

declare(strict_types=1);

use App\Auth;

function handleTemplateRoutes(string $uri, string $method, string $rootDir): bool
{
    if ($uri === '/api/templates' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'templates.manage');
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        $stmt = $pdo->prepare('SELECT id, name, body, created_at, updated_at
            FROM message_templates
            WHERE user_id = :uid
            ORDER BY name ASC');
        $stmt->execute([':uid' => $uid]);
        json(['templates' => $stmt->fetchAll()]);
        return true;
    }

    if ($uri === '/api/templates/save' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'templates.manage');
        $uid = Auth::userId();
        if ($uid === null) {
            json(['error' => 'Not authenticated'], 401);
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }

        $id = (int) ($payload['id'] ?? 0);
        $name = trim((string) ($payload['name'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));

        if ($name === '' || $body === '') {
            json(['error' => 'name and body required'], 422);
        }

        if ($id > 0) {
            $chk = $pdo->prepare('SELECT id FROM message_templates WHERE id = :id AND user_id = :uid LIMIT 1');
            $chk->execute([':id' => $id, ':uid' => $uid]);
            if (!$chk->fetch()) {
                json(['error' => 'Not found'], 404);
            }

            try {
                $pdo->prepare('UPDATE message_templates SET name = :n, body = :b WHERE id = :id AND user_id = :uid')
                    ->execute([':n' => $name, ':b' => $body, ':id' => $id, ':uid' => $uid]);
            } catch (\Throwable $e) {
                json(['error' => 'Could not save template (name may already exist)'], 409);
            }

            json(['ok' => true, 'id' => $id]);
            return true;
        }

        try {
            $pdo->prepare('INSERT INTO message_templates (user_id, name, body) VALUES (:uid, :n, :b)')
                ->execute([':uid' => $uid, ':n' => $name, ':b' => $body]);
            $newId = (int) $pdo->lastInsertId();
            json(['ok' => true, 'id' => $newId]);
            return true;
        } catch (\Throwable $e) {
            json(['error' => 'Could not save template (name may already exist)'], 409);
        }
    }

    if ($uri === '/api/templates/delete' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'templates.manage');
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

        $pdo->prepare('DELETE FROM message_templates WHERE id = :id AND user_id = :uid')
            ->execute([':id' => $id, ':uid' => $uid]);
        json(['ok' => true]);
        return true;
    }

    return false;
}
