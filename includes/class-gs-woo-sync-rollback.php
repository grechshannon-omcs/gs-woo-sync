<?php
defined( 'ABSPATH' ) || exit;

class GS_Woo_Sync_Rollback {

    const TABLE = 'gs_woo_sync_snapshots';

    /* ── Core product fields captured in snapshot ──────────── */
    const CORE_FIELDS = [
        'post_title', 'post_content', 'post_excerpt', 'post_status',
    ];

    const META_FIELDS = [
        '_sku', '_regular_price', '_sale_price', '_price',
        '_stock', '_stock_status', '_manage_stock', '_stock_quantity',
        '_weight', '_length', '_width', '_height',
        '_thumbnail_id', '_product_image_gallery',
        '_gs_woo_sync_image_url',
    ];

    public static function init(): void {}

    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            log_id      BIGINT UNSIGNED NOT NULL,
            product_id  BIGINT UNSIGNED NOT NULL,
            snapshot    LONGTEXT        NOT NULL,
            snapped_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY log_id (log_id),
            KEY product_id (product_id)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /* ── Snapshot a list of product IDs before sync ────────── */

    public static function snapshot( int $log_id, array $product_ids ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        foreach ( $product_ids as $product_id ) {
            $post = get_post( $product_id );
            if ( ! $post ) continue;

            $data = [
                'post' => [],
                'meta' => [],
                'terms'=> [],
            ];

            // Core post fields
            foreach ( self::CORE_FIELDS as $field ) {
                $data['post'][ $field ] = $post->$field ?? '';
            }

            // All meta (not just the list — capture everything for full fidelity)
            $all_meta = get_post_meta( $product_id );
            foreach ( $all_meta as $key => $values ) {
                $data['meta'][ $key ] = $values[0] ?? '';
            }

            // Taxonomy terms
            foreach ( [ 'product_cat', 'product_tag', 'product_type' ] as $tax ) {
                $terms = wp_get_object_terms( $product_id, $tax, [ 'fields' => 'ids' ] );
                $data['terms'][ $tax ] = is_wp_error( $terms ) ? [] : $terms;
            }

            $wpdb->insert( $table, [
                'log_id'     => $log_id,
                'product_id' => $product_id,
                'snapshot'   => wp_json_encode( $data ),
                'snapped_at' => current_time( 'mysql' ),
            ] );
        }
    }

    /* ── Restore all products from a log_id snapshot ───────── */

    public static function restore( int $log_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE log_id = %d", $log_id ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return [ 'success' => false, 'message' => 'No snapshot found for this sync run.' ];
        }

        $restored = 0;
        $errors   = 0;

        foreach ( $rows as $row ) {
            $product_id = (int) $row['product_id'];
            $data       = json_decode( $row['snapshot'], true );

            if ( ! $data ) { $errors++; continue; }

            // Restore post fields
            $update = [ 'ID' => $product_id ];
            foreach ( $data['post'] as $field => $value ) {
                $update[ $field ] = $value;
            }
            $result = wp_update_post( $update, true );
            if ( is_wp_error( $result ) ) { $errors++; continue; }

            // Restore meta
            foreach ( $data['meta'] as $key => $value ) {
                update_post_meta( $product_id, $key, $value );
            }

            // Restore taxonomy terms
            foreach ( $data['terms'] as $tax => $term_ids ) {
                wp_set_object_terms( $product_id, $term_ids, $tax );
            }

            // If product was trashed by this sync run, restore it to publish
            if ( get_post_status( $product_id ) === 'trash' ) {
                wp_untrash_post( $product_id );
            }

            $restored++;
        }

        return [
            'success'  => $errors === 0,
            'restored' => $restored,
            'errors'   => $errors,
            'message'  => "Restored {$restored} products." . ( $errors ? " {$errors} errors." : '' ),
        ];
    }

    /* ── Check if a snapshot exists for a log_id ───────────── */

    public static function has_snapshot( int $log_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE log_id = %d", $log_id )
        );
        return $count > 0;
    }

    /* ── Delete old snapshots (keep last N) ─────────────────── */

    public static function prune( int $keep = 10 ): void {
        global $wpdb;
        $table     = $wpdb->prefix . self::TABLE;
        $log_table = $wpdb->prefix . GS_Woo_Sync_Log::TABLE;

        // MySQL doesn't allow LIMIT inside a NOT IN subquery, so fetch IDs first
        $keep_ids = $wpdb->get_col(
            $wpdb->prepare( "SELECT id FROM {$log_table} ORDER BY id DESC LIMIT %d", $keep )
        );

        if ( empty( $keep_ids ) ) return;

        $placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE log_id NOT IN ({$placeholders})",
                ...$keep_ids
            )
        );
    }

    /* ── Get snapshot count for a log_id ───────────────────── */

    public static function get_count( int $log_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE log_id = %d", $log_id )
        );
    }
}
