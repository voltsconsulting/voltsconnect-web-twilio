<?php

declare(strict_types=1);

use App\Auth;

function handleCallsRoutes(string $uri, string $method, string $rootDir): bool
{
    if ($uri === '/api/calls' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'calls.view');

        $q = trim((string)($_GET['q'] ?? ''));
        $direction = trim((string)($_GET['direction'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $userId = (int) ($_GET['user_id'] ?? 0);
        $fromDate = trim((string)($_GET['from_date'] ?? ''));
        $toDate = trim((string)($_GET['to_date'] ?? ''));

        $limit = (int) ($_GET['limit'] ?? 50);
        if ($limit < 1) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $where = ' WHERE 1=1';
        $params = [];

        if ($q !== '') {
            $where .= ' AND (c.from_number LIKE :q OR c.to_number LIKE :q OR c.twilio_sid LIKE :q OR u.email LIKE :q OR c.client_identity LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        if (in_array($direction, ['inbound', 'outbound'], true)) {
            $where .= ' AND c.direction = :dir';
            $params[':dir'] = $direction;
        }

        if ($status !== '') {
            $where .= ' AND c.status LIKE :st';
            $params[':st'] = '%' . $status . '%';
        }

        if ($userId > 0) {
            $where .= ' AND c.user_id = :uid';
            $params[':uid'] = $userId;
        }

        if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            $where .= ' AND c.created_at >= :fromdt';
            $params[':fromdt'] = $fromDate . ' 00:00:00';
        }
        if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $where .= ' AND c.created_at <= :todt';
            $params[':todt'] = $toDate . ' 23:59:59';
        }

        $stmt = $pdo->prepare('SELECT c.*, u.email AS user_email
        FROM calls c
        LEFT JOIN users u ON u.id = c.user_id'
            . $where .
            ' ORDER BY c.created_at DESC
        LIMIT ' . $limit);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        json(['calls' => $rows]);
        return true;
    }

    if ($uri === '/api/calls/bulk-delete' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'calls.manage');

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $rawIds = $payload['call_ids'] ?? null;
        if (!is_array($rawIds)) {
            json(['error' => 'call_ids required'], 422);
        }

        $callIds = [];
        foreach ($rawIds as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $callIds[$id] = true;
            }
        }
        $callIds = array_keys($callIds);
        if (count($callIds) === 0) {
            json(['error' => 'No valid call_ids'], 422);
        }

        $placeholders = implode(',', array_fill(0, count($callIds), '?'));
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM calls WHERE id IN (' . $placeholders . ')');
            $stmt->execute(array_map('intval', $callIds));
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

    return false;
}
