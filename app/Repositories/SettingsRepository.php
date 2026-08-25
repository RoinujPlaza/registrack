<?php

declare(strict_types=1);

namespace RegisTrack\Repositories;

use PDO;
use RegisTrack\Core\AppContext;

/**
 * Institution-configurable behaviour (system_settings table).
 * These are the values administrators can change at runtime without a deploy —
 * e.g. the FR4 email channel toggle.
 */
final class SettingsRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = AppContext::instance()->db();
    }

    public function get(string $key): ?string
    {
        $statement = $this->db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO system_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $statement->execute([$key, $value]);
    }

    /** FR4: "depending on the configuration set by the institution". */
    public function emailEnabled(): bool
    {
        return $this->get('notification_email_enabled') === '1';
    }
}
