<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Auth
{
    public static function userId(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        return $id;
    }

    public static function check(): bool
    {
        return self::userId() !== null;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: /login');
            exit;
        }
    }

    public static function attempt(PDO $pdo, string $email, string $password): bool
    {
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            return false;
        }
        $_SESSION['user_id'] = (int) $row['id'];
        return true;
    }

    public static function register(PDO $pdo, string $email, string $password): bool
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (:email, :hash)');
        try {
            $stmt->execute([':email' => $email, ':hash' => $hash]);
        } catch (\Throwable $e) {
            return false;
        }
        $_SESSION['user_id'] = (int) $pdo->lastInsertId();

        $phone = Config::get('TWILIO_PHONE_NUMBER');
        if (is_string($phone) && trim($phone) !== '') {
            $phone = trim($phone);
            $pdo->prepare('INSERT IGNORE INTO numbers (phone_number, friendly_name) VALUES (:pn, NULL)')
                ->execute([':pn' => $phone]);

            $numStmt = $pdo->prepare('SELECT id FROM numbers WHERE phone_number = :pn LIMIT 1');
            $numStmt->execute([':pn' => $phone]);
            $numberId = (int) (($numStmt->fetch()['id'] ?? 0) ?: 0);

            if ($numberId > 0) {
                $pdo->prepare('INSERT IGNORE INTO user_numbers (user_id, number_id, is_default) VALUES (:uid, :nid, 1)')
                    ->execute([':uid' => (int) $_SESSION['user_id'], ':nid' => $numberId]);
            }
        }
        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id']);
    }
}
