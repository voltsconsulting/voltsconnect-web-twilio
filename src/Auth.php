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
        try {
            session_regenerate_id(true);
        } catch (\Throwable $e) {
        }
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
        try {
            session_regenerate_id(true);
        } catch (\Throwable $e) {
        }
        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id']);
    }
}
