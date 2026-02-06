<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(string $rootDir): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = (string) (Config::get('DB_HOST', 'localhost') ?? 'localhost');
        $port = (string) (Config::get('DB_PORT', '3306') ?? '3306');
        $db = (string) (Config::get('DB_DATABASE', '') ?? '');
        $user = (string) (Config::get('DB_USERNAME', '') ?? '');
        $pass = (string) (Config::get('DB_PASSWORD', '') ?? '');

        if ($db === '' || $user === '') {
            throw new \RuntimeException('Database is not configured. Set DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD.');
        }

        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=utf8mb4';
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return self::$pdo;
    }

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT \'agent\',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS twilio_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            account_sid VARCHAR(64) NOT NULL,
            auth_token VARCHAR(128) NOT NULL,
            api_key VARCHAR(64) NULL,
            api_secret VARCHAR(128) NULL,
            twiml_app_sid VARCHAR(64) NULL,
            default_from_number VARCHAR(32) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_twilio_accounts_name (name),
            KEY idx_twilio_accounts_sid (account_sid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS user_twilio_accounts (
            user_id BIGINT UNSIGNED NOT NULL,
            twilio_account_id BIGINT UNSIGNED NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, twilio_account_id),
            KEY idx_uta_twilio_account_id (twilio_account_id),
            CONSTRAINT fk_uta_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_uta_twilio_account_id FOREIGN KEY (twilio_account_id) REFERENCES twilio_accounts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS contacts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NULL,
            phone_number VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_contacts_phone (phone_number),
            KEY idx_contacts_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS conversations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            assigned_user_id BIGINT UNSIGNED NULL,
            default_number_id BIGINT UNSIGNED NULL,
            last_message_preview VARCHAR(255) NULL,
            last_message_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_conversations_last_message_at (last_message_at),
            KEY idx_conversations_contact_id (contact_id),
            CONSTRAINT fk_conversations_contact_id FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
            CONSTRAINT fk_conversations_assigned_user_id FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_conversations_default_number_id FOREIGN KEY (default_number_id) REFERENCES numbers(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            direction VARCHAR(20) NOT NULL,
            from_number VARCHAR(32) NOT NULL,
            to_number VARCHAR(32) NOT NULL,
            body TEXT NOT NULL,
            twilio_sid VARCHAR(64) NULL,
            status VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_messages_twilio_sid (twilio_sid),
            KEY idx_messages_conversation_id (conversation_id),
            KEY idx_messages_created_at (created_at),
            CONSTRAINT fk_messages_conversation_id FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
            CONSTRAINT fk_messages_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS conversation_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notes_conversation_id (conversation_id),
            KEY idx_notes_created_at (created_at),
            CONSTRAINT fk_notes_conversation_id FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
            CONSTRAINT fk_notes_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS numbers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone_number VARCHAR(32) NOT NULL,
            friendly_name VARCHAR(255) NULL,
            twilio_account_id BIGINT UNSIGNED NULL,
            voice_forward_number VARCHAR(32) NULL,
            voice_ring_timeout INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_numbers_phone (phone_number),
            KEY idx_numbers_twilio_account_id (twilio_account_id),
            CONSTRAINT fk_numbers_twilio_account_id FOREIGN KEY (twilio_account_id) REFERENCES twilio_accounts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        try {
            $pdo->exec('ALTER TABLE numbers ADD COLUMN twilio_account_id BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE numbers ADD COLUMN voice_forward_number VARCHAR(32) NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE numbers ADD COLUMN voice_ring_timeout INT UNSIGNED NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE numbers ADD KEY idx_numbers_twilio_account_id (twilio_account_id)');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE numbers ADD CONSTRAINT fk_numbers_twilio_account_id FOREIGN KEY (twilio_account_id) REFERENCES twilio_accounts(id) ON DELETE SET NULL');
        } catch (\Throwable $e) {
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS user_numbers (
            user_id BIGINT UNSIGNED NOT NULL,
            number_id BIGINT UNSIGNED NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, number_id),
            KEY idx_user_numbers_number_id (number_id),
            CONSTRAINT fk_user_numbers_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_numbers_number_id FOREIGN KEY (number_id) REFERENCES numbers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS sms_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            direction VARCHAR(20) NOT NULL,
            from_number VARCHAR(32) NOT NULL,
            to_number VARCHAR(32) NOT NULL,
            body TEXT NOT NULL,
            twilio_sid VARCHAR(64) NULL,
            status VARCHAR(50) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sms_twilio_sid (twilio_sid),
            KEY idx_sms_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS calls (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            direction VARCHAR(20) NOT NULL,
            from_number VARCHAR(32) NOT NULL,
            to_number VARCHAR(32) NOT NULL,
            twilio_sid VARCHAR(64) NULL,
            user_id BIGINT UNSIGNED NULL,
            client_identity VARCHAR(255) NULL,
            status VARCHAR(50) NULL,
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            duration_seconds INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_calls_twilio_sid (twilio_sid),
            KEY idx_calls_created_at (created_at),
            KEY idx_calls_user_id_created_at (user_id, created_at),
            KEY idx_calls_client_identity (client_identity),
            CONSTRAINT fk_calls_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS app_settings (
            k VARCHAR(100) NOT NULL,
            v TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS voicemails (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            call_sid VARCHAR(64) NULL,
            from_number VARCHAR(32) NULL,
            to_number VARCHAR(32) NULL,
            recording_url TEXT NULL,
            recording_duration INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_voicemails_created_at (created_at),
            KEY idx_voicemails_call_sid (call_sid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        try {
            $pdo->exec('ALTER TABLE calls ADD COLUMN user_id BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE calls ADD COLUMN recording_url VARCHAR(255) NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE calls ADD COLUMN recording_sid VARCHAR(64) NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE calls ADD COLUMN recording_duration INT UNSIGNED NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE calls ADD COLUMN client_identity VARCHAR(255) NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE calls ADD COLUMN started_at DATETIME NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE calls ADD COLUMN ended_at DATETIME NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE calls ADD COLUMN duration_seconds INT UNSIGNED NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE calls ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE calls ADD KEY idx_calls_user_id_created_at (user_id, created_at)');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE calls ADD KEY idx_calls_client_identity (client_identity)');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE calls ADD CONSTRAINT fk_calls_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL');
        } catch (\Throwable $e) {
        }
    }
}
