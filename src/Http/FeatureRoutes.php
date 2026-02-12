<?php

declare(strict_types=1);

use App\Auth;

function handleFeatureRoutes(string $uri, string $method, string $rootDir): bool
{
    if ($uri === '/api/analytics/quick' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'analytics.view');

        $today = (string) ($pdo->query('SELECT CURDATE() AS d')->fetch()['d'] ?? '');

        $contactsTotal = (int) (($pdo->query('SELECT COUNT(*) AS c FROM contacts')->fetch()['c'] ?? 0) ?: 0);
        $conversationsTotal = (int) (($pdo->query('SELECT COUNT(*) AS c FROM conversations')->fetch()['c'] ?? 0) ?: 0);

        $msgStmt = $pdo->prepare('SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN direction = \'inbound\' THEN 1 ELSE 0 END) AS inbound,
        SUM(CASE WHEN direction = \'outbound\' THEN 1 ELSE 0 END) AS outbound
      FROM messages
      WHERE DATE(created_at) = CURDATE()');
        $msgStmt->execute();
        $m = $msgStmt->fetch();
        $messagesToday = (int) (($m['total'] ?? 0) ?: 0);
        $inboundToday = (int) (($m['inbound'] ?? 0) ?: 0);
        $outboundToday = (int) (($m['outbound'] ?? 0) ?: 0);

        $callsToday = (int) (($pdo->query('SELECT COUNT(*) AS c FROM calls WHERE DATE(created_at) = CURDATE()')->fetch()['c'] ?? 0) ?: 0);
        $voicemailsToday = (int) (($pdo->query('SELECT COUNT(*) AS c FROM voicemails WHERE DATE(created_at) = CURDATE()')->fetch()['c'] ?? 0) ?: 0);

        json([
            'date' => $today,
            'contacts_total' => $contactsTotal,
            'conversations_total' => $conversationsTotal,
            'messages_today' => $messagesToday,
            'inbound_today' => $inboundToday,
            'outbound_today' => $outboundToday,
            'calls_today' => $callsToday,
            'voicemails_today' => $voicemailsToday,
        ]);
        return true;
    }

    if ($uri === '/api/admin/settings/opt-out' && $method === 'GET') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');
        json(['sms_opt_out_enabled' => appSettingGet($pdo, 'sms_opt_out_enabled', '1') === '1']);
        return true;
    }

    if ($uri === '/api/admin/settings/opt-out' && $method === 'POST') {
        Auth::requireLogin();
        $pdo = getPdo($rootDir);
        requirePermission($pdo, 'settings.manage');
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            json(['error' => 'Invalid JSON'], 400);
        }
        $enabled = !empty($payload['sms_opt_out_enabled']) ? '1' : '0';
        appSettingSet($pdo, 'sms_opt_out_enabled', $enabled);
        $persisted = appSettingGet($pdo, 'sms_opt_out_enabled', '1') === '1';
        json(['ok' => true, 'sms_opt_out_enabled' => $persisted]);
        return true;
    }

    return false;
}
