<?php
defined( 'ABSPATH' ) || exit;

class GS_Woo_Sync_Log {

    const TABLE = 'gs_woo_sync_log';

    public static function init(): void {}

    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            trigger_by  VARCHAR(20)     NOT NULL DEFAULT 'cron',
            created     INT             NOT NULL DEFAULT 0,
            updated     INT             NOT NULL DEFAULT 0,
            skipped     INT             NOT NULL DEFAULT 0,
            trashed     INT             NOT NULL DEFAULT 0,
            errors      INT             NOT NULL DEFAULT 0,
            duration    FLOAT           NOT NULL DEFAULT 0,
            notes       TEXT,
            PRIMARY KEY (id)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function insert( array $data ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . self::TABLE, $data );
    }

    public static function get_rows( int $limit = 50 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' ORDER BY id DESC LIMIT %d',
                $limit
            ),
            ARRAY_A
        );
    }

    public static function clear(): void {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . self::TABLE );
    }
}
