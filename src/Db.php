<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    private static function recordMigration(PDO $pdo, string $key, string $description): void
    {
        try {
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration_key, description) VALUES (:k, :d)
                ON DUPLICATE KEY UPDATE migration_key = migration_key');
            $stmt->execute([':k' => $key, ':d' => $description]);
        } catch (\Throwable $e) {
        }
    }

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
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_key VARCHAR(120) NOT NULL,
            description VARCHAR(255) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_schema_migrations_key (migration_key),
            KEY idx_schema_migrations_applied_at (applied_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS teams (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_teams_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        try {
            $pdo->exec("INSERT IGNORE INTO teams (id, name) VALUES (1, 'Default')");
        } catch (\Throwable $e) {
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS team_members (
            team_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (team_id, user_id),
            KEY idx_team_members_user_id (user_id),
            CONSTRAINT fk_team_members_team_id FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
            CONSTRAINT fk_team_members_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS roles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_roles_team_name (team_id, name),
            KEY idx_roles_team_id (team_id),
            CONSTRAINT fk_roles_team_id FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS permissions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            perm_key VARCHAR(100) NOT NULL,
            label VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_permissions_key (perm_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        try {
            $stmt = $pdo->prepare('INSERT IGNORE INTO permissions (perm_key, label) VALUES (:k, :l)');
            $items = [
                ['k' => 'analytics.view', 'l' => 'Analytics: view'],
                ['k' => 'inbox.view', 'l' => 'Inbox: view conversations/messages'],
                ['k' => 'inbox.send', 'l' => 'Inbox: send messages'],
                ['k' => 'contacts.view', 'l' => 'Contacts: view'],
                ['k' => 'contacts.edit', 'l' => 'Contacts: edit'],
                ['k' => 'dialpad.use', 'l' => 'Dialpad: make calls'],
                ['k' => 'calls.view', 'l' => 'Calls: view call logs'],
                ['k' => 'calls.manage', 'l' => 'Calls: manage (delete/export)'],
                ['k' => 'voicemails.view', 'l' => 'Voicemails: view'],
                ['k' => 'voicemails.manage', 'l' => 'Voicemails: manage (delete)'],
                ['k' => 'broadcast.use', 'l' => 'Broadcast: send campaigns'],
                ['k' => 'templates.manage', 'l' => 'Templates: manage'],
                ['k' => 'numbers.view', 'l' => 'Numbers: view'],
                ['k' => 'numbers.manage', 'l' => 'Numbers: manage assignments'],
                ['k' => 'settings.view', 'l' => 'Settings: view'],
                ['k' => 'settings.manage', 'l' => 'Settings: manage'],
                ['k' => 'users.manage', 'l' => 'Users & Roles: manage'],
                ['k' => 'notifications.manage', 'l' => 'Notifications: manage'],
            ];
            foreach ($items as $it) {
                $stmt->execute([':k' => $it['k'], ':l' => $it['l']]);
            }
        } catch (\Throwable $e) {
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS role_permissions (
            role_id BIGINT UNSIGNED NOT NULL,
            permission_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (role_id, permission_id),
            KEY idx_role_permissions_permission_id (permission_id),
            CONSTRAINT fk_role_permissions_role_id FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT fk_role_permissions_permission_id FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS user_role_assignments (
            user_id BIGINT UNSIGNED NOT NULL,
            role_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, role_id),
            KEY idx_user_role_assignments_role_id (role_id),
            CONSTRAINT fk_user_role_assignments_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_role_assignments_role_id FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        self::recordMigration($pdo, '1.2_rbac_scaffold', 'Update 1.2: Add teams/roles/permissions tables');

        try {
            $pdo->prepare('INSERT IGNORE INTO roles (team_id, name) VALUES (1, :n)')
                ->execute([':n' => 'Admin']);
            $pdo->prepare('INSERT IGNORE INTO roles (team_id, name) VALUES (1, :n)')
                ->execute([':n' => 'Agent']);

            $adminRoleId = (int) (($pdo->query("SELECT id FROM roles WHERE team_id = 1 AND name = 'Admin' LIMIT 1")->fetch()['id'] ?? 0) ?: 0);
            $agentRoleId = (int) (($pdo->query("SELECT id FROM roles WHERE team_id = 1 AND name = 'Agent' LIMIT 1")->fetch()['id'] ?? 0) ?: 0);

            if ($adminRoleId > 0) {
                $pdo->exec('INSERT IGNORE INTO role_permissions (role_id, permission_id)
                    SELECT ' . $adminRoleId . ', p.id FROM permissions p');
            }
            if ($agentRoleId > 0) {
                $pdo->exec('INSERT IGNORE INTO role_permissions (role_id, permission_id)
                    SELECT ' . $agentRoleId . ', p.id FROM permissions p
                    WHERE p.perm_key IN (
                        \'inbox.view\', \'inbox.send\',
                        \'contacts.view\', \'contacts.edit\',
                        \'dialpad.use\', \'calls.view\',
                        \'voicemails.view\', \'broadcast.use\',
                        \'numbers.view\',
                        \'templates.manage\',
                        \'settings.view\'
                    )');
            }

            if ($adminRoleId > 0) {
                $pdo->exec('INSERT IGNORE INTO user_role_assignments (user_id, role_id)
                    SELECT u.id, ' . $adminRoleId . ' FROM users u WHERE u.role = \'admin\'');
            }
            if ($agentRoleId > 0) {
                $pdo->exec('INSERT IGNORE INTO user_role_assignments (user_id, role_id)
                    SELECT u.id, ' . $agentRoleId . ' FROM users u WHERE u.role <> \'admin\'');
            }
        } catch (\Throwable $e) {
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS conversation_reads (
            user_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, conversation_id),
            KEY idx_conversation_reads_conversation_id (conversation_id),
            CONSTRAINT fk_conversation_reads_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_conversation_reads_conversation_id FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS conversation_read_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_cre_conversation_id (conversation_id),
            KEY idx_cre_created_at (created_at),
            CONSTRAINT fk_cre_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_cre_conversation_id FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        self::recordMigration($pdo, '1.3_read_tracking', 'Update 1.3: Add conversation read tracking');

        $pdo->exec('CREATE TABLE IF NOT EXISTS notification_role_rules (
            role_id BIGINT UNSIGNED NOT NULL,
            event_key VARCHAR(120) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            reminder_minutes INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (role_id, event_key),
            CONSTRAINT fk_nrr_role_id FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS notification_sends (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            role_id BIGINT UNSIGNED NOT NULL,
            event_key VARCHAR(120) NOT NULL,
            ref_key VARCHAR(200) NOT NULL,
            conversation_id BIGINT UNSIGNED NULL,
            message_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_notification_sends (role_id, event_key, ref_key),
            KEY idx_notification_sends_created_at (created_at),
            CONSTRAINT fk_ns_role_id FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT fk_ns_conversation_id FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        self::recordMigration($pdo, '1.4_notifications', 'Update 1.4: Add notification rules and sends');

        try {
            $pdo->exec('ALTER TABLE notification_sends ADD COLUMN ref_key VARCHAR(200) NOT NULL DEFAULT \'\'');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE notification_sends DROP INDEX uq_notification_sends');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE notification_sends ADD UNIQUE KEY uq_notification_sends (role_id, event_key, ref_key)');
        } catch (\Throwable $e) {
        }

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
            first_name VARCHAR(255) NULL,
            last_name VARCHAR(255) NULL,
            name VARCHAR(255) NULL,
            phone_number VARCHAR(32) NOT NULL,
            email VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_contacts_phone (phone_number),
            KEY idx_contacts_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS contact_fields (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            field_key VARCHAR(64) NOT NULL,
            label VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_contact_fields_key (field_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS contact_field_values (
            contact_id BIGINT UNSIGNED NOT NULL,
            field_id BIGINT UNSIGNED NOT NULL,
            value TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (contact_id, field_id),
            KEY idx_cfv_field_id (field_id),
            CONSTRAINT fk_cfv_contact_id FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
            CONSTRAINT fk_cfv_field_id FOREIGN KEY (field_id) REFERENCES contact_fields(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS contact_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_contact_notes_contact_id (contact_id),
            KEY idx_contact_notes_created_at (created_at),
            CONSTRAINT fk_contact_notes_contact_id FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
            CONSTRAINT fk_contact_notes_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS contact_tags (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_contact_tags_user_name (user_id, name),
            KEY idx_contact_tags_user_id (user_id),
            CONSTRAINT fk_contact_tags_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS contact_tag_members (
            tag_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (tag_id, contact_id),
            KEY idx_ctm_contact_id (contact_id),
            CONSTRAINT fk_ctm_tag_id FOREIGN KEY (tag_id) REFERENCES contact_tags(id) ON DELETE CASCADE,
            CONSTRAINT fk_ctm_contact_id FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS contact_groups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_contact_groups_user_name (user_id, name),
            KEY idx_contact_groups_user_id (user_id),
            CONSTRAINT fk_contact_groups_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS contact_group_members (
            group_id BIGINT UNSIGNED NOT NULL,
            contact_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (group_id, contact_id),
            KEY idx_cgm_contact_id (contact_id),
            CONSTRAINT fk_cgm_group_id FOREIGN KEY (group_id) REFERENCES contact_groups(id) ON DELETE CASCADE,
            CONSTRAINT fk_cgm_contact_id FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
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

        $pdo->exec('CREATE TABLE IF NOT EXISTS message_media (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            url TEXT NOT NULL,
            content_type VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_message_media_message_id (message_id),
            CONSTRAINT fk_message_media_message_id FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
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

        try {
            $pdo->exec('ALTER TABLE numbers ADD COLUMN twilio_account_id BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
        }

        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
        } catch (\Throwable $e) {
        }

        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN team_id BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN role_id BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE users ADD KEY idx_users_team_id (team_id)');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE users ADD KEY idx_users_role_id (role_id)');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE users ADD CONSTRAINT fk_users_team_id FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE users ADD CONSTRAINT fk_users_role_id FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL');
        } catch (\Throwable $e) {
        }

        try {
            $pdo->exec('ALTER TABLE team_members ADD UNIQUE KEY uq_team_members_user_id (user_id)');
        } catch (\Throwable $e) {
        }

        try {
            $pdo->exec('ALTER TABLE contacts ADD COLUMN email VARCHAR(255) NULL');
        } catch (\Throwable $e) {
        }

        try {
            $pdo->exec('ALTER TABLE contacts ADD COLUMN first_name VARCHAR(255) NULL');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE contacts ADD COLUMN last_name VARCHAR(255) NULL');
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

        $pdo->exec('CREATE TABLE IF NOT EXISTS sms_opt_outs (
            phone_number VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (phone_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $pdo->exec('CREATE TABLE IF NOT EXISTS message_templates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_message_templates_user_name (user_id, name),
            KEY idx_message_templates_user_id (user_id),
            CONSTRAINT fk_message_templates_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
