<?php

declare(strict_types=1);

use App\Auth;

function handleRateLimitRoutes(string $uri, string $method, string $rootDir): bool
{
 $uid = Auth::userId();
 if ($uid === null) {
 json(['error' => 'Not authenticated'], 401);
 }

 $pdo = getPdo($rootDir);

 // Ensure table exists
 ensureRateLimitSchema($pdo);

 $payload = $method === 'POST' ? json_decode((string) file_get_contents('php://input'), true) : ($_GET ?? []);
 $endpoint = isset($payload['endpoint']) ? trim((string) $payload['endpoint']) : 'send_sms';
 $limit = isset($payload['limit']) ? max(1, (int) $payload['limit']) : 30;
 $windowSecs = isset($payload['window']) ? max(1, (int) $payload['window']) : 60;

 $windowStart = gmdate('Y-m-d H:i:s', time() - $windowSecs);

 // Cleanup expired windows
 $pdo->prepare('DELETE FROM rate_limits WHERE user_id = ? AND endpoint = ? AND window_start < ?')
 ->execute([$uid, $endpoint, $windowStart]);

 // Check current count
 $chk = $pdo->prepare('SELECT request_count, window_start FROM rate_limits WHERE user_id = ? AND endpoint = ?');
 $chk->execute([$uid, $endpoint]);
 $row = $chk->fetch(PDO::FETCH_ASSOC);

 if ($row) {
 $count = (int) ($row['request_count'] ?? 0);
 if ($count >= $limit) {
 $resetAt = strtotime($row['window_start']) + $windowSecs;
 $remaining = max(0, $resetAt - time());
 json([
 'allowed' => false,
 'remaining' => 0,
 'reset_in' => $remaining,
 'retry_after' => $remaining,
 'message' => "Rate limit exceeded. Try again in {$remaining} seconds."
 ], 429);
 }
 $remaining = $limit - $count - 1;
 } else {
 $remaining = $limit - 1;
 }

 // Increment or insert counter
 if ($row) {
 $pdo->prepare('UPDATE rate_limits SET request_count = request_count + 1 WHERE user_id = ? AND endpoint = ?')
 ->execute([$uid, $endpoint]);
 } else {
 $pdo->prepare('INSERT INTO rate_limits (user_id, endpoint, request_count, window_start) VALUES (?, ?, 1, NOW())')
 ->execute([$uid, $endpoint]);
 }

 json([
 'allowed' => true,
 'remaining' => max(0, $remaining),
 'limit' => $limit,
 'window' => $windowSecs,
 ]);

 return true;
}

function requireRateLimit(PDO $pdo, int $uid, string $endpoint = 'send_sms', int $limit = 30, int $windowSecs = 60): void
{
 $windowStart = gmdate('Y-m-d H:i:s', time() - $windowSecs);

 $pdo->prepare('DELETE FROM rate_limits WHERE user_id = ? AND endpoint = ? AND window_start < ?')
 ->execute([$uid, $endpoint, $windowStart]);

 $chk = $pdo->prepare('SELECT request_count, window_start FROM rate_limits WHERE user_id = ? AND endpoint = ?');
 $chk->execute([$uid, $endpoint]);
 $row = $chk->fetch(PDO::FETCH_ASSOC);

 if ($row) {
 $count = (int) ($row['request_count'] ?? 0);
 if ($count >= $limit) {
 $resetAt = strtotime($row['window_start']) + $windowSecs;
 $remaining = max(0, $resetAt - time());
 http_response_code(429);
 json([
 'error' => "Rate limit exceeded ({$limit} requests per {$windowSecs}s). Try again in {$remaining} seconds.",
 'retry_after' => $remaining,
 ], 429);
 }
 $pdo->prepare('UPDATE rate_limits SET request_count = request_count + 1 WHERE user_id = ? AND endpoint = ?')
 ->execute([$uid, $endpoint]);
 } else {
 $pdo->prepare('INSERT INTO rate_limits (user_id, endpoint, request_count, window_start) VALUES (?, ?, 1, NOW())')
 ->execute([$uid, $endpoint]);
 }
}

function ensureRateLimitSchema(PDO $pdo): void
{
 $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
 id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 endpoint VARCHAR(100) NOT NULL,
 request_count INT NOT NULL DEFAULT 1,
 window_start DATETIME NOT NULL,
 UNIQUE KEY (user_id, endpoint)
 )');
}
