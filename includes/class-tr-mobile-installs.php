<?php
if (!defined('ABSPATH')) exit;

/**
 * Per-install credentials for mobile clients.
 * Plaintext install_secret is shown once at registration; only the hash is stored.
 */
final class TR_Mobile_Installs {
    public const DB_VERSION = 2;
    public const OPTION_DB_VERSION = 'tr_mobile_installs_db_version';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'tr_mobile_installs';
    }

    public static function maybe_upgrade(): void {
        $current = (int) get_option(self::OPTION_DB_VERSION, 0);
        if ($current >= self::DB_VERSION) {
            return;
        }
        self::create_table();
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
    }

    public static function create_table(): void {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            install_id varchar(64) NOT NULL,
            secret_hash varchar(255) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            platform varchar(32) NOT NULL DEFAULT '',
            app_version varchar(32) NOT NULL DEFAULT '',
            device_label varchar(191) NOT NULL DEFAULT '',
            package_name varchar(191) NOT NULL DEFAULT '',
            attest_key_id varchar(255) NOT NULL DEFAULT '',
            attest_public_key text NULL,
            created_at datetime NOT NULL,
            last_seen_at datetime DEFAULT NULL,
            last_ip varchar(45) NOT NULL DEFAULT '',
            revoked_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY install_id (install_id),
            KEY status_last_seen (status, last_seen_at)
        ) {$charset};";
        dbDelta($sql);
    }

    /**
     * @return array{install_id:string,install_secret:string}|WP_Error
     */
    public static function register(array $meta) {
        global $wpdb;

        $platform = sanitize_key((string) ($meta['platform'] ?? ''));
        $app_version = sanitize_text_field((string) ($meta['app_version'] ?? ''));
        $device_label = sanitize_text_field((string) ($meta['device_label'] ?? ''));
        $package_name = sanitize_text_field((string) ($meta['package_name'] ?? ''));
        $attest_key_id = sanitize_text_field((string) ($meta['attest_key_id'] ?? ''));
        $attest_public_key = (string) ($meta['attest_public_key'] ?? '');
        if (strlen($app_version) > 32) {
            $app_version = substr($app_version, 0, 32);
        }
        if (strlen($device_label) > 191) {
            $device_label = substr($device_label, 0, 191);
        }
        if (strlen($package_name) > 191) {
            $package_name = substr($package_name, 0, 191);
        }
        if (strlen($attest_key_id) > 255) {
            $attest_key_id = substr($attest_key_id, 0, 255);
        }
        if ($platform !== '' && !in_array($platform, ['android', 'ios', 'other'], true)) {
            $platform = 'other';
        }

        $install_id = 'inst_' . wp_generate_password(24, false, false);
        // Alphanumeric only so JSON/body sanitizers do not alter the secret.
        $install_secret = wp_generate_password(48, false, false);
        $now = current_time('mysql', true);
        $ip = class_exists('TR_Mobile_Rate_Limit') ? TR_Mobile_Rate_Limit::client_ip() : '';

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'install_id' => $install_id,
                'secret_hash' => wp_hash_password($install_secret),
                'status' => self::STATUS_ACTIVE,
                'platform' => $platform,
                'app_version' => $app_version,
                'device_label' => $device_label,
                'package_name' => $package_name,
                'attest_key_id' => $attest_key_id,
                'attest_public_key' => $attest_public_key !== '' ? $attest_public_key : null,
                'created_at' => $now,
                'last_seen_at' => $now,
                'last_ip' => substr($ip, 0, 45),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (!$inserted) {
            return new WP_Error('tr_register_failed', 'Unable to register install.', ['status' => 500]);
        }

        return [
            'install_id' => $install_id,
            'install_secret' => $install_secret,
        ];
    }

    /**
     * @return object|null
     */
    public static function find(string $install_id) {
        global $wpdb;
        $install_id = sanitize_text_field($install_id);
        if ($install_id === '') {
            return null;
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() . ' WHERE install_id = %s LIMIT 1',
                $install_id
            )
        );
        return $row ?: null;
    }

    /**
     * @return true|WP_Error
     */
    public static function verify_credentials(string $install_id, string $install_secret) {
        $row = self::find($install_id);
        if (!$row) {
            return new WP_Error('tr_unauthorized', 'Invalid install credentials.', ['status' => 401]);
        }
        if (($row->status ?? '') !== self::STATUS_ACTIVE) {
            return new WP_Error('tr_install_revoked', 'This install has been revoked.', ['status' => 401]);
        }
        if (!wp_check_password($install_secret, (string) $row->secret_hash)) {
            return new WP_Error('tr_unauthorized', 'Invalid install credentials.', ['status' => 401]);
        }
        self::touch($install_id);
        return true;
    }

    /**
     * @return true|WP_Error
     */
    public static function assert_active(string $install_id) {
        $row = self::find($install_id);
        if (!$row) {
            return new WP_Error('tr_unauthorized', 'Unknown install.', ['status' => 401]);
        }
        if (($row->status ?? '') !== self::STATUS_ACTIVE) {
            return new WP_Error('tr_install_revoked', 'This install has been revoked.', ['status' => 401]);
        }
        self::touch($install_id);
        return true;
    }

    public static function touch(string $install_id): void {
        global $wpdb;
        $ip = class_exists('TR_Mobile_Rate_Limit') ? TR_Mobile_Rate_Limit::client_ip() : '';
        $wpdb->update(
            self::table_name(),
            [
                'last_seen_at' => current_time('mysql', true),
                'last_ip' => substr($ip, 0, 45),
            ],
            ['install_id' => $install_id],
            ['%s', '%s'],
            ['%s']
        );
    }

    public static function revoke(string $install_id): bool {
        global $wpdb;
        $updated = $wpdb->update(
            self::table_name(),
            [
                'status' => self::STATUS_REVOKED,
                'revoked_at' => current_time('mysql', true),
            ],
            ['install_id' => $install_id],
            ['%s', '%s'],
            ['%s']
        );
        return $updated !== false;
    }

    public static function reactivate(string $install_id): bool {
        global $wpdb;
        $table = self::table_name();
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = %s, revoked_at = NULL WHERE install_id = %s",
                self::STATUS_ACTIVE,
                $install_id
            )
        );
        return $updated !== false;
    }

    /**
     * @return array{items:array<int,object>,total:int}
     */
    public static function list(int $page = 1, int $per_page = 20, string $status = ''): array {
        global $wpdb;
        $page = max(1, $page);
        $per_page = min(max($per_page, 1), 100);
        $offset = ($page - 1) * $per_page;
        $table = self::table_name();

        $where = '1=1';
        $params = [];
        if ($status === self::STATUS_ACTIVE || $status === self::STATUS_REVOKED) {
            $where .= ' AND status = %s';
            $params[] = $status;
        }

        $total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($total_sql, ...$params))
            : $wpdb->get_var($total_sql));

        $list_sql = "SELECT install_id, status, platform, app_version, device_label, created_at, last_seen_at, last_ip, revoked_at
            FROM {$table}
            WHERE {$where}
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d";
        $list_params = array_merge($params, [$per_page, $offset]);
        $items = $wpdb->get_results($wpdb->prepare($list_sql, ...$list_params)) ?: [];

        return ['items' => $items, 'total' => $total];
    }

    public static function drop_table(): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query('DROP TABLE IF EXISTS ' . self::table_name());
        delete_option(self::OPTION_DB_VERSION);
    }
}
